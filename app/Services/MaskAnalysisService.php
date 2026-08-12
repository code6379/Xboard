<?php

namespace App\Services;

use App\Models\SubscriptionMaskLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MaskAnalysisService
{
    private const RANKING_LIMIT = 20;
    private const EVIDENCE_LIMIT = 50;
    private const SPREAD_WINDOW_SECONDS = 3600;

    public function analyse(array $filters): array
    {
        $query = $this->query($filters);
        $pageSize = max(1, min((int) ($filters['page_size'] ?? 10), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $summary = $this->summary($query);
        $rankings = $this->rankings($query);
        $accountEvidence = $this->accountEvidence($query, $rankings['risk_users']);

        $total = (clone $query)->count();
        $logs = (clone $query)
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (SubscriptionMaskLog $log): array => $this->logRow($log))
            ->values()
            ->all();

        return [
            'summary' => $summary,
            'rankings' => $rankings,
            'account_evidence' => $accountEvidence,
            'suspicion' => [
                'shared_ips' => $rankings['shared_ips'],
                'multi_ip_users' => $rankings['multi_ip_users'],
                'multi_country_users' => $rankings['multi_country_users'],
                'high_frequency_pairs' => $rankings['high_frequency_pairs'],
                'shared_user_agents' => $rankings['shared_user_agents'],
            ],
            'suspects' => $rankings['risk_users'],
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

    private function summary(Builder $query): array
    {
        return [
            'total_requests' => (clone $query)->count(),
            'distinct_users' => (clone $query)->distinct('user_id')->count('user_id'),
            'distinct_ips' => (clone $query)->whereNotNull('ip')->distinct('ip')->count('ip'),
            'masked_requests' => (clone $query)->where('masked', true)->count(),
            'proxy_requests' => (clone $query)->where('is_proxy', true)->count(),
            'high_risk_requests' => (clone $query)->where('fraud_score', '>=', 70)->count(),
            'suspected_leaks' => (clone $query)
                ->whereNotNull('ip')
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(DISTINCT ip) >= 3')
                ->get()
                ->count(),
            'shared_ip_count' => $this->sharedIpQuery($query)->count(),
            'short_term_spread_count' => $this->shortTermSpread($query)->count(),
        ];
    }

    private function rankings(Builder $query): array
    {
        $sharedIps = $this->sharedIpQuery($query)
            ->orderByDesc('distinct_users')
            ->orderByDesc('request_count')
            ->limit(self::RANKING_LIMIT)
            ->get()
            ->map(function ($row) use ($query): array {
                return [
                'ip' => (string) $row->ip,
                'request_count' => (int) $row->request_count,
                'distinct_users' => (int) $row->distinct_users,
                'country_count' => (int) $row->country_count,
                'latest_seen_at' => $row->latest_seen_at,
                'max_fraud_score' => (int) ($row->max_fraud_score ?? 0),
                'is_proxy' => (bool) $row->is_proxy,
                    'accounts' => $this->accountsForValue($query, 'ip', $row->ip),
                ];
            })
            ->values()
            ->all();

        $sharedIpKeys = $this->sharedIpQuery($query)->pluck('ip');
        $sharedIpStats = (clone $query)
            ->whereIn('ip', $sharedIpKeys)
            ->selectRaw('user_id, COUNT(DISTINCT ip) AS shared_ip_count, COUNT(*) AS shared_ip_requests')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $sharedUserAgents = $this->sharedUserAgentQuery($query)
            ->orderByDesc('distinct_users')
            ->orderByDesc('request_count')
            ->limit(self::RANKING_LIMIT)
            ->get()
            ->map(function ($row) use ($query): array {
                return [
                'user_agent' => (string) $row->user_agent,
                'request_count' => (int) $row->request_count,
                'distinct_users' => (int) $row->distinct_users,
                'distinct_ips' => (int) $row->distinct_ips,
                'latest_seen_at' => $row->latest_seen_at,
                    'accounts' => $this->accountsForValue($query, 'user_agent', $row->user_agent),
                ];
            })
            ->values()
            ->all();

        $sharedUaKeys = $this->sharedUserAgentQuery($query)->pluck('user_agent');
        $sharedUaStats = (clone $query)
            ->whereIn('user_agent', $sharedUaKeys)
            ->selectRaw('user_id, COUNT(DISTINCT user_agent) AS shared_user_agent_count, COUNT(*) AS shared_user_agent_requests')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $userRows = (clone $query)
            ->selectRaw('user_id, MIN(email) AS email, COUNT(*) AS request_count, MAX(created_at) AS last_seen_at, MIN(created_at) AS first_seen_at, COUNT(DISTINCT ip) AS distinct_ips, COUNT(DISTINCT country_code) AS distinct_countries, COUNT(DISTINCT user_agent) AS distinct_user_agents, SUM(CASE WHEN is_proxy = 1 THEN 1 ELSE 0 END) AS proxy_requests, COUNT(DISTINCT CASE WHEN is_proxy = 1 THEN ip END) AS proxy_ips, SUM(CASE WHEN fraud_score >= 70 THEN 1 ELSE 0 END) AS high_risk_requests, MAX(fraud_score) AS max_fraud_score')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $spreads = $this->shortTermSpread($query)->keyBy('user_id');
        $riskUsers = $userRows
            ->map(function ($row) use ($sharedIpStats, $sharedUaStats, $spreads): array {
                $sharedIp = $sharedIpStats->get($row->user_id);
                $sharedUa = $sharedUaStats->get($row->user_id);
                $spread = $spreads->get($row->user_id);

                $signals = [];
                $score = 0;
                $sharedIpCount = (int) ($sharedIp->shared_ip_count ?? 0);
                $sharedUaCount = (int) ($sharedUa->shared_user_agent_count ?? 0);
                $proxyIps = (int) $row->proxy_ips;
                $countries = (int) $row->distinct_countries;
                $distinctIps = (int) $row->distinct_ips;

                if ($sharedIpCount > 0) {
                    $points = min(35, 20 + ($sharedIpCount - 1) * 5);
                    $score += $points;
                    $signals[] = ['key' => 'shared_ip', 'label' => '共享 IP', 'count' => $sharedIpCount];
                }
                if ($spread) {
                    $points = min(25, 10 + max(0, (int) $spread['spread_ip_count'] - 3) * 3);
                    $score += $points;
                    $signals[] = ['key' => 'short_term_spread', 'label' => '短时扩散', 'count' => (int) $spread['spread_ip_count']];
                }
                if ($distinctIps >= 3) {
                    $points = min(20, 8 + ($distinctIps - 3) * 2);
                    $score += $points;
                    $signals[] = ['key' => 'multiple_ips', 'label' => '多 IP 访问', 'count' => $distinctIps];
                }
                if ($countries >= 2) {
                    $points = min(15, 8 + ($countries - 2) * 3);
                    $score += $points;
                    $signals[] = ['key' => 'cross_region', 'label' => '跨地区切换', 'count' => $countries];
                }
                if ($proxyIps > 0) {
                    $points = min(15, 7 + ($proxyIps - 1) * 2);
                    $score += $points;
                    $signals[] = ['key' => 'proxy', 'label' => '代理/机房来源', 'count' => $proxyIps];
                }
                if ($sharedUaCount > 0) {
                    $score += 8;
                    $signals[] = ['key' => 'shared_user_agent', 'label' => '共享 User-Agent', 'count' => $sharedUaCount];
                }
                if ((int) $row->high_risk_requests > 0) {
                    $score += min(12, 6 + (int) $row->high_risk_requests);
                    $signals[] = ['key' => 'high_risk_ip', 'label' => '高风险 IP', 'count' => (int) $row->high_risk_requests];
                }

                return [
                    'user_id' => (int) $row->user_id,
                    'email' => (string) $row->email,
                    'risk_score' => min(100, $score),
                    'risk_level' => $score >= 70 ? '高风险' : ($score >= 40 ? '重点核查' : '需要关注'),
                    'request_count' => (int) $row->request_count,
                    'distinct_ips' => $distinctIps,
                    'distinct_countries' => $countries,
                    'proxy_requests' => (int) $row->proxy_requests,
                    'proxy_ips' => $proxyIps,
                    'high_risk_requests' => (int) $row->high_risk_requests,
                    'first_seen_at' => $row->first_seen_at,
                    'last_seen_at' => $row->last_seen_at,
                    'short_term_spread' => $spread,
                    'signals' => $signals,
                    'accounts' => [$this->accountFromUserRow($row)],
                ];
            })
            ->filter(fn (array $user): bool => $user['risk_score'] > 0)
            ->sort(function (array $left, array $right): int {
                return [$right['risk_score'], $right['distinct_ips'], $right['request_count']] <=> [$left['risk_score'], $left['distinct_ips'], $left['request_count']];
            })
            ->take(self::EVIDENCE_LIMIT)
            ->values()
            ->all();

        $proxyUsers = $userRows
            ->filter(fn ($row): bool => (int) $row->proxy_ips > 0)
            ->sortByDesc(fn ($row): array => [(int) $row->proxy_ips, (int) $row->proxy_requests, (int) $row->max_fraud_score])
            ->take(self::RANKING_LIMIT)
            ->map(function ($row): array {
                return [
                'user_id' => (int) $row->user_id,
                'email' => (string) $row->email,
                'proxy_ips' => (int) $row->proxy_ips,
                'proxy_requests' => (int) $row->proxy_requests,
                'max_fraud_score' => (int) ($row->max_fraud_score ?? 0),
                'distinct_countries' => (int) $row->distinct_countries,
                    'accounts' => [$this->accountFromUserRow($row)],
                ];
            })
            ->values()
            ->all();

        $crossRegionUsers = $userRows
            ->filter(fn ($row): bool => (int) $row->distinct_countries >= 2)
            ->sortByDesc(fn ($row): array => [(int) $row->distinct_countries, (int) $row->distinct_ips, (int) $row->request_count])
            ->take(self::RANKING_LIMIT)
            ->map(function ($row): array {
                return [
                'user_id' => (int) $row->user_id,
                'email' => (string) $row->email,
                'distinct_countries' => (int) $row->distinct_countries,
                'distinct_ips' => (int) $row->distinct_ips,
                'request_count' => (int) $row->request_count,
                'last_seen_at' => $row->last_seen_at,
                    'accounts' => [$this->accountFromUserRow($row)],
                ];
            })
            ->values()
            ->all();

        $highFrequencyUsers = $userRows
            ->filter(fn ($row): bool => (int) $row->request_count >= 5)
            ->sortByDesc(fn ($row): array => [(int) $row->request_count, (int) $row->distinct_ips])
            ->take(self::RANKING_LIMIT)
            ->map(function ($row): array {
                return [
                'user_id' => (int) $row->user_id,
                'email' => (string) $row->email,
                'request_count' => (int) $row->request_count,
                'distinct_ips' => (int) $row->distinct_ips,
                'proxy_requests' => (int) $row->proxy_requests,
                'last_seen_at' => $row->last_seen_at,
                    'accounts' => [$this->accountFromUserRow($row)],
                ];
            })
            ->values()
            ->all();

        $ruleHits = (clone $query)
            ->whereNotNull('reason')
            ->where('reason', '<>', '')
            ->selectRaw('reason, COUNT(*) AS request_count, COUNT(DISTINCT user_id) AS distinct_users, MAX(created_at) AS latest_seen_at')
            ->groupBy('reason')
            ->orderByDesc('request_count')
            ->limit(self::RANKING_LIMIT)
            ->get()
            ->map(function ($row) use ($query): array {
                return [
                'reason' => (string) $row->reason,
                'request_count' => (int) $row->request_count,
                'distinct_users' => (int) $row->distinct_users,
                'latest_seen_at' => $row->latest_seen_at,
                    'accounts' => $this->accountsForValue($query, 'reason', $row->reason),
                ];
            })
            ->values()
            ->all();

        return [
            'risk_users' => $riskUsers,
            'shared_ips' => $sharedIps,
            'multi_ip_users' => $this->multiIpUsers($userRows),
            'short_term_spread' => $this->shortTermSpread($query)
                ->take(self::RANKING_LIMIT)
                ->map(function (array $row) use ($query): array {
                    $row['accounts'] = $this->accountsForValue($query, 'user_id', $row['user_id']);

                    return $row;
                })
                ->values()
                ->all(),
            'proxy_users' => $proxyUsers,
            'shared_user_agents' => $sharedUserAgents,
            'cross_region_users' => $crossRegionUsers,
            'multi_country_users' => $crossRegionUsers,
            'high_frequency_users' => $highFrequencyUsers,
            'high_frequency_pairs' => $highFrequencyUsers,
            'rule_hits' => $ruleHits,
        ];
    }

    private function multiIpUsers(Collection $userRows): array
    {
        return $userRows
            ->filter(fn ($row): bool => (int) $row->distinct_ips >= 2)
            ->sortByDesc(fn ($row): array => [(int) $row->distinct_ips, (int) $row->request_count])
            ->take(self::RANKING_LIMIT)
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'email' => (string) $row->email,
                'request_count' => (int) $row->request_count,
                'distinct_ips' => (int) $row->distinct_ips,
            ])
            ->values()
            ->all();
    }

    private function accountsForValue(Builder $query, string $field, mixed $value): array
    {
        return (clone $query)
            ->where($field, $value)
            ->selectRaw('user_id, MIN(email) AS email, COUNT(*) AS request_count, COUNT(DISTINCT ip) AS distinct_ips, COUNT(DISTINCT country_code) AS distinct_countries, SUM(CASE WHEN is_proxy = 1 THEN 1 ELSE 0 END) AS proxy_requests, MAX(fraud_score) AS max_fraud_score, MIN(created_at) AS first_seen_at, MAX(created_at) AS last_seen_at')
            ->groupBy('user_id')
            ->orderByDesc('request_count')
            ->get()
            ->map(fn ($row): array => $this->accountFromUserRow($row))
            ->values()
            ->all();
    }

    private function accountFromUserRow(object $row): array
    {
        return [
            'user_id' => (int) $row->user_id,
            'email' => (string) $row->email,
            'request_count' => (int) ($row->request_count ?? 0),
            'distinct_ips' => (int) ($row->distinct_ips ?? 0),
            'distinct_countries' => (int) ($row->distinct_countries ?? 0),
            'proxy_requests' => (int) ($row->proxy_requests ?? 0),
            'max_fraud_score' => (int) ($row->max_fraud_score ?? 0),
            'first_seen_at' => $row->first_seen_at ?? null,
            'last_seen_at' => $row->last_seen_at ?? null,
        ];
    }

    private function sharedIpQuery(Builder $query): Builder
    {
        return (clone $query)
            ->whereNotNull('ip')
            ->selectRaw('ip, COUNT(*) AS request_count, COUNT(DISTINCT user_id) AS distinct_users, COUNT(DISTINCT country_code) AS country_count, MAX(created_at) AS latest_seen_at, MAX(fraud_score) AS max_fraud_score, MAX(CASE WHEN is_proxy = 1 THEN 1 ELSE 0 END) AS is_proxy')
            ->groupBy('ip')
            ->havingRaw('COUNT(DISTINCT user_id) >= 2');
    }

    private function sharedUserAgentQuery(Builder $query): Builder
    {
        return (clone $query)
            ->whereNotNull('user_agent')
            ->where('user_agent', '<>', '')
            ->selectRaw('user_agent, COUNT(*) AS request_count, COUNT(DISTINCT user_id) AS distinct_users, COUNT(DISTINCT ip) AS distinct_ips, MAX(created_at) AS latest_seen_at')
            ->groupBy('user_agent')
            ->havingRaw('COUNT(DISTINCT user_id) >= 2');
    }

    private function shortTermSpread(Builder $query): Collection
    {
        $rows = (clone $query)
            ->whereNotNull('ip')
            ->select(['user_id', 'email', 'ip', 'country_code', 'created_at'])
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->get();

        return $rows
            ->groupBy('user_id')
            ->map(function (Collection $userRows): ?array {
                $userRows = $userRows->values();
                $left = 0;
                $ipCounts = [];
                $best = null;

                foreach ($userRows as $right => $row) {
                    $ipCounts[$row->ip] = ($ipCounts[$row->ip] ?? 0) + 1;

                    while ($left <= $right && Carbon::parse($row->created_at)->diffInSeconds(Carbon::parse($userRows[$left]->created_at)) > self::SPREAD_WINDOW_SECONDS) {
                        $oldIp = $userRows[$left]->ip;
                        $ipCounts[$oldIp]--;
                        if ($ipCounts[$oldIp] === 0) {
                            unset($ipCounts[$oldIp]);
                        }
                        $left++;
                    }

                    if (count($ipCounts) < 3) {
                        continue;
                    }

                    $candidate = [
                        'user_id' => (int) $row->user_id,
                        'email' => (string) $row->email,
                        'spread_ip_count' => count($ipCounts),
                        'request_count' => $right - $left + 1,
                        'window_start' => $userRows[$left]->created_at,
                        'window_end' => $row->created_at,
                    ];

                    if (!$best || [$candidate['spread_ip_count'], $candidate['request_count']] > [$best['spread_ip_count'], $best['request_count']]) {
                        $best = $candidate;
                    }
                }

                return $best;
            })
            ->filter()
            ->sortByDesc(fn (array $item): array => [$item['spread_ip_count'], $item['request_count']])
            ->values();
    }

    private function accountEvidence(Builder $query, array $riskUsers): array
    {
        if ($riskUsers === []) {
            return [];
        }

        $userIds = array_column($riskUsers, 'user_id');
        $rows = (clone $query)
            ->whereIn('user_id', $userIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id');

        $sharedIpCounts = $this->sharedIpQuery($query)->pluck('distinct_users', 'ip');

        return collect($riskUsers)
            ->map(function (array $riskUser) use ($rows, $sharedIpCounts): array {
                $userRows = $rows->get($riskUser['user_id'], collect());
                $ips = $userRows
                    ->filter(fn (SubscriptionMaskLog $row): bool => filled($row->ip))
                    ->groupBy('ip')
                    ->map(function (Collection $ipRows, string $ip) use ($sharedIpCounts): array {
                        $latest = $ipRows->first();

                        return [
                            'ip' => $ip,
                            'request_count' => $ipRows->count(),
                            'country_code' => $latest->country_code,
                            'country' => $latest->country,
                            'region' => $latest->region,
                            'city' => $latest->city,
                            'isp' => $latest->isp,
                            'as_name' => $latest->as_name,
                            'asn' => $latest->asn,
                            'is_proxy' => $ipRows->contains(fn (SubscriptionMaskLog $row): bool => (bool) $row->is_proxy),
                            'max_fraud_score' => (int) $ipRows->max('fraud_score'),
                            'related_users' => (int) ($sharedIpCounts->get($ip, 1)),
                            'first_seen_at' => $ipRows->last()->created_at,
                            'last_seen_at' => $latest->created_at,
                        ];
                    })
                    ->sortByDesc(fn (array $item): array => [$item['related_users'], $item['request_count']])
                    ->values()
                    ->take(self::RANKING_LIMIT)
                    ->all();

                return [
                    'user_id' => $riskUser['user_id'],
                    'email' => $riskUser['email'],
                    'risk_score' => $riskUser['risk_score'],
                    'risk_level' => $riskUser['risk_level'],
                    'ips' => $ips,
                    'user_agents' => $userRows
                        ->filter(fn (SubscriptionMaskLog $row): bool => filled($row->user_agent))
                        ->groupBy('user_agent')
                        ->map(fn (Collection $uaRows, string $userAgent): array => [
                            'user_agent' => $userAgent,
                            'request_count' => $uaRows->count(),
                            'last_seen_at' => $uaRows->first()->created_at,
                        ])
                        ->sortByDesc('request_count')
                        ->values()
                        ->take(10)
                        ->all(),
                    'timeline' => $userRows
                        ->take(50)
                        ->map(fn (SubscriptionMaskLog $row): array => [
                            'created_at' => $row->created_at,
                            'ip' => $row->ip,
                            'country_code' => $row->country_code,
                            'country' => $row->country,
                            'city' => $row->city,
                            'isp' => $row->isp,
                            'is_proxy' => (bool) $row->is_proxy,
                            'fraud_score' => $row->fraud_score,
                            'user_agent' => $row->user_agent,
                            'reason' => $row->reason,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function logRow(SubscriptionMaskLog $log): array
    {
        return [
            'id' => (int) $log->id,
            'created_at' => $log->created_at,
            'user_id' => (int) $log->user_id,
            'email' => $log->email,
            'ip' => $log->ip,
            'country_code' => $log->country_code,
            'country' => $log->country,
            'region' => $log->region,
            'city' => $log->city,
            'isp' => $log->isp,
            'as_name' => $log->as_name,
            'asn' => $log->asn,
            'is_proxy' => (bool) $log->is_proxy,
            'proxy_type' => $log->proxy_type,
            'fraud_score' => $log->fraud_score,
            'user_agent' => $log->user_agent,
            'reason' => $log->reason,
            'masked' => (bool) $log->masked,
            'matched_value' => $log->matched_value,
            'fake_domain' => $log->fake_domain,
            'client_flag' => $log->client_flag,
        ];
    }
}
