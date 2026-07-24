<?php

namespace App\Services;

use Throwable;
use Ip2Region;
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

        // 连续低流量确认后，将邮箱和本次访问 IP 固化进离线黑名单。
        // 后续请求会优先命中名单，不再依赖每日流量统计结果。
        if ($match['reason'] === '低流量') {
            $this->addLowTrafficUserToBlacklist($user, $request->ip());
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

        $chatId = $this->getTelegramAlertChatId();
        if ($chatId === null) {
            return;
        }

        // 客户端会自动刷新订阅；同一用户在间隔期内只推送一次，避免频道刷屏。
        $cacheKey = 'low_traffic_subscription_alert_' . $user->id;
        $alertInterval = $this->getAlertInterval();
        if ($alertInterval === null || !Cache::add($cacheKey, true, $alertInterval)) {
            return;
        }

        SendTelegramJob::dispatch($chatId, $this->buildTelegramMessage($user, $request, $source, $match));
    }

    /**
     * 判断是否应对该用户返回假域名。
     * 先检查中国大陆 IP 和 IP 白名单，随后检查邮箱白名单、黑名单和低流量规则。
     *
     * @return array{reason: string, value: string}|null
     */
    private function getMaskReason(User $user, Request $request): ?array
    {
        if ($this->getFakeDomain() === '') {
            return null;
        }

        // IP 白名单是最前置的闸门，不允许邮箱白名单绕过。
        if (!$this->isMainlandIpv4($request->ip())) {
            return ['reason' => '非中国大陆 IP', 'value' => $this->getIpCountry($request->ip())];
        }

        if (!$this->isAllowlistedIp($request->ip())) {
            return ['reason' => 'IP 未在白名单', 'value' => $request->ip()];
        }

        // 白名单用户永不替换域名，即使同时命中其他异常规则。
        if ($this->matchWhitelistEmail($user->email)) {
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
     * 必须每一天都有日流量统计记录；中间缺少任何一天时不判定为低流量。
     */
    private function hasLowTraffic(User $user): bool
    {
        $days = $this->getLowTrafficDays();
        $limit = $this->getLowTrafficLimit();
        if ($days === null || $limit === null) {
            return false;
        }

        $startAt = now()->startOfDay()->subDays($days - 1)->timestamp;
        $dailyTraffic = StatUser::query()
            ->selectRaw('record_at, SUM(u + d) AS traffic')
            ->where('user_id', $user->id)
            ->where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->groupBy('record_at')
            ->pluck('traffic', 'record_at')
            ->toArray();

        for ($day = 0; $day < $days; $day++) {
            $recordAt = $startAt + ($day * 86400);

            // 用户没有在这一天产生统计记录时，不把它当成 0 流量，避免误判正常用户。
            if (!array_key_exists($recordAt, $dailyTraffic)) {
                return false;
            }

            if ((int) $dailyTraffic[$recordAt] >= $limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * 在中国大陆 IP 检查通过后，按完整 IPv4 地址精确匹配允许集合。
     */
    private function isAllowlistedIp(string $ip): bool
    {
        $offlineList = $this->readOfflineList('SUBSCRIPTION_ALLOWLIST_IPS_FILE');
        if (empty($offlineList)) {
            return true;
        }

        if (in_array($ip, $offlineList, true)) {
            return true;
        }

        return false;
    }

    /**
     * ip2region 的首个字段为国家；排除港澳台后才视为中国大陆 IP。
     */
    private function isMainlandIpv4(string $ip): bool
    {
        $region = $this->getIpRegion($ip);
        return $region !== null
            && str_starts_with($region, '中国|')
            && !str_contains($region, '香港')
            && !str_contains($region, '澳门')
            && !str_contains($region, '台湾');
    }

    /**
     * 返回 IP 归属地中的国家字段，供非大陆 IP 的告警和日志展示。
     */
    private function getIpCountry(string $ip): string
    {
        $region = $this->getIpRegion($ip);
        return $region === null ? '未知' : (explode('|', $region)[0] ?: '未知');
    }

    /**
     * ip2region 归属地格式为：国家|区域|省份|城市|运营商。
     */
    private function getIpRegion(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        try {
            $result = (new Ip2Region())->memorySearch($ip);
            $region = (string) ($result['region'] ?? '');
            return $region === '' ? null : $region;
        } catch (Throwable $exception) {
            Log::warning('订阅 IP 归属地查询失败', ['ip' => $ip, 'exception' => $exception->getMessage()]);
            return null;
        }
    }

    /**
     * 在离线 IP/CIDR 名单中查找请求 IP，支持 IPv4、IPv6、单个 IP 和 CIDR。
     */
    private function matchSuspiciousIpRange(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        foreach ($this->readOfflineList('SUBSCRIPTION_BLACKLIST_IP_RANGES_FILE') as $range) {
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
        foreach ($this->readOfflineList('SUBSCRIPTION_BLACKLIST_EMAILS_FILE') as $suspiciousEmail) {
            if ($email === strtolower($suspiciousEmail)) {
                return $suspiciousEmail;
            }
        }

        return null;
    }

    /**
     * 在离线白名单中按完整邮箱精确匹配，邮箱大小写不敏感。
     */
    private function matchWhitelistEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        foreach ($this->readOfflineList('SUBSCRIPTION_WHITELIST_EMAILS_FILE') as $whitelistEmail) {
            if ($email === strtolower($whitelistEmail)) {
                return $whitelistEmail;
            }
        }

        return null;
    }

    /**
     * 将连续低流量用户的邮箱和当前访问 IP 追加到对应黑名单文件。
     * 已存在的内容不会重复写入；文件锁避免多个订阅请求同时写入时发生冲突。
     */
    private function addLowTrafficUserToBlacklist(User $user, string $ip): void
    {
        $this->appendOfflineListValue('SUBSCRIPTION_BLACKLIST_EMAILS_FILE', strtolower(trim($user->email)));

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->appendOfflineListValue('SUBSCRIPTION_BLACKLIST_IP_RANGES_FILE', $ip);
        }
    }

    /**
     * 读取离线名单文件：忽略空行和以 # 开头的注释行。
     * 文件路径由 .env 配置，相对路径相对于项目根目录。
     *
     * @return array<int, string>
     */
    public function readOfflineList(string $environmentKey): array
    {
        $path = $this->getOfflineListPath($environmentKey);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    }

    /**
     * 将一条内容追加到离线名单文件，并避免重复写入。
     */
    private function appendOfflineListValue(string $environmentKey, string $value): void
    {
        if ($value === '') {
            return;
        }

        $path = $this->getOfflineListPath($environmentKey);
        if ($path === null) {
            Log::warning('订阅黑名单文件路径未配置', ['environment_key' => $environmentKey]);
            return;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            Log::warning('无法创建订阅黑名单目录', ['directory' => $directory]);
            return;
        }

        $file = fopen($path, 'c+');
        if ($file === false) {
            Log::warning('无法写入订阅黑名单文件', ['path' => $path]);
            return;
        }

        try {
            if (!flock($file, LOCK_EX)) {
                return;
            }

            $content = stream_get_contents($file) ?: '';
            $values = array_map('strtolower', array_map('trim', preg_split('/\R/', $content)));
            if (in_array(strtolower($value), $values, true)) {
                return;
            }

            fseek($file, 0, SEEK_END);
            fwrite($file, (strlen($content) > 0 && !str_ends_with($content, "\n") ? "\n" : '') . $value . "\n");
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    /**
     * 获取 .env 配置的离线名单绝对路径；未配置时返回 null。
     */
    private function getOfflineListPath(string $environmentKey): ?string
    {
        $path = trim((string) env($environmentKey));
        return $path === '' ? null : base_path($path);
    }

    /**
     * 从 .env 的 FAKE_DOMAIN 读取固定假域名。
     * 留空表示暂不启用该功能，所有用户都会收到真实节点域名。
     */
    private function getFakeDomain(): string
    {
        return trim((string) env('FAKE_DOMAIN'));
    }

    /**
     * 从 .env 的 LOW_TRAFFIC_DAYS 读取统计天数；缺失或无效时不启用低流量规则。
     */
    private function getLowTrafficDays(): ?int
    {
        $days = filter_var(env('LOW_TRAFFIC_DAYS'), FILTER_VALIDATE_INT);
        return $days !== false && $days > 0 ? $days : null;
    }

    /**
     * 从 .env 的 LOW_TRAFFIC_LIMIT 读取每日流量阈值，单位为字节。
     * 缺失或无效时不启用低流量规则。
     */
    private function getLowTrafficLimit(): ?int
    {
        $limit = filter_var(env('LOW_TRAFFIC_LIMIT'), FILTER_VALIDATE_INT);
        return $limit !== false && $limit > 0 ? $limit : null;
    }

    /**
     * 从 .env 的 LOW_TRAFFIC_ALERT_INTERVAL 读取同一用户的告警间隔。
     * 缺失或无效时不发送 Telegram 告警。
     */
    private function getAlertInterval(): ?int
    {
        $interval = filter_var(env('LOW_TRAFFIC_ALERT_INTERVAL'), FILTER_VALIDATE_INT);
        return $interval !== false && $interval >= 60 ? $interval : null;
    }

    /**
     * 从 .env 读取 Telegram 频道或群组 ID；缺失或无效时不发送告警。
     */
    private function getTelegramAlertChatId(): ?int
    {
        $chatId = filter_var(env('TELEGRAM_ALERT_CHAT_ID'), FILTER_VALIDATE_INT);
        return $chatId !== false && $chatId !== 0 ? $chatId : null;
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
