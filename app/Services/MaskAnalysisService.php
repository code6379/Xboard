<?php

namespace App\Services;

use App\Models\SubscriptionMaskLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\IpUtils;

class MaskAnalysisService
{
    private const RANKING_LIMIT = 50;
    private const EVIDENCE_LIMIT = 50;
    private const SPREAD_WINDOW_SECONDS = 3600;

    public function analyseRankings(array $filters): array
    {
        $query = $this->query($filters);
        $summary = $this->summary($query);
        $blacklistIpRanges = $filters['blacklist_ip_ranges'] ?? [];
        $blacklistEmails = array_map('strtolower', $filters['blacklist_emails'] ?? []);
        $rankings = $this->markRankingBlacklistState($this->attachAccountProfiles($this->rankings($query)), $blacklistIpRanges, $blacklistEmails);
        // 使用当前流量的原始字节值排序，未知账号信息放在最后。
        usort($rankings['traffic_users'], fn (array $left, array $right): int => [
            isset($left['traffic_used']) ? 0 : 1, $left['traffic_used'] ?? 0, $left['user_id'],
        ] <=> [
            isset($right['traffic_used']) ? 0 : 1, $right['traffic_used'] ?? 0, $right['user_id'],
        ]);
        $summary['traffic_user_count'] = count($rankings['traffic_users']);
        $accountEvidence = $this->accountEvidence($query, $rankings['risk_users']);
        $accountEvidence = array_map(function (array $item) use ($blacklistIpRanges, $blacklistEmails): array {
            $item['email_blacklisted'] = in_array(strtolower((string) $item['email']), $blacklistEmails, true);
            $item['email_blacklist_entry'] = $item['email_blacklisted'] ? strtolower((string) $item['email']) : null;
            $item['ips'] = array_map(function (array $ip) use ($blacklistIpRanges): array {
                $ip['blacklist_entry'] = $this->findIpBlacklistEntry($ip['ip'] ?? null, $blacklistIpRanges);
                $ip['is_blacklisted'] = $ip['blacklist_entry'] !== null;
                return $ip;
            }, $item['ips'] ?? []);
            return $item;
        }, $accountEvidence);

        return [
            'summary' => $summary,
            'previous_summary' => $this->previousSummary($filters),
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
        ];
    }

    public function listAccessLogs(array $filters): array
    {
        $query = $this->query($filters);
        $blacklistIpRanges = $filters['blacklist_ip_ranges'] ?? [];
        $blacklistEmails = array_map('strtolower', $filters['blacklist_emails'] ?? []);
        $logPageSize = max(1, min((int) ($filters['log_page_size'] ?? 10), 100));
        $logPage = max(1, (int) ($filters['log_page'] ?? 1));
        $total = (clone $query)->count();
        $logs = (clone $query)
            ->with('user:id,created_at,u,d,transfer_enable')
            ->leftJoin('v2_plan as mask_log_plan', 'v2_subscription_mask_logs.plan_id', '=', 'mask_log_plan.id')
            ->leftJoin('v2_server_group as mask_log_group', 'v2_subscription_mask_logs.group_id', '=', 'mask_log_group.id')
            ->select('v2_subscription_mask_logs.*', 'mask_log_plan.name as plan_name', 'mask_log_group.name as group_name')
            ->orderByDesc('v2_subscription_mask_logs.id')
            ->forPage($logPage, $logPageSize)
            ->get()
            ->map(function (SubscriptionMaskLog $log) use ($blacklistIpRanges, $blacklistEmails): array {
                $row = $this->logRow($log);
                $row['account_profile'] = $this->accountUsage($log->user);
                $row['ip_blacklist_entry'] = $this->findIpBlacklistEntry($row['ip'] ?? null, $blacklistIpRanges);
                $row['ip_blacklisted'] = $row['ip_blacklist_entry'] !== null;
                $row['email_blacklisted'] = in_array(strtolower((string) $row['email']), $blacklistEmails, true);
                $row['email_blacklist_entry'] = $row['email_blacklisted'] ? strtolower((string) $row['email']) : null;
                return $row;
            })
            ->values()
            ->all();

        return [
            'data' => $logs,
            'total' => $total,
            'page' => $logPage,
            'page_size' => $logPageSize,
        ];
    }

    /** 单独查询所选账号的访问 IP，不依赖风险榜的账号或 IP 数量上限。 */
    public function listAccountIps(int $userId, array $filters, int $page = 1, int $pageSize = 10): array
    {
        $query = SubscriptionMaskLog::query()
            ->whereBetween('created_at', [$filters['start'], $filters['end']])
            ->where('user_id', $userId);
        $this->applyRegistrationFilters($query, $filters);
        $latest = (clone $query)->orderByDesc('id')->first();
        $profile = \App\Models\User::query()
            ->leftJoin('v2_plan as account_plan', 'v2_user.plan_id', '=', 'account_plan.id')
            ->where('v2_user.id', $userId)
            ->select('v2_user.id', 'v2_user.email', 'v2_user.created_at', 'v2_user.u', 'v2_user.d', 'v2_user.transfer_enable', 'account_plan.name as plan_name')
            ->first();
        $email = $latest?->email ?? $profile?->email;
        $emails = array_map('strtolower', $filters['blacklist_emails'] ?? []);
        $emailBlacklisted = in_array(strtolower((string) $email), $emails, true);
        $ipRanges = $filters['blacklist_ip_ranges'] ?? [];
        $total = (clone $query)->whereNotNull('ip')->distinct('ip')->count('ip');
        $pageSize = in_array($pageSize, [10, 20, 50], true) ? $pageSize : 10;
        $page = min(max(1, $page), max(1, (int) ceil($total / $pageSize)));
        $ips = (clone $query)
            ->whereNotNull('ip')
            ->selectRaw('ip, COUNT(*) AS request_count, MIN(created_at) AS first_seen_at, MAX(created_at) AS last_seen_at, MAX(country) AS country, MAX(region) AS region, MAX(city) AS city, MAX(fraud_score) AS max_fraud_score, MAX(CASE WHEN is_proxy = 1 THEN 1 ELSE 0 END) AS is_proxy')
            ->groupBy('ip')
            ->orderByDesc('request_count')
            ->orderByDesc('last_seen_at')
            ->orderBy('ip')
            ->forPage($page, $pageSize)
            ->get()
            ->map(function ($row) use ($ipRanges): array {
                $entry = $this->findIpBlacklistEntry($row->ip, $ipRanges);
                return [
                    'ip' => (string) $row->ip,
                    'request_count' => (int) $row->request_count,
                    'country' => $row->country,
                    'region' => $row->region,
                    'city' => $row->city,
                    'is_proxy' => (bool) $row->is_proxy,
                    'max_fraud_score' => $row->max_fraud_score !== null ? (int) $row->max_fraud_score : null,
                    'first_seen_at' => $row->first_seen_at,
                    'last_seen_at' => $row->last_seen_at,
                    'is_blacklisted' => $entry !== null,
                    'blacklist_entry' => $entry,
                ];
            })->all();

        return [
            'account' => array_merge([
                'user_id' => $userId,
                'email' => $email,
                'plan_name' => $profile?->plan_name,
                'email_blacklisted' => $emailBlacklisted,
                'email_blacklist_entry' => $emailBlacklisted ? strtolower((string) $email) : null,
            ], $this->accountUsage($profile)),
            'request_count' => (clone $query)->count(),
            'start' => Carbon::parse($filters['start'])->toDateString(),
            'end' => Carbon::parse($filters['end'])->toDateString(),
            'data' => $ips,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function query(array $filters): Builder
    {
        return $this->applyRegistrationFilters(SubscriptionMaskLog::query(), $filters)
            ->whereBetween('v2_subscription_mask_logs.created_at', [$filters['start'], $filters['end']])
            ->when($filters['email'] ?? null, fn (Builder $query, string $email) => $query->where('email', 'like', '%' . $email . '%'))
            ->when($filters['ip'] ?? null, fn (Builder $query, string $ip) => $query->where('ip', $ip))
            ->when($filters['ip_range'] ?? null, function (Builder $query, string $range): Builder {
                if (filter_var($range, FILTER_VALIDATE_IP)) {
                    return $query->where('ip', $range);
                }

                $matchingIps = (clone $query)
                    ->whereNotNull('ip')
                    ->select('ip')
                    ->distinct()
                    ->pluck('ip')
                    ->filter(fn (string $ip): bool => IpUtils::checkIp($ip, $range))
                    ->values()
                    ->all();

                return $query->whereIn('ip', $matchingIps);
            })
            ->when($filters['country'] ?? null, fn (Builder $query, string $country) => $query->where('country_code', $country))
            ->when($filters['reason'] ?? null, fn (Builder $query, string $reason) => $query->where('reason', $reason))
            ->when($filters['user_agent'] ?? null, fn (Builder $query, string $userAgent) => $query->where('user_agent', 'like', '%' . $userAgent . '%'))
            ->when($filters['proxy_only'] ?? false, fn (Builder $query) => $query->where('is_proxy', true))
            ->when($filters['masked_only'] ?? false, fn (Builder $query) => $query->where('masked', true))
            ->when($filters['min_fraud_score'] ?? null, fn (Builder $query, int $score) => $query->where('fraud_score', '>=', $score));
    }

    /** 注册日期按账号的时间戳筛选，与订阅访问时间分别限制。 */
    private function applyRegistrationFilters(Builder $query, array $filters): Builder
    {
        $start = $filters['registered_start'] ?? null;
        $end = $filters['registered_end'] ?? null;
        if ($start !== null || $end !== null) {
            $query->whereHas('user', function (Builder $users) use ($start, $end): void {
                if ($start !== null) {
                    $users->where('v2_user.created_at', '>=', Carbon::parse($start)->timestamp);
                }
                if ($end !== null) {
                    $users->where('v2_user.created_at', '<=', Carbon::parse($end)->timestamp);
                }
            });
        }

        return $query;
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
            'multi_ip_user_count' => (clone $query)
                ->whereNotNull('ip')
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(DISTINCT ip) >= 2')
                ->get()
                ->count(),
            'cross_region_user_count' => (clone $query)
                ->whereNotNull('country_code')
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(DISTINCT country_code) >= 2')
                ->get()
                ->count(),
            'proxy_user_count' => (clone $query)->where('is_proxy', true)->distinct('user_id')->count('user_id'),
            'shared_user_agent_count' => $this->sharedUserAgentQuery($query)->count(),
            'high_risk_ip_count' => (clone $query)
                ->whereNotNull('ip')
                ->where('fraud_score', '>=', 70)
                ->distinct('ip')
                ->count('ip'),
        ];
    }

    /** 按相邻的同长度时间段计算概览对比，页面不使用示意百分比。 */
    private function previousSummary(array $filters): array
    {
        $start = Carbon::parse($filters['start'])->startOfDay();
        $days = (int) $start->diffInDays(Carbon::parse($filters['end'])->startOfDay()) + 1;
        $previousFilters = array_replace($filters, [
            'start' => $start->copy()->subDays($days),
            'end' => $start->copy()->subSecond(),
        ]);
        $query = $this->query($previousFilters);

        return [
            'total_requests' => (clone $query)->count(),
            'distinct_users' => (clone $query)->distinct('user_id')->count('user_id'),
            'distinct_ips' => (clone $query)->whereNotNull('ip')->distinct('ip')->count('ip'),
            'high_risk_ip_count' => (clone $query)->whereNotNull('ip')->where('fraud_score', '>=', 70)->distinct('ip')->count('ip'),
        ];
    }

    /** 读取账号当前的注册时间和流量，流量单位保持数据库中的字节。 */
    private function accountUsage(?\App\Models\User $user): array
    {
        return [
            'registered_at' => $user?->created_at,
            'traffic_used' => $user ? $user->getTotalUsedTraffic() : null,
            'traffic_remaining' => $user ? $user->getRemainingTraffic() : null,
            'traffic_total' => $user ? (int) ($user->transfer_enable ?? 0) : null,
        ];
    }

    /** 一次查询补齐账号的套餐、注册时间和当前流量，供详情显示。 */
    private function attachAccountProfiles(array $rankings): array
    {
        $userIds = [];
        foreach ($rankings as $rows) {
            foreach ($rows as $row) {
                if (isset($row['user_id'])) {
                    $userIds[] = (int) $row['user_id'];
                }
                foreach ($row['accounts'] ?? [] as $account) {
                    $userIds[] = (int) $account['user_id'];
                }
            }
        }
        $profiles = \App\Models\User::query()
            ->leftJoin('v2_plan as analysis_plan', 'v2_user.plan_id', '=', 'analysis_plan.id')
            ->whereIn('v2_user.id', array_values(array_unique($userIds)))
            ->select('v2_user.id', 'v2_user.created_at', 'v2_user.u', 'v2_user.d', 'v2_user.transfer_enable', 'analysis_plan.name as plan_name')
            ->get()
            ->keyBy('id');
        foreach ($rankings as $key => $rows) {
            foreach ($rows as $index => $row) {
                if (isset($row['user_id'])) {
                    $profile = $profiles->get((int) $row['user_id']);
                    $rankings[$key][$index] = array_merge($row, ['plan_name' => $profile?->plan_name], $this->accountUsage($profile));
                }
                foreach ($row['accounts'] ?? [] as $accountIndex => $account) {
                    $profile = $profiles->get((int) $account['user_id']);
                    $rankings[$key][$index]['accounts'][$accountIndex] = array_merge($account, [
                        'plan_name' => $profile?->plan_name,
                    ], $this->accountUsage($profile));
                }
            }
        }

        return $rankings;
    }

    /** 给排行记录附加当前插件名单状态，页面无需猜测是否已拉黑。 */
    private function markRankingBlacklistState(array $rankings, array $ipRanges, array $emails): array
    {
        foreach ($rankings as $rankingKey => $rows) {
            foreach ($rows as $index => $row) {
                if (isset($row['ip'])) {
                    $rankings[$rankingKey][$index]['blacklist_entry'] = $this->findIpBlacklistEntry($row['ip'], $ipRanges);
                    $rankings[$rankingKey][$index]['is_blacklisted'] = $rankings[$rankingKey][$index]['blacklist_entry'] !== null;
                }
                if (isset($row['email'])) {
                    $rankings[$rankingKey][$index]['email_blacklisted'] = in_array(strtolower((string) $row['email']), $emails, true);
                    $rankings[$rankingKey][$index]['email_blacklist_entry'] = $rankings[$rankingKey][$index]['email_blacklisted']
                        ? strtolower((string) $row['email'])
                        : null;
                }
                if (isset($row['accounts']) && is_array($row['accounts'])) {
                    foreach ($row['accounts'] as $accountIndex => $account) {
                        $email = strtolower((string) ($account['email'] ?? ''));
                        $rankings[$rankingKey][$index]['accounts'][$accountIndex]['email_blacklisted'] = in_array(
                            $email,
                            $emails,
                            true
                        );
                        $rankings[$rankingKey][$index]['accounts'][$accountIndex]['email_blacklist_entry'] = in_array($email, $emails, true) ? $email : null;
                    }
                }
            }
        }

        return $rankings;
    }

    private function findIpBlacklistEntry(?string $ip, array $ranges): ?string
    {
        if (!$ip) {
            return null;
        }

        foreach ($ranges as $range) {
            if ($ip === $range || IpUtils::checkIp($ip, $range)) {
                return $range;
            }
        }

        return null;
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
            'traffic_users' => $userRows->map(fn ($row): array => $this->accountFromUserRow($row))->values()->all(),
            'ip_details' => $this->ipRanking($query),
            'high_risk_ips' => $this->ipRanking($query, true),
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

    /** 按 IP 汇总访问与情报，支持全部 IP 和高风险 IP 排行。 */
    private function ipRanking(Builder $query, bool $highRiskOnly = false): array
    {
        $ipQuery = (clone $query)->whereNotNull('ip');
        if ($highRiskOnly) {
            $ipQuery->where('fraud_score', '>=', 70);
        }

        $grouped = $ipQuery
            ->selectRaw('ip, COUNT(*) AS request_count, COUNT(DISTINCT user_id) AS distinct_users, COUNT(DISTINCT country_code) AS country_count, MAX(created_at) AS latest_seen_at, MAX(fraud_score) AS max_fraud_score, MAX(CASE WHEN is_proxy = 1 THEN 1 ELSE 0 END) AS is_proxy, MAX(country_code) AS country_code, MAX(country) AS country, MAX(region) AS region, MAX(city) AS city, MAX(isp) AS isp, MAX(as_name) AS as_name, MAX(usage_type) AS usage_type, MAX(proxy_type) AS proxy_type, MAX(risk_flags) AS risk_flags')
            ->groupBy('ip');

        $rows = (clone $grouped)
            ->orderByDesc('max_fraud_score')
            ->orderByDesc('request_count')
            ->limit(self::RANKING_LIMIT)
            ->get();
        $addresses = $rows->pluck('ip')->all();
        $accountsByIp = $addresses === []
            ? collect()
            : (clone $query)
                ->whereIn('ip', $addresses)
                ->selectRaw('ip, user_id, MIN(email) AS email, COUNT(*) AS request_count, COUNT(DISTINCT ip) AS distinct_ips, COUNT(DISTINCT country_code) AS distinct_countries, SUM(CASE WHEN is_proxy = 1 THEN 1 ELSE 0 END) AS proxy_requests, MAX(fraud_score) AS max_fraud_score, MIN(created_at) AS first_seen_at, MAX(created_at) AS last_seen_at')
                ->groupBy('ip', 'user_id')
                ->get()
                ->groupBy('ip');

        return $rows->map(function ($row) use ($accountsByIp): array {
                $ip = (string) $row->ip;

                return [
                    'ip' => $ip,
                    'request_count' => (int) $row->request_count,
                    'distinct_users' => (int) $row->distinct_users,
                    'country_count' => (int) $row->country_count,
                    'latest_seen_at' => $row->latest_seen_at,
                    'max_fraud_score' => (int) ($row->max_fraud_score ?? 0),
                    'is_proxy' => (bool) $row->is_proxy,
                    'country_code' => $row->country_code,
                    'country' => $row->country,
                    'region' => $row->region,
                    'city' => $row->city,
                    'isp' => $row->isp,
                    'as_name' => $row->as_name,
                    'usage_type' => $row->usage_type,
                    'proxy_type' => $row->proxy_type,
                    'risk_flags' => array_values(array_filter(array_map('trim', explode(',', (string) $row->risk_flags)))),
                    'accounts' => ($accountsByIp->get($ip) ?? collect())
                        ->map(fn ($account): array => $this->accountFromUserRow($account))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
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
            'plan_id' => $log->plan_id,
            'plan_name' => $log->plan_name,
            'group_id' => $log->group_id,
            'group_name' => $log->group_name,
            'transfer_enable' => $log->transfer_enable,
            'upload' => $log->upload,
            'download' => $log->download,
            'speed_limit' => $log->speed_limit,
            'device_limit' => $log->device_limit,
            'banned' => (bool) $log->banned,
            'expired_at' => $log->expired_at,
            'ip' => $log->ip,
            'forwarded_for' => $log->forwarded_for,
            'real_ip' => $log->real_ip,
            'continent' => $log->continent,
            'country_code' => $log->country_code,
            'country' => $log->country,
            'region' => $log->region,
            'city' => $log->city,
            'isp' => $log->isp,
            'as_name' => $log->as_name,
            'asn' => $log->asn,
            'ip_domain' => $log->ip_domain,
            'usage_type' => $log->usage_type,
            'net_speed' => $log->net_speed,
            'is_proxy' => (bool) $log->is_proxy,
            'proxy_type' => $log->proxy_type,
            'fraud_score' => $log->fraud_score,
            'threat' => $log->threat,
            'proxy_provider' => $log->proxy_provider,
            'proxy_last_seen' => $log->proxy_last_seen,
            'risk_flags' => array_values(array_filter(array_map('trim', explode(',', (string) $log->risk_flags)))),
            'route' => $log->route,
            'request_host' => $log->request_host,
            'request_method' => $log->request_method,
            'requested_types' => $log->requested_types,
            'filter_keyword' => $log->filter_keyword,
            'referer' => $log->referer,
            'user_agent' => $log->user_agent,
            'completed' => (bool) $log->completed,
            'processing_ms' => $log->processing_ms,
            'reason' => $log->reason,
            'masked' => (bool) $log->masked,
            'matched_value' => $log->matched_value,
            'fake_domain' => $log->fake_domain,
            'client_flag' => $log->client_flag,
        ];
    }
}
