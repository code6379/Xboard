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
