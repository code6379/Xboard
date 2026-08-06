<?php

namespace App\Services;

use App\Models\SubscriptionMaskLog;
use Illuminate\Database\Eloquent\Builder;

class MaskAnalysisService
{
    public function analyse(array $filters): array
    {
        $query = $this->query($filters);
        $pageSize = max(1, min((int) ($filters['page_size'] ?? 50), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $summary = [
            'total_requests' => (clone $query)->count(),
            'distinct_users' => (clone $query)->distinct('user_id')->count('user_id'),
            'distinct_ips' => (clone $query)->whereNotNull('ip')->distinct('ip')->count('ip'),
            'masked_requests' => (clone $query)->where('masked', true)->count(),
            'proxy_requests' => (clone $query)->where('is_proxy', true)->count(),
            'high_risk_requests' => (clone $query)->where('fraud_score', '>=', 70)->count(),
        ];

        $sharedIps = (clone $query)
            ->whereNotNull('ip')
            ->selectRaw('ip, COUNT(*) AS request_count, COUNT(DISTINCT user_id) AS distinct_users')
            ->groupBy('ip')
            ->havingRaw('COUNT(DISTINCT user_id) >= 2')
            ->orderByDesc('distinct_users')
            ->orderByDesc('request_count')
            ->limit(20)
            ->get()
            ->values()
            ->all();

        $multiIpUsers = (clone $query)
            ->whereNotNull('ip')
            ->selectRaw('user_id, MIN(email) AS email, COUNT(*) AS request_count, COUNT(DISTINCT ip) AS distinct_ips')
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT ip) >= 2')
            ->orderByDesc('distinct_ips')
            ->orderByDesc('request_count')
            ->limit(20)
            ->get()
            ->values()
            ->all();

        $multiCountryUsers = (clone $query)
            ->whereNotNull('country_code')
            ->selectRaw('user_id, MIN(email) AS email, COUNT(*) AS request_count, COUNT(DISTINCT country_code) AS distinct_countries')
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT country_code) >= 2')
            ->orderByDesc('distinct_countries')
            ->orderByDesc('request_count')
            ->limit(20)
            ->get()
            ->values()
            ->all();

        $highFrequencyPairs = (clone $query)
            ->whereNotNull('ip')
            ->selectRaw('user_id, MIN(email) AS email, ip, COUNT(*) AS request_count')
            ->groupBy('user_id', 'ip')
            ->havingRaw('COUNT(*) >= 5')
            ->orderByDesc('request_count')
            ->limit(20)
            ->get()
            ->values()
            ->all();

        $sharedUserAgents = (clone $query)
            ->whereNotNull('user_agent')
            ->where('user_agent', '<>', '')
            ->selectRaw('user_agent, COUNT(*) AS request_count, COUNT(DISTINCT user_id) AS distinct_users, COUNT(DISTINCT ip) AS distinct_ips')
            ->groupBy('user_agent')
            ->havingRaw('COUNT(DISTINCT user_id) >= 2')
            ->orderByDesc('distinct_users')
            ->orderByDesc('request_count')
            ->limit(20)
            ->get()
            ->values()
            ->all();

        $sharedIpKeys = (clone $query)
            ->whereNotNull('ip')
            ->select('ip')
            ->groupBy('ip')
            ->havingRaw('COUNT(DISTINCT user_id) >= 2');
        $sharedIpRequests = (clone $query)
            ->whereIn('ip', $sharedIpKeys)
            ->selectRaw('user_id, COUNT(*) AS request_count')
            ->groupBy('user_id')
            ->pluck('request_count', 'user_id');

        $sharedUserAgentKeys = (clone $query)
            ->whereNotNull('user_agent')
            ->where('user_agent', '<>', '')
            ->select('user_agent')
            ->groupBy('user_agent')
            ->havingRaw('COUNT(DISTINCT user_id) >= 2');
        $sharedUserAgentRequests = (clone $query)
            ->whereIn('user_agent', $sharedUserAgentKeys)
            ->selectRaw('user_id, COUNT(*) AS request_count')
            ->groupBy('user_id')
            ->pluck('request_count', 'user_id');

        $suspects = (clone $query)
            ->selectRaw('user_id, MIN(email) AS email, COUNT(*) AS request_count, MAX(created_at) AS last_seen_at, COUNT(DISTINCT ip) AS distinct_ips, COUNT(DISTINCT country_code) AS distinct_countries, SUM(CASE WHEN is_proxy = 1 THEN 1 ELSE 0 END) AS proxy_requests, SUM(CASE WHEN fraud_score >= 70 THEN 1 ELSE 0 END) AS high_risk_requests')
            ->groupBy('user_id')
            ->get()
            ->map(function ($user) use ($sharedIpRequests, $sharedUserAgentRequests): array {
                $score = 0;
                $signals = [];
                $sharedIpCount = (int) $sharedIpRequests->get($user->user_id, 0);
                $sharedUserAgentCount = (int) $sharedUserAgentRequests->get($user->user_id, 0);

                if ($sharedIpCount > 0) {
                    $score += 35;
                    $signals[] = ['label' => '共享 IP', 'count' => $sharedIpCount];
                }
                if ($sharedUserAgentCount > 0) {
                    $score += 10;
                    $signals[] = ['label' => '共享 User-Agent', 'count' => $sharedUserAgentCount];
                }
                if ((int) $user->distinct_countries >= 2) {
                    $score += 15;
                    $signals[] = ['label' => '跨国访问', 'count' => (int) $user->distinct_countries];
                }
                if ((int) $user->distinct_ips >= 3) {
                    $score += 12;
                    $signals[] = ['label' => '多 IP 访问', 'count' => (int) $user->distinct_ips];
                }
                if ((int) $user->proxy_requests > 0) {
                    $score += 8;
                    $signals[] = ['label' => '代理网络', 'count' => (int) $user->proxy_requests];
                }
                if ((int) $user->high_risk_requests > 0) {
                    $score += 15;
                    $signals[] = ['label' => '高风险网络', 'count' => (int) $user->high_risk_requests];
                }

                return [
                    'user_id' => (int) $user->user_id,
                    'email' => (string) $user->email,
                    'risk_score' => min($score, 100),
                    'risk_level' => $score >= 60 ? '高风险' : ($score >= 25 ? '待核查' : '证据不足'),
                    'request_count' => (int) $user->request_count,
                    'last_seen_at' => $user->last_seen_at,
                    'signals' => $signals,
                ];
            })
            ->filter(fn (array $suspect): bool => $suspect['risk_score'] > 0)
            ->sort(function (array $left, array $right): int {
                return [$right['risk_score'], $right['request_count']] <=> [$left['risk_score'], $left['request_count']];
            })
            ->take(50)
            ->values()
            ->all();

        $total = (clone $query)->count();
        $logs = (clone $query)
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'suspicion' => [
                'shared_ips' => $sharedIps,
                'multi_ip_users' => $multiIpUsers,
                'multi_country_users' => $multiCountryUsers,
                'high_frequency_pairs' => $highFrequencyPairs,
                'shared_user_agents' => $sharedUserAgents,
            ],
            'suspects' => $suspects,
            'logs' => [
                'data' => $logs,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ],
        ];
    }

    public function query(array $filters): Builder
    {
        return SubscriptionMaskLog::query()
            ->whereBetween('created_at', [$filters['start'], $filters['end']])
            ->when($filters['email'] ?? null, fn (Builder $query, string $email) => $query->where('email', 'like', '%' . $email . '%'))
            ->when($filters['ip'] ?? null, fn (Builder $query, string $ip) => $query->where('ip', $ip))
            ->when($filters['country'] ?? null, fn (Builder $query, string $country) => $query->where('country_code', $country))
            ->when($filters['reason'] ?? null, fn (Builder $query, string $reason) => $query->where('reason', $reason))
            ->when($filters['user_agent'] ?? null, fn (Builder $query, string $userAgent) => $query->where('user_agent', 'like', '%' . $userAgent . '%'))
            ->when($filters['proxy_only'] ?? false, fn (Builder $query) => $query->where('is_proxy', true))
            ->when($filters['masked_only'] ?? false, fn (Builder $query) => $query->where('masked', true))
            ->when($filters['min_fraud_score'] ?? null, fn (Builder $query, int $score) => $query->where('fraud_score', '>=', $score));
    }
}
