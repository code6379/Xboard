<?php

namespace Plugin\SubscriptionDomainMask;

use App\Jobs\SendTelegramJob;
use App\Models\StatUser;
use App\Models\SubscriptionMaskLog;
use App\Models\User;
use App\Models\Plugin as PluginModel;
use App\Services\Plugin\AbstractPlugin;
use App\Utils\IP2Location;
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
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('client.subscribe.servers', [$this, 'maskSubscribeServers'], 10);
        $this->listen('client.subscribe.success', [$this, 'notifySuccessfulSubscription'], 10);
    }

    /**
     * 替换订阅内容中的节点域名
     *
     * @param array   $servers
     * @param User    $user
     * @param Request $request
     *
     * @return array[]
     */
    public function maskSubscribeServers(array $servers, User $user, Request $request): array
    {
        return $this->maskServersForUser($user, $request, $servers);
    }

    /**
     * 订阅成功通知
     * @param array $payload
     *
     * @return void
     */
    public function notifySuccessfulSubscription(array $payload): void
    {
        $user = $payload['user'] ?? null;
        $request = $payload['request'] ?? null;
        $source = $payload['source'] ?? '普通订阅';

        if (!$user instanceof User || !$request instanceof Request) {
            return;
        }

        $this->notifySuccessfulMaskedSubscription($user, $request, $source);
    }

    /**
     * 根据用户近几天的流量决定是否替换订阅节点域名，并记录每次调用。
     *
     * @param array<int, array<string, mixed>> $servers
     * @return array<int, array<string, mixed>>
     */
    public function maskServersForUser(User $user, Request $request, array $servers): array
    {
        $log = SubscriptionMaskLog::forMaskingRequest($user, $request);

        try {
            $ipInfo = $this->getIp2Location()->lookupCached($request->ip());
            $log->fillIpInfo($ipInfo);
            $match = $this->getMaskReason($user, $request, $ipInfo);
            if ($match === null) {
                return $servers;
            }

            $log->markCompleted($match,$this->getFakeDomain());

            return $this->replaceServerDomains($servers);
        } finally {
            $log->save();
        }
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
            'user_id'           => $user->id,
            'email'             => $user->email,
            'ip'                => $request->ip(),
            'user_agent'        => $request->userAgent(),
            'source'            => $source,
            'fake_domain'       => $this->getFakeDomain(),
            'low_traffic_days'  => $this->getLowTrafficDays(),
            'low_traffic_limit' => $this->getLowTrafficLimit(),
            'reason'            => $match['reason'],
            'matched_value'     => $match['value'],
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
     * 中国大陆 IP 或命中 IP 白名单时，才继续邮箱白名单、黑名单和低流量规则。
     *
     * @return array{reason: string, value: string}|null
     */
    private function getMaskReason(User $user, Request $request, ?array $ipInfo = null): ?array
    {
        if ($this->getFakeDomain() === '') {
            return null;
        }

        $ip     = $request->ip();

        // 白名单用户永不替换域名或者ip在允许名单内时
        if ($this->matchWhitelistEmail($user->email) || $this->isAllowlistedIp($ip)) {
            return null;
        }

        $ipInfo = $this->getIp2Location()->lookupCached($ip);

        // 非大陆ip无法正常访问订阅
        if ($ipInfo['country_code'] !== 'CN') {
            return [
                'reason' => '非大陆IP',
                'value' => sprintf('%s|%s|%s', $ipInfo['country'], $ipInfo['region'], $ipInfo['city']),
            ];
        }

        if ($email = $this->matchSuspiciousEmail($user->email)) {
            return ['reason' => '邮箱名单', 'value' => $email];
        }

        if ($ipRange = $this->matchSuspiciousIpRange($request->ip())) {
            return ['reason' => 'IP段', 'value' => $ipRange];
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
     * 按完整 IPv4 地址精确匹配允许集合，用于放行非大陆 IP。
     */
    private function isAllowlistedIp(string $ip): bool
    {
        $offlineList = $this->getConfiguredList('allowlist_ips');
        if (empty($offlineList)) {
            return true;
        }

        if (in_array($ip, $offlineList, true)) {
            return true;
        }

        return false;
    }

    /**
     * 在配置的 IP/CIDR 名单中查找请求 IP，支持 IPv4、IPv6、单个 IP 和 CIDR。
     */
    private function matchSuspiciousIpRange(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        foreach ($this->getConfiguredList('blacklist_ip_ranges') as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                return $range;
            }
        }

        return null;
    }

    /**
     * 在配置的邮箱名单中按完整邮箱精确匹配，邮箱大小写不敏感。
     */
    private function matchSuspiciousEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        foreach ($this->getConfiguredList('blacklist_emails') as $suspiciousEmail) {
            if ($email === strtolower($suspiciousEmail)) {
                return $suspiciousEmail;
            }
        }

        return null;
    }

    /**
     * 在配置的邮箱白名单中按完整邮箱精确匹配，邮箱大小写不敏感。
     */
    private function matchWhitelistEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        foreach ($this->getConfiguredList('whitelist_emails') as $whitelistEmail) {
            if ($email === strtolower($whitelistEmail)) {
                return $whitelistEmail;
            }
        }

        return null;
    }

    /**
     * 将连续低流量用户的邮箱和当前访问 IP 追加到插件配置中的黑名单。
     * 已存在的内容不会重复写入。
     */
    private function addLowTrafficUserToBlacklist(User $user, string $ip): void
    {
        $this->appendConfiguredListValue('blacklist_emails', strtolower(trim($user->email)));

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->appendConfiguredListValue('blacklist_ip_ranges', $ip);
        }
    }

    /**
     * 读取插件配置中的多行名单：忽略空行和以 # 开头的注释行。
     *
     * @return array<int, string>
     */
    private function getConfiguredList(string $configKey): array
    {
        $lines = preg_split('/\R/', (string) $this->getConfig($configKey, '')) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));
    }

    /**
     * 向插件配置中的多行名单追加一条内容，并避免重复写入。
     */
    private function appendConfiguredListValue(string $configKey, string $value): void
    {
        if ($value === '') {
            return;
        }

        $values = $this->getConfiguredList($configKey);
        if (in_array(strtolower($value), array_map('strtolower', $values), true)) {
            return;
        }

        $values[] = $value;
        $this->updatePluginConfigValue($configKey, implode("\n", $values));
    }

    private function updatePluginConfigValue(string $key, mixed $value): void
    {
        $plugin = PluginModel::query()->where('code', $this->getPluginCode())->first();
        if (!$plugin) {
            Log::warning('无法更新订阅域名伪装插件配置', ['config_key' => $key]);
            return;
        }

        $config = $plugin->config ? json_decode($plugin->config, true) : [];
        if (!is_array($config)) {
            $config = [];
        }

        $config[$key] = $value;
        $plugin->update(['config' => json_encode($config)]);
        $this->setConfig(array_merge($this->getConfig(), [$key => $value]));
    }

    /**
     * 从插件配置读取固定假域名。
     * 留空表示暂不启用该功能，所有用户都会收到真实节点域名。
     */
    private function getFakeDomain(): string
    {
        return trim((string) $this->getConfig('fake_domain', ''));
    }

    /**
     * 获取用于 IP 归属查询的服务实例。
     */
    private function getIp2Location(): IP2Location
    {
        $rawKeys = trim((string) $this->getConfig('ip2location_api_keys', ''));
        $keys = array_values(array_filter(array_map('trim', explode(',', $rawKeys))));

        return new IP2Location($keys);
    }

    /**
     * 从插件配置读取统计天数；缺失或无效时不启用低流量规则。
     */
    private function getLowTrafficDays(): ?int
    {
        $days = filter_var($this->getConfig('low_traffic_days'), FILTER_VALIDATE_INT);
        return $days !== false && $days > 0 ? $days : null;
    }

    /**
     * 从插件配置读取每日流量阈值，单位为字节。
     * 缺失或无效时不启用低流量规则。
     */
    private function getLowTrafficLimit(): ?int
    {
        $limit = filter_var($this->getConfig('low_traffic_limit'), FILTER_VALIDATE_INT);
        return $limit !== false && $limit > 0 ? $limit : null;
    }

    /**
     * 从插件配置读取同一用户的告警间隔。
     * 缺失或无效时不发送 Telegram 告警。
     */
    private function getAlertInterval(): ?int
    {
        $interval = filter_var($this->getConfig('low_traffic_alert_interval'), FILTER_VALIDATE_INT);
        return $interval !== false && $interval >= 60 ? $interval : null;
    }

    /**
     * 从插件配置读取 Telegram 频道或群组 ID；缺失或无效时不发送告警。
     */
    private function getTelegramAlertChatId(): ?int
    {
        $chatId = filter_var($this->getConfig('telegram_alert_chat_id'), FILTER_VALIDATE_INT);
        return $chatId !== false && $chatId !== 0 ? $chatId : null;
    }

    /**
     * 生成频道告警内容；不包含订阅 token、订阅链接和真实节点域名。
     */
    private function buildTelegramMessage(User $user, Request $request, string $source, array $match): string
    {
        return implode("\n", [
            '用户 ID: ' . $user->id,
            '邮箱: ' . $user->email,
            '请求 IP: ' . $request->ip(),
            '请求域名: ' . $request->getHost(),
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
