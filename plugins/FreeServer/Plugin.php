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
    /**
     * 将 CF 高速节点合并到用户订阅节点中。
     */
    public function boot(): void
    {
        if (!$this->getConfig('enabled', false) || $this->getSubscriptionUrl() === '') {
            return;
        }

        $this->filter('client.subscribe.servers', [$this, 'addFreeServers'], 9);
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
                if (!is_array($item) || count($item) !== 8) {
                    return false;
                }

                $tag = $item['tag'] ?? '';
                return !str_contains($tag, 't.me') && !str_contains($tag, '加入我的频道');
            })
            ->values();

        // 从现有节点中取一个 vless 类型的模板。
        $template = collect($servers)->firstWhere('type', 'vless') ?? [];
        if ($template === []) {
            return $servers;
        }

        $converted = collect($filtered)->values()
            ->map(fn($outbound, $i) => $this->convertOutboundToServer($template, $outbound, $i + 1))
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

        return $tag;
    }

    /**
     * 将远程 outbound 转换为 XBoard 节点。
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $outbound
     * @return array<string, mixed>
     */
    private function convertOutboundToServer(array $template, array $outbound, int $sort = 0): array
    {
        $server = $template;
        $server['id'] = null;
        $server['name'] = $this->guessCountryLabel($outbound['tag'] ?? '', 'CF高速');
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
