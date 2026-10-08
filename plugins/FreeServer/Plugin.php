<?php

namespace Plugin\FreeServer;

use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class Plugin extends AbstractPlugin
{
    /** 按真实账号订阅节点的完整字段定义模板，运行时不读取原节点。 */
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
     * 将 CF 高速节点合并到用户订阅节点中。
     */
    public function boot(): void
    {
        if (!$this->getConfig('enabled', false) || $this->getSubscriptionUrl() === '') {
            return;
        }

        $this->filter('client.subscribe.servers', [$this, 'addFreeServers'], 20);
    }

    /**
     * 向用户订阅节点中添加免费节点。
     *
     * @param array<int, array<string, mixed>> $servers
     * @param User                             $user
     * @param Request                          $request
     *
     * @return array<int, array<string, mixed>>
     * @throws ConnectionException
     */
    public function addFreeServers(array $servers, User $user, Request $request): array
    {
        if (!$this->isAllowedGroup($user)) {
            return $servers;
        }

        return $this->freeServers($servers);
    }

    /**
     * 判断用户是否在允许的用户组中。
     * @param User $user
     *
     * @return bool
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

    private function getSubscriptionUrl(): string
    {
        return trim((string) $this->getConfig('subscription_url', ''));
    }

    /**
     * 获取并合并远程免费节点。
     *
     * @param array<int, array<string, mixed>> $servers
     * @return array<int, array<string, mixed>>
     * @throws ConnectionException
     */
    private function freeServers(array $servers): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeader('user-agent', 'sing-box')
                ->get($this->getSubscriptionUrl());

            if ($response->failed()) {
                Log::warning('免费节点订阅请求失败', [
                    'status' => $response->status(),
                    'url' => $this->getSubscriptionUrl(),
                ]);

                return $servers;
            }

            $jsonDecode = $response->json();
        } catch (\Throwable $e) {
            Log::warning('免费节点订阅请求异常', [
                'url' => $this->getSubscriptionUrl(),
                'error' => $e->getMessage(),
            ]);

            return $servers;
        }

        if (!is_array($jsonDecode) || !isset($jsonDecode['outbounds']) || !is_array($jsonDecode['outbounds'])) {
            Log::warning('免费节点订阅内容格式无效', [
                'url' => $this->getSubscriptionUrl(),
            ]);

            return $servers;
        }

        // 剔除推广/引流的假节点。
        $filtered = collect($jsonDecode['outbounds'])
            ->filter(function ($item) {
                if (!is_array($item) || count($item) < 7) {
                    return false;
                }

                $tag = $item['tag'] ?? '';
                return !str_contains($tag, 't.me') && !str_contains($tag, '加入我的频道');
            })
            ->values();

        $converted = collect($filtered)->values()
            ->map(fn($outbound, $i) => $this->convertOutboundToServer($outbound, $i + 1))
            ->toArray();

        return collect($servers)->merge($converted)->values()->toArray();
    }

    /**
     * 猜测节点标签的国家标签。
     * @param string $tag
     * @param string $prefix
     *
     * @return string
     */
    private function guessCountryLabel(string $tag, string $prefix = ''): string
    {
        $map = [
            'HK' => '🇭🇰 香港', 'TW' => '🇹🇼 台湾', 'MO' => '🇲🇴 澳门',
            'CN' => '🇨🇳 中国', 'JP' => '🇯🇵 日本', 'KR' => '🇰🇷 韩国',
            'SG' => '🇸🇬 新加坡', 'US' => '🇺🇸 美国', 'CA' => '🇨🇦 加拿大',
            'GB' => '🇬🇧 英国', 'UK' => '🇬🇧 英国', 'DE' => '🇩🇪 德国',
            'FR' => '🇫🇷 法国', 'NL' => '🇳🇱 荷兰', 'IT' => '🇮🇹 意大利',
            'ES' => '🇪🇸 西班牙', 'PT' => '🇵🇹 葡萄牙', 'CH' => '🇨🇭 瑞士',
            'FI' => '🇫🇮 芬兰', 'SE' => '🇸🇪 瑞典', 'NO' => '🇳🇴 挪威',
            'DK' => '🇩🇰 丹麦', 'IE' => '🇮🇪 爱尔兰', 'PL' => '🇵🇱 波兰',
            'AT' => '🇦🇹 奥地利', 'BE' => '🇧🇪 比利时', 'LV' => '🇱🇻 拉脱维亚',
            'LT' => '🇱🇹 立陶宛', 'EE' => '🇪🇪 爱沙尼亚', 'RU' => '🇷🇺 俄罗斯',
            'UA' => '🇺🇦 乌克兰', 'TR' => '🇹🇷 土耳其', 'IN' => '🇮🇳 印度',
            'ID' => '🇮🇩 印度尼西亚', 'MY' => '🇲🇾 马来西亚', 'TH' => '🇹🇭 泰国',
            'VN' => '🇻🇳 越南', 'PH' => '🇵🇭 菲律宾', 'AU' => '🇦🇺 澳大利亚',
            'NZ' => '🇳🇿 新西兰', 'BR' => '🇧🇷 巴西', 'AR' => '🇦🇷 阿根廷',
            'MX' => '🇲🇽 墨西哥', 'ZA' => '🇿🇦 南非', 'AE' => '🇦🇪 阿联酋',
            'IL' => '🇮🇱 以色列', 'SA' => '🇸🇦 沙特',
        ];

        foreach ($map as $code => $replace) {
            if (str_contains($tag, $code)) {
                [$flag, $name] = explode(' ', $replace, 2);
                // 先只替换文字部分
                $text = str_replace($code, $prefix . $name, $tag);
                // 去掉所有空格
                $text = str_replace(' ', '', $text);
                // 拼上国旗+空格
                return $flag . ' ' . $text;
            }
        }

        return '🇺🇸' . ' ' . $tag;
    }

    /**
     * 将远程 outbound 转换为 XBoard 节点。
     *
     * @param array<string, mixed> $outbound
     * @return array<string, mixed>
     */
    private function convertOutboundToServer(array $outbound, int $sort = 0): array
    {
        $server = self::SERVER_TEMPLATE;
        $server['id'] = null;
        $server['name'] = $outbound['tag']; //$this->guessCountryLabel($outbound['tag'] ?? '', 'CF');
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
