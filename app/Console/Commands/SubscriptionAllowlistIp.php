<?php

namespace App\Console\Commands;

class SubscriptionAllowlistIp extends SubscriptionListCommand
{
    protected $signature = 'sub:allow-ip {action : add, remove, or list} {value? : Full IPv4 address}';

    protected $description = '管理订阅非大陆 IP 白名单';

    protected string $environmentKey = 'SUBSCRIPTION_ALLOWLIST_IPS_FILE';

    protected string $entryName = '订阅非大陆 IP 白名单';

    protected function normalizeValue(string $value): ?string
    {
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $value : null;
    }
}
