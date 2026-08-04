<?php

namespace App\Models;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 每次订阅节点域名伪装判断的追溯记录。
 */
class SubscriptionMaskLog extends Model
{
    protected $table      = 'v2_subscription_mask_logs';
    public    $timestamps = false;
    protected $guarded    = ['id'];

    protected $casts = [
        'masked'     => 'boolean',
        'is_proxy'   => 'boolean',
        'banned'     => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 创建一次订阅伪装请求的基础快照。无论后续判断是否完成，该快照都会在 finally 中入库。
     *
     * @param User    $user
     * @param Request $request
     *
     * @return SubscriptionMaskLog
     */
    public static function forMaskingRequest(User $user, Request $request): self
    {
        return new self([
            'user_id'         => $user->id,
            'email'           => $user->email,
            'user_uuid'       => $user->uuid,
            'plan_id'         => $user->plan_id,
            'group_id'        => $user->group_id,
            'transfer_enable' => $user->transfer_enable,
            'upload'          => $user->u ?? 0,
            'download'        => $user->d ?? 0,
            'speed_limit'     => $user->speed_limit,
            'device_limit'    => $user->device_limit,
            'banned'          => $user->banned,
            'expired_at'      => $user->expired_at,
            'ip'              => $request->ip(),
            'forwarded_for'   => $request->header('x-forwarded-for'),
            'real_ip'         => $request->header('x-real-ip'),
            'route'           => optional($request->route())->getName(),
            'request_host'    => $request->getHost(),
            'request_method'  => $request->method(),
            'requested_types' => $request->input('types'),
            'filter_keyword'  => $request->input('filter'),
            'client_flag'     => $request->input('flag'),
            'user_agent'      => $request->userAgent(),
            'referer'         => $request->header('referer'),
            'created_at'      => now(),
        ]);
    }

    /**
     * 写入 IP2Location 查询结果，字段保持扁平，方便后台筛选和聚合。
     *
     * @param array<string, mixed> $ipInfo
     */
    public function fillIpInfo(array $ipInfo): void
    {
        $this->fill([
            'continent'       => $ipInfo['continent'] ?? null,
            'country_code'    => $ipInfo['country_code'] ?? null,
            'country'         => $ipInfo['country'] ?? null,
            'region'          => $ipInfo['region'] ?? null,
            'city'            => $ipInfo['city'] ?? null,
            'isp'             => $ipInfo['isp'] ?? null,
            'as_name'         => $ipInfo['as_name'] ?? null,
            'asn'             => $ipInfo['asn'] ?? null,
            'ip_domain'       => $ipInfo['domain'] ?? null,
            'usage_type'      => $ipInfo['usage_type'] ?? null,
            'net_speed'       => $ipInfo['net_speed'] ?? null,
            'is_proxy'        => filter_var($ipInfo['is_proxy'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'proxy_type'      => $ipInfo['proxy_type'] ?? null,
            'fraud_score'     => is_numeric($ipInfo['fraud_score'] ?? null) ? (int)$ipInfo['fraud_score'] : null,
            'threat'          => $ipInfo['threat'] ?? null,
            'proxy_provider'  => $ipInfo['provider'] ?? null,
            'proxy_last_seen' => $ipInfo['last_seen'] ?? null,
            'risk_flags'      => implode(',', $ipInfo['risk_flags'] ?? []),
        ]);
    }

    /**
     * 标记本次规则判断已完成，并保存是否替换域名及命中规则。
     *
     * @param array{reason: string, value: string}|null $match
     */
    public function markCompleted(?array $match,string $fakeDomain): void
    {
        $this->fill([
            'masked'        => $match !== null,
            'reason'        => $match['reason'] ?? null,
            'matched_value' => $match['value'] ?? null,
            'fake_domain'   => $fakeDomain
        ]);
    }

}
