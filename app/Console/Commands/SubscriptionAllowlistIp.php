<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;

class SubscriptionAllowlistIp extends SubscriptionListCommand
{
    protected $signature = 'sub:allow-ip {action : add, remove, or list} {value? : Full IPv4 address}';

    protected $description = '管理订阅真实域名允许 IP 集合';

    protected string $environmentKey = 'SUBSCRIPTION_ALLOWLIST_IPS_FILE';

    protected string $entryName = '订阅允许 IP';

    protected function normalizeValue(string $value): ?string
    {
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        try {
            $result = (new \Ip2Region())->memorySearch($value);
            $region = (string) ($result['region'] ?? '');
            return str_starts_with($region, '中国|')
                && !str_contains($region, '香港')
                && !str_contains($region, '澳门')
                && !str_contains($region, '台湾')
                ? $value
                : null;
        } catch (\Throwable $exception) {
            Log::warning('订阅允许 IP 归属地查询失败', ['ip' => $value, 'exception' => $exception->getMessage()]);
            return null;
        }
    }
}
