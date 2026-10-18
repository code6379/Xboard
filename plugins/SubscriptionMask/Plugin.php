<?php

namespace Plugin\SubscriptionMask;

use App\Models\StatUser;
use App\Models\SubscriptionMaskLog;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Utils\IP2Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 订阅风控。
 *
 * 基于 IP 归属、代理画像、邮箱与流量名单判定风险用户，
 * 命中后只返回独立的风控提示节点；后续 FreeServer 可按自身分组配置追加免费节点。
 * 不会修改数据库内的真实节点配置，也不会影响后台和节点通讯。
 */
class Plugin extends AbstractPlugin
{
    /** 风控命中后显示的默认提示内容。 */
    private const DEFAULT_NOTICE_MESSAGE = '⚠️ 请关闭代理后更新订阅';

    public function boot(): void
    {
        // 总开关：关闭时不注册任何钩子，插件完全静默。
        if (!$this->getConfig('enabled', false)) {
            return;
        }

        $this->filter('client.subscribe.servers', [$this, 'filterRiskySubscriptionServers'], 10);
        $this->listen('client.subscribe.success', [$this, 'recordSuccessfulSubscription'], 10);
    }

    /**
     * 判断订阅风险，命中时清除真实节点并返回风控提示节点。
     *
     * @param array<int, array<string, mixed>> $servers
     * @param User $user
     * @param Request $request
     * @return array<int, array<string, mixed>>
     */
    public function filterRiskySubscriptionServers(array $servers, User $user, Request $request): array
    {
        return $this->filterServersForUser($user, $request, $servers);
    }

    /** 订阅成功后更新数据库中的完成状态，不向外部发送通知。 */
    public function recordSuccessfulSubscription(array $payload): void
    {
        $request = $payload['request'] ?? null;
        if (!$request instanceof Request) {
            return;
        }

        $log = $request->attributes->get('subscription_mask_log');
        if (!$log instanceof SubscriptionMaskLog) {
            return;
        }

        $ipInfo = $request->attributes->get('subscription_mask_ip_info');
        $countryCode = strtoupper((string) ($ipInfo['country_code'] ?? ''));
        if ($countryCode !== '' && $countryCode !== 'CN' && !$this->isAllowlistedIp($request->ip())) {
            // 国外 IP 已按风控策略处理，不写入泄露分析记录。
            return;
        }

        $log->forceFill(['completed' => true])->save();
    }

    /**
     * 根据用户信息判断订阅风险，并准备成功请求的访问记录。
     *
     * @param array<int, array<string, mixed>> $servers
     * @return array<int, array<string, mixed>>
     */
    public function filterServersForUser(User $user, Request $request, array $servers): array
    {
        $log = SubscriptionMaskLog::forMaskingRequest($user, $request);

        $ipInfo = null;
        try {
            $ipInfo = $this->getIp2Location()->lookupCached($request->ip());
            $log->fillIpInfo($ipInfo);
            $request->attributes->set('subscription_mask_ip_info', $ipInfo);
        } catch (\Throwable $e) {
            // 情报服务失败时不阻断订阅，成功生成后仍会记录基础访问信息。
            $request->attributes->set('subscription_mask_ip_lookup_failed', true);
            Log::warning('订阅 IP 情报查询失败', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);
        }

        $match = $this->getMaskReason($user, $request, $ipInfo);
        // 保留原有风控标记字段，供分析页继续统计风险命中。
        $log->markCompleted($match);
        $request->attributes->set('subscription_mask_log', $log);

        return $match === null ? $servers : [$this->buildNoticeServer()];
    }

    /**
     * 判断是否应对该用户返回提示节点，黑名单优先于白名单。
     *
     * @return array{reason: string, value: string}|null
     */
    private function getMaskReason(User $user, Request $request, ?array $ipInfo = null): ?array
    {
        $ip     = $request->ip();

        // 分析页明确拉黑的邮箱或 IP 优先于白名单。
        if ($email = $this->matchSuspiciousEmail($user->email)) {
            return ['reason' => '邮箱名单', 'value' => $email];
        }

        if ($ipRange = $this->matchSuspiciousIpRange($ip)) {
            return ['reason' => 'IP段', 'value' => $ipRange];
        }

        // 只有 IP 白名单可以跳过国家归属判断；邮箱白名单不放行国外 IP。
        if ($this->isAllowlistedIp($ip)) {
            return null;
        }

        if ($ipInfo === null) {
            if ($request->attributes->get('subscription_mask_ip_lookup_failed', false)) {
                return ['reason' => 'IP归属未知', 'value' => $ip];
            }

            try {
                $ipInfo = $this->getIp2Location()->lookupCached($ip);
            } catch (\Throwable $e) {
                Log::warning('订阅 IP 风险判断跳过', [
                    'user_id' => $user->id,
                    'ip' => $ip,
                    'error' => $e->getMessage(),
                ]);
                return ['reason' => 'IP归属未知', 'value' => $ip];
            }
        }

        // 非大陆ip无法正常访问订阅
        $countryCode = strtoupper((string) ($ipInfo['country_code'] ?? ''));
        if ($countryCode === '') {
            return ['reason' => 'IP归属未知', 'value' => $ip];
        }
        if ($countryCode !== 'CN') {
            return [
                'reason' => '非大陆IP',
                'value' => sprintf('%s|%s|%s', $ipInfo['country'] ?? '', $ipInfo['region'] ?? '', $ipInfo['city'] ?? ''),
            ];
        }

        // 邮箱白名单仅跳过后续的低流量等自动风险规则。
        if ($this->matchWhitelistEmail($user->email)) {
            return null;
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
     * 按配置的 IP/CIDR 名单匹配允许集合，用于放行非大陆 IP。
     */
    private function isAllowlistedIp(string $ip): bool
    {
        $offlineList = $this->getConfiguredList('allowlist_ips');
        if (empty($offlineList)) {
            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($offlineList as $entry) {
            if (IpUtils::checkIp($ip, $entry)) {
                return true;
            }
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

    /** 构造独立的 Shadowsocks 提示节点，不复制任何真实节点信息。 */
    private function buildNoticeServer(): array
    {
        $message = trim((string) $this->getConfig('notice_message', self::DEFAULT_NOTICE_MESSAGE));
        $message = preg_replace('/\R+/u', ' ', $message);

        if ($message === '') {
            $message = self::DEFAULT_NOTICE_MESSAGE;
        }

        return [
            'id' => null,
            'type' => 'shadowsocks',
            'code' => null,
            'parent_id' => null,
            'group_ids' => [],
            'route_ids' => [],
            'name' => $message,
            'rate' => 1.0,
            'tags' => [],
            'host' => 'notice.invalid',
            'port' => 1,
            'server_port' => 1,
            'password' => 'subscription-risk-notice',
            'protocol_settings' => [
                'cipher' => 'aes-128-gcm',
                'obfs' => null,
                'obfs_settings' => null,
                'plugin' => null,
                'plugin_opts' => null,
            ],
            'show' => true,
            'enabled' => true,
            'sort' => 0,
            'created_at' => null,
            'updated_at' => null,
            'custom_outbounds' => null,
            'custom_routes' => null,
            'cert_config' => null,
        ];
    }
}
