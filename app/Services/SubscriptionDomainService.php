<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 订阅域名伪装服务。
 *
 * 用于识别连续低流量用户，并仅在生成订阅内容时替换节点域名。
 * 不会修改数据库内的真实节点配置，也不会影响后台和节点通讯。
 */
class SubscriptionDomainService
{
    /**
     * 根据用户近几天的流量决定是否替换订阅节点域名。
     *
     * @param array<int, array<string, mixed>> $servers
     * @return array<int, array<string, mixed>>
     */
    public function maskServersForUser(User $user, Request $request, array $servers): array
    {
        if ($this->getMaskReason($user, $request) === null) {
            return $servers;
        }

        return $this->replaceServerDomains($servers);
    }

    /**
     * 订阅内容成功生成后，记录命中用户并异步通知 Telegram 频道。
     */
    public function notifySuccessfulMaskedSubscription(User $user, Request $request, string $source): void
    {
        $match = $this->getMaskReason($user, $request);
        if ($match === null) {
            return;
        }

        $context = [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'source' => $source,
            'fake_domain' => $this->getFakeDomain(),
            'low_traffic_days' => $this->getLowTrafficDays(),
            'low_traffic_limit' => $this->getLowTrafficLimit(),
            'reason' => $match['reason'],
            'matched_value' => $match['value'],
        ];

        // 每一次成功返回都会写日志，方便在 storage/logs 中完整追溯。
        Log::info('低流量用户订阅已返回假域名', $context);

        $chatId = (int) env('TELEGRAM_ALERT_CHAT_ID', 0);
        if ($chatId === 0) {
            return;
        }

        // 客户端会自动刷新订阅；同一用户在间隔期内只推送一次，避免频道刷屏。
        $cacheKey = 'low_traffic_subscription_alert_' . $user->id;
        if (!Cache::add($cacheKey, true, $this->getAlertInterval())) {
            return;
        }

        SendTelegramJob::dispatch($chatId, $this->buildTelegramMessage($user, $request, $source, $match));
    }

    /**
     * 判断是否应对该用户返回假域名。
     * 邮箱名单优先，其次 IP 段，最后才检查低流量规则。
     *
     * @return array{reason: string, value: string}|null
     */
    private function getMaskReason(User $user, Request $request): ?array
    {
        if ($this->getFakeDomain() === '') {
            return null;
        }

        if ($email = $this->matchSuspiciousEmail($user->email)) {
            return ['reason' => '邮箱名单', 'value' => $email];
        }

        if ($ipRange = $this->matchSuspiciousIpRange($request->ip())) {
            return ['reason' => 'IP 段', 'value' => $ipRange];
        }

        if ($this->hasLowTraffic($user)) {
            return ['reason' => '低流量', 'value' => '最近 ' . $this->getLowTrafficDays() . ' 天'];
        }

        return null;
    }

    /**
     * 判断用户最近指定天数内是否每天都没有达到指定流量阈值。
     * 某一天没有流量记录时，按当天使用 0 字节处理。
     */
    private function hasLowTraffic(User $user): bool
    {
        $days = $this->getLowTrafficDays();
        $limit = $this->getLowTrafficLimit();
        $startAt = now()->startOfDay()->subDays($days - 1)->timestamp;
        $dailyTraffic = StatUser::query()
            ->selectRaw('record_at, SUM(u + d) AS traffic')
            ->where('user_id', $user->id)
            ->where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->groupBy('record_at')
            ->pluck('traffic', 'record_at');

        for ($day = 0; $day < $days; $day++) {
            $recordAt = $startAt + ($day * 86400);
            if ((int) ($dailyTraffic[$recordAt] ?? 0) >= $limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * 在离线 IP/CIDR 名单中查找请求 IP，支持 IPv4、IPv6、单个 IP 和 CIDR。
     */
    private function matchSuspiciousIpRange(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        foreach ($this->readOfflineList('SUSPICIOUS_IP_RANGES_FILE', 'storage/app/suspicious-ip-ranges.txt') as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                return $range;
            }
        }

        return null;
    }

    /**
     * 在离线邮箱名单中按完整邮箱精确匹配，邮箱大小写不敏感。
     */
    private function matchSuspiciousEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        foreach ($this->readOfflineList('SUSPICIOUS_EMAILS_FILE', 'storage/app/suspicious-emails.txt') as $suspiciousEmail) {
            if ($email === strtolower($suspiciousEmail)) {
                return $suspiciousEmail;
            }
        }

        return null;
    }

    /**
     * 读取离线名单文件：忽略空行和以 # 开头的注释行。
     * 文件路径由 .env 配置，相对路径相对于项目根目录。
     *
     * @return array<int, string>
     */
    private function readOfflineList(string $environmentKey, string $defaultPath): array
    {
        $path = base_path(trim((string) env($environmentKey, $defaultPath)) ?: $defaultPath);
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    }

    /**
     * 从 .env 的 FAKE_DOMAIN 读取固定假域名。
     * 留空表示暂不启用该功能，所有用户都会收到真实节点域名。
     */
    private function getFakeDomain(): string
    {
        return trim((string) env('FAKE_DOMAIN', ''));
    }

    /**
     * 从 .env 的 LOW_TRAFFIC_DAYS 读取统计天数，默认最近 7 个自然日。
     */
    private function getLowTrafficDays(): int
    {
        return max(1, (int) env('LOW_TRAFFIC_DAYS', 7));
    }

    /**
     * 从 .env 的 LOW_TRAFFIC_LIMIT 读取每日流量阈值，单位为字节。
     * 默认值 5242880 即 5 MiB（5 * 1024 * 1024）。
     */
    private function getLowTrafficLimit(): int
    {
        return max(0, (int) env('LOW_TRAFFIC_LIMIT', 5242880));
    }

    /**
     * 从 .env 的 LOW_TRAFFIC_ALERT_INTERVAL 读取同一用户的告警间隔，默认 24 小时。
     */
    private function getAlertInterval(): int
    {
        return max(60, (int) env('LOW_TRAFFIC_ALERT_INTERVAL', 86400));
    }

    /**
     * 生成频道告警内容；不包含订阅 token、订阅链接和真实节点域名。
     */
    private function buildTelegramMessage(User $user, Request $request, string $source, array $match): string
    {
        return implode("\n", [
            '异常用户',
            '',
            '用户 ID: ' . $user->id,
            '邮箱: ' . $user->email,
            '请求 IP: ' . $request->ip(),
            '命中原因: ' . $match['reason'],
            '命中内容: ' . $match['value'],
            '订阅入口: ' . $source,
            '客户端: ' . ($request->userAgent() ?: '未知'),
            '时间: ' . now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 将订阅输出中可能暴露真实入口域名的字段全部替换为固定假域名。
     * 这里只处理内存中的节点数组，数据库里的真实节点不会被修改。
     *
     * @param array<int, array<string, mixed>> $servers
     * @return array<int, array<string, mixed>>
     */
    private function replaceServerDomains(array $servers): array
    {
        $fakeDomain = $this->getFakeDomain();

        return array_map(function (array $server) use ($fakeDomain): array {
            $server['host'] = $fakeDomain;
            $settings = $server['protocol_settings'] ?? [];

            // 这些字段分别对应 TLS SNI、Reality SNI、WS/H2/HTTP 传输层 Host 等地址信息。
            foreach ([
                'tls_settings.server_name',
                'reality_settings.server_name',
                'tls.server_name',
                'network_settings.headers.Host',
                'network_settings.host',
                'network_settings.header.request.headers.Host',
                'obfs_settings.host',
            ] as $path) {
                $value = data_get($settings, $path);
                if ($value !== null && $value !== '') {
                    data_set(
                        $settings,
                        $path,
                        is_array($value)
                            ? array_fill(0, count($value), $fakeDomain)
                            : $fakeDomain
                    );
                }
            }

            // Shadowsocks 插件的 host 参数也可能包含真实域名，需要一并替换。
            if (!empty($settings['plugin_opts'])) {
                $settings['plugin_opts'] = collect(explode(';', $settings['plugin_opts']))
                    ->map(function (string $option) use ($fakeDomain): string {
                        if (!str_contains($option, '=')) {
                            return $option;
                        }

                        [$key] = explode('=', $option, 2);
                        return str_contains(strtolower(trim($key)), 'host')
                            ? trim($key) . '=' . $fakeDomain
                            : $option;
                    })
                    ->implode(';');
            }

            $server['protocol_settings'] = $settings;
            return $server;
        }, $servers);
    }
}
