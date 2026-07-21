<?php

namespace App\Services;

use App\Models\StatUser;
use App\Models\User;

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
    public function maskServersForUser(User $user, array $servers): array
    {
        if (!$this->shouldUseFakeDomain($user)) {
            return $servers;
        }

        return $this->replaceServerDomains($servers);
    }

    /**
     * 判断用户最近指定天数内是否每天都没有达到指定流量阈值。
     * 某一天没有流量记录时，按当天使用 0 字节处理。
     */
    private function shouldUseFakeDomain(User $user): bool
    {
        if ($this->getFakeDomain() === '') {
            return false;
        }

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
