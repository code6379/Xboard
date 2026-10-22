<?php

namespace Plugin\FreeServer;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 缓存远程免费节点，并按用户分组将缓存节点合并到订阅中。
 */
class Plugin extends AbstractPlugin
{
    /**
     * 远程订阅地址返回 404 后的冷却标记缓存键前缀。
     */
    private const URL_COOLDOWN_CACHE_PREFIX = 'free_server:subscription_url_cooldown:';

    /**
     * 转换后免费节点缓存键前缀；版本号用于区分缓存结构。
     */
    private const NODE_CACHE_KEY_PREFIX = 'free_server:subscription_nodes:v2:';

    /**
     * 刷新状态缓存键前缀。
     */
    private const REFRESH_STATE_CACHE_KEY_PREFIX = 'free_server:refresh_state:v2:';

    /**
     * 远程 VLESS 节点转换为 XBoard 节点时使用的字段模板。
     */
    private const SERVER_TEMPLATE = [
        'id' => null,
        'type' => 'vless',
        'code' => null,
        'parent_id' => null,
        'machine_id' => null,
        'group_ids' => [],
        'route_ids' => [],
        'name' => '',
        'rate' => 1.0,
        'rate_time_enable' => false,
        'rate_time_ranges' => [],
        'tags' => [],
        'host' => '',
        'port' => 0,
        'server_port' => 0,
        'ports' => null,
        'show' => true,
        'enabled' => true,
        'sort' => 0,
        'created_at' => null,
        'updated_at' => null,
        'custom_outbounds' => null,
        'custom_routes' => null,
        'cert_config' => null,
        'transfer_enable' => null,
        'u' => 0,
        'd' => 0,
        'password' => '',
        'last_check_at' => null,
        'last_push_at' => null,
        'online' => 0,
        'is_online' => 0,
        'available_status' => 0,
        'cache_key' => null,
        'server_key' => null,
        'protocol_settings' => [
            'network' => 'tcp',
            'network_settings' => [
                'path' => '/',
                'headers' => ['Host' => ''],
            ],
            'tls' => 0,
            'tls_settings' => [
                'server_name' => null,
                'allow_insecure' => false,
                'ech' => [
                    'enabled' => false,
                    'config' => null,
                    'query_server_name' => null,
                    'key' => null,
                    'key_path' => null,
                    'config_path' => null,
                ],
            ],
            'flow' => null,
            'reality_settings' => [
                'server_name' => null,
                'server_port' => null,
                'public_key' => null,
                'private_key' => null,
                'short_id' => null,
                'allow_insecure' => false,
            ],
            'utls' => [
                'enabled' => false,
                'fingerprint' => 'chrome',
            ],
            'encryption' => [
                'enabled' => false,
                'encryption' => null,
                'decryption' => null,
            ],
            'multiplex' => [
                'enabled' => false,
                'protocol' => 'yamux',
                'max_connections' => null,
                'padding' => false,
                'brutal' => [
                    'enabled' => false,
                    'up_mbps' => null,
                    'down_mbps' => null,
                ],
            ],
        ],
    ];

    /**
     * 根据插件开关和订阅地址配置注册订阅过滤器。
     *
     * @return void
     */
    public function boot(): void
    {
        if (!$this->getConfig('enabled', false) || $this->getConfiguredSubscriptionUrls() === []) {
            return;
        }

        $this->filter('client.subscribe.servers', [$this, 'addFreeServers'], 30);
    }

    /**
     * 注册免费节点缓存的周期刷新任务。
     *
     * 任务每分钟检查一次，仅在缓存缺失或到达刷新时间时访问订阅源。
     *
     * @param Schedule $schedule Laravel 调度器
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        if (!$this->getConfig('enabled', false) || $this->getConfiguredSubscriptionUrls() === []) {
            return;
        }

        $schedule->call(fn() => $this->refreshSubscriptionCacheIfDue())
            ->everyMinute()
            ->name('free_server_subscription_cache_refresh')
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    /**
     * 将缓存的免费节点合并到允许分组用户的订阅中。
     *
     * @param array<int, array<string, mixed>> $servers 原订阅节点
     * @param User $user 当前用户
     * @param Request $request 当前订阅请求
     * @return array<int, array<string, mixed>> 合并后的订阅节点
     */
    public function addFreeServers(array $servers, User $user, Request $request): array
    {
        if (!$this->isAllowedGroup($user)) {
            return $servers;
        }

        return collect($servers)
            ->merge($this->getCachedFreeServers())
            ->values()
            ->toArray();
    }

    /**
     * 判断用户是否属于免费节点允许的分组。
     *
     * @param User $user 当前用户
     * @return bool 用户分组是否在允许列表中
     */
    private function isAllowedGroup(User $user): bool
    {
        $configuredGroups = $this->getConfig('allowed_group_ids', []);
        if (is_string($configuredGroups)) {
            $configuredGroups = explode(',', $configuredGroups);
        }

        if (!is_array($configuredGroups) || $user->group_id === null) {
            return false;
        }

        $allowedGroupIds = array_values(array_filter(
            array_map('intval', $configuredGroups),
            fn(int $groupId): bool => $groupId > 0
        ));

        return in_array((int) $user->group_id, $allowedGroupIds, true);
    }

    /**
     * 解析配置中的订阅地址，并保留管理员设置的优先顺序。
     *
     * @return array<int, string> 订阅地址列表
     */
    private function getConfiguredSubscriptionUrls(): array
    {
        $configuredUrls = (string) $this->getConfig('subscription_url', '');

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n|,]+/', $configuredUrls) ?: []
        ), fn(string $url): bool => $url !== ''));
    }

    /**
     * 返回当前未处于 404 冷却期的地址，并保持配置顺序。
     *
     * @return array<int, string> 可用的订阅地址列表
     */
    private function getSubscriptionUrls(): array
    {
        $urls = $this->getConfiguredSubscriptionUrls();

        return array_values(array_filter(
            $urls,
            fn(string $url): bool => !Cache::has($this->getUrlCooldownCacheKey($url))
        ));
    }

    /**
     * 将返回 404 的地址冷却到次日早上 8 点，使用应用时区计算时间。
     *
     * @param string $url 返回 404 的订阅地址
     * @return void
     */
    private function cooldownSubscriptionUrl(string $url): void
    {
        $restoreAt = now()->addDay()->startOfDay()->addHours(8);
        Cache::put($this->getUrlCooldownCacheKey($url), true, $restoreAt);
    }

    /**
     * 根据订阅地址生成 404 冷却标记对应的缓存键。
     *
     * @param string $url 订阅地址
     * @return string 冷却标记缓存键
     */
    private function getUrlCooldownCacheKey(string $url): string
    {
        return self::URL_COOLDOWN_CACHE_PREFIX . $url;
    }

    /**
     * 缓存异常订阅地址；404 冷却到次日早上 8 点，其余异常按节点缓存时长冷却。
     *
     * @param string $url 订阅地址
     * @param string $reason 失败原因
     * @param array<string, mixed> $context 请求诊断信息
     * @return void
     */
    private function markSubscriptionUrlUnavailable(string $url, string $reason, array $context): void
    {
        if ($reason === 'http_404') {
            $this->cooldownSubscriptionUrl($url);
            $context['cache_policy'] = '次日 08:00 恢复';
        } else {
            Cache::put($this->getUrlCooldownCacheKey($url), true, now()->addMinutes($this->getCacheTtlMinutes()));
            $context['cache_policy'] = $this->getCacheTtlMinutes() . ' 分钟后重试';
        }

        Log::warning('免费节点订阅源获取失败，地址已缓存', array_merge($context, [
            'reason' => $reason,
        ]));
    }

    /**
     * 根据订阅地址生成缓存哈希，地址配置变更后使用新的缓存键。
     *
     * @return string 订阅地址配置的哈希值
     */
    private function getSubscriptionCacheHash(): string
    {
        return hash('sha256', implode("\n", $this->getConfiguredSubscriptionUrls()));
    }

    /**
     * 获取免费节点数组对应的缓存键。
     *
     * @return string 免费节点缓存键
     */
    private function getNodesCacheKey(): string
    {
        return self::NODE_CACHE_KEY_PREFIX . $this->getSubscriptionCacheHash();
    }

    /**
     * 获取刷新状态对应的缓存键。
     *
     * @return string 刷新状态缓存键
     */
    private function getRefreshStateCacheKey(): string
    {
        return self::REFRESH_STATE_CACHE_KEY_PREFIX
            . $this->getSubscriptionCacheHash()
            . ':'
            . $this->getCacheTtlMinutes();
    }

    /**
     * 读取缓存中尚未过期的免费节点。
     *
     * @return array<int, array<string, mixed>> 可用的免费节点列表
     */
    private function getCachedFreeServers(): array
    {
        $servers = Cache::get($this->getNodesCacheKey(), []);
        if (!is_array($servers)) {
            return [];
        }

        return array_values(array_filter($servers, fn($server): bool => is_array($server)));
    }

    /**
     * 每分钟由调度器调用，仅在缓存缺失或刷新时间到达时请求上游。
     *
     * @return void
     */
    private function refreshSubscriptionCacheIfDue(): void
    {
        if ($this->getConfiguredSubscriptionUrls() === []) {
            return;
        }

        $nodesCacheKey = $this->getNodesCacheKey();
        $stateKey = $this->getRefreshStateCacheKey();
        $state = [];
        try {
            if (Cache::has($nodesCacheKey)) {
                return;
            }

            $state = Cache::get($stateKey, []);
            $state = is_array($state) ? $state : [];
            if ((int) ($state['next_attempt_at'] ?? 0) > now()->timestamp) {
                return;
            }

            $urls = $this->getSubscriptionUrls();
            if ($urls === []) {
                $state['last_attempt_at'] = now()->timestamp;
                $state['last_result'] = 'all_urls_cached_or_cooled';
                $state['next_attempt_at'] = now()->addMinute()->timestamp;
                Cache::put($stateKey, $state, now()->addDays(8));
                return;
            }

            Log::info('开始刷新免费节点订阅源', [
                'url_count' => count($urls),
                'cache_ttl_minutes' => $this->getCacheTtlMinutes(),
            ]);

            $servers = $this->fetchFreeServers($urls);
            if ($servers === null || $servers === []) {
                $this->scheduleRefreshRetry($stateKey, $state);
                return;
            }

            Cache::put($nodesCacheKey, array_values($servers), now()->addMinutes($this->getCacheTtlMinutes()));
            Cache::forget($stateKey);

            Log::info('免费节点缓存刷新成功', [
                'node_count' => count($servers),
                'cache_ttl_minutes' => $this->getCacheTtlMinutes(),
            ]);
        } catch (Throwable $e) {
            Log::warning('免费节点缓存刷新失败', [
                'error_type' => get_class($e),
            ]);
            $this->scheduleRefreshRetry($stateKey, $state);
        }
    }

    /**
     * 记录失败次数和下次重试时间，不写入失败或空节点结果。
     *
     * @param string $stateKey 刷新状态缓存键
     * @param array<string, int|null> $state 当前刷新状态
     * @return void
     */
    private function scheduleRefreshRetry(string $stateKey, array $state): void
    {
        $failureCount = min(8, (int) ($state['failure_count'] ?? 0) + 1);
        $retryMinutes = $this->getSubscriptionUrls() === []
            ? 1
            : $this->getCacheTtlMinutes();
        $attemptedAt = now();

        $state = array_merge($state, [
            'failure_count' => $failureCount,
            'last_attempt_at' => $attemptedAt->timestamp,
            'last_result' => 'no_usable_source',
            'next_attempt_at' => $attemptedAt->copy()->addMinutes($retryMinutes)->timestamp,
        ]);

        Cache::put($stateKey, $state, $attemptedAt->copy()->addDays(8));

        Log::warning('本轮未获得可用的免费节点，已缓存失败状态', [
            'failure_count' => $failureCount,
            'retry_after_minutes' => $retryMinutes,
            'cooldown_url_count' => count($this->getConfiguredSubscriptionUrls()) - count($this->getSubscriptionUrls()),
        ]);
    }

    /**
     * 读取节点缓存时长；无效配置使用 30 分钟默认值。
     *
     * @return int 节点缓存时长，单位为分钟
     */
    private function getCacheTtlMinutes(): int
    {
        $minutes = filter_var($this->getConfig('cache_ttl_minutes', 30), FILTER_VALIDATE_INT);

        return $minutes !== false && $minutes >= 1 && $minutes <= 1440 ? $minutes : 30;
    }

    /**
     * 按配置顺序请求订阅源，缓存异常地址并尝试下一个地址。
     *
     * @param array<int, string> $urls 当前未处于冷却期的订阅地址
     * @return array<int, array<string, mixed>>|null 可用节点列表；全部失败时返回 null
     */
    private function fetchFreeServers(array $urls): ?array
    {
        $failedUrlCount = 0;

        foreach ($urls as $index => $url) {
            $sourceContext = [
                'url_index' => $index + 1,
                'host' => (string) (parse_url($url, PHP_URL_HOST) ?: 'unknown'),
            ];

            try {
                $response = Http::timeout(30)
                    ->withHeader('user-agent', 'sing-box')
                    ->get($url);
                $body = $response->body();
                $responseContext = array_merge($sourceContext, [
                    'http_status' => $response->status(),
                    'content_type' => (string) $response->header('Content-Type'),
                    'body_bytes' => strlen($body),
                ]);

                if ($response->status() === 404) {
                    $this->markSubscriptionUrlUnavailable($url, 'http_404', $responseContext);
                    $failedUrlCount++;
                    continue;
                }

                if (!$response->successful()) {
                    $this->markSubscriptionUrlUnavailable($url, 'http_status', $responseContext);
                    $failedUrlCount++;
                    continue;
                }

                $subscriptionData = json_decode($body, true);
                if (!is_array($subscriptionData)) {
                    $this->markSubscriptionUrlUnavailable($url, 'invalid_json', array_merge($responseContext, [
                        'json_error' => json_last_error_msg(),
                    ]));
                    $failedUrlCount++;
                    continue;
                }

                $outbounds = $subscriptionData['outbounds'] ?? null;
                if (!is_array($outbounds)) {
                    $this->markSubscriptionUrlUnavailable($url, 'missing_outbounds', array_merge($responseContext, [
                        'top_level_keys' => array_keys($subscriptionData),
                    ]));
                    $failedUrlCount++;
                    continue;
                }

                $converted = [];
                $rejectedFieldCount = 0;
                $rejectedPromoCount = 0;

                foreach ($outbounds as $outbound) {
                    if (!is_array($outbound) || count($outbound) < 7) {
                        $rejectedFieldCount++;
                        continue;
                    }

                    $tag = (string) ($outbound['tag'] ?? '');
                    if (str_contains($tag, 't.me') || str_contains($tag, '加入我的频道')) {
                        $rejectedPromoCount++;
                        continue;
                    }

                    $converted[] = $this->convertOutboundToServer($outbound, count($converted) + 1);
                }

                if ($converted === []) {
                    $this->markSubscriptionUrlUnavailable($url, 'no_usable_outbounds', array_merge($responseContext, [
                        'outbound_count' => count($outbounds),
                        'rejected_field_count' => $rejectedFieldCount,
                        'rejected_promo_count' => $rejectedPromoCount,
                    ]));
                    $failedUrlCount++;
                    continue;
                }

                Log::info('免费节点订阅源解析成功', array_merge($responseContext, [
                    'outbound_count' => count($outbounds),
                    'accepted_node_count' => count($converted),
                    'rejected_field_count' => $rejectedFieldCount,
                    'rejected_promo_count' => $rejectedPromoCount,
                ]));

                return $converted;
            } catch (Throwable $e) {
                $this->markSubscriptionUrlUnavailable($url, 'request_exception', array_merge($sourceContext, [
                    'exception_type' => get_class($e),
                ]));
                $failedUrlCount++;
            }
        }

        Log::warning('本轮所有免费节点订阅源均未获得可用节点', [
            'attempted_url_count' => count($urls),
            'failed_url_count' => $failedUrlCount,
        ]);

        return null;
    }

    /**
     * 将 sing-box outbound 转换为 XBoard VLESS 节点。
     *
     * @param array<string, mixed> $outbound sing-box 出站配置
     * @param int $sort 节点排序值
     * @return array<string, mixed> XBoard 节点数据
     */
    private function convertOutboundToServer(array $outbound, int $sort = 0): array
    {
        $server = self::SERVER_TEMPLATE;
        $server['id'] = null;
        $server['name'] = (string) ($outbound['tag'] ?? '');
        $server['sort'] = $sort;
        $server['created_at'] = null;
        $server['updated_at'] = null;
        $server['u'] = 0;
        $server['d'] = 0;
        $server['last_check_at'] = null;
        $server['last_push_at'] = null;
        $server['cache_key'] = null;
        $server['online'] = 0;
        $server['is_online'] = 0;

        $server['host'] = $outbound['server'] ?? '';
        $server['port'] = $outbound['server_port'] ?? 0;
        $server['server_port'] = $outbound['server_port'] ?? 0;
        $server['password'] = $outbound['uuid'] ?? '';

        $server['protocol_settings']['network'] = $outbound['transport']['type'] ?? ($outbound['network'] ?? 'tcp');
        $server['protocol_settings']['network_settings']['path'] = $outbound['transport']['path'] ?? '/';
        $server['protocol_settings']['network_settings']['headers']['Host'] = $outbound['transport']['headers']['Host'] ?? '';
        $server['protocol_settings']['tls'] = !empty($outbound['tls']['enabled']) ? 1 : 0;
        $server['protocol_settings']['tls_settings']['server_name'] = $outbound['tls']['server_name'] ?? null;
        $server['protocol_settings']['tls_settings']['allow_insecure'] = $outbound['tls']['insecure'] ?? false;
        $server['protocol_settings']['utls']['enabled'] = $outbound['tls']['utls']['enabled'] ?? false;
        $server['protocol_settings']['utls']['fingerprint'] = $outbound['tls']['utls']['fingerprint'] ?? '';
        $server['cert_config'] = null;

        return $server;
    }
}
