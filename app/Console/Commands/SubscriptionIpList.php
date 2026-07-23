<?php

namespace App\Console\Commands;

class SubscriptionIpList extends SubscriptionListCommand
{
    protected $signature = 'sub:ip {action : add, remove, or list} {value? : IP address or CIDR range}';

    protected $description = '管理订阅 IP/CIDR 黑名单';

    protected string $environmentKey = 'SUBSCRIPTION_BLACKLIST_IP_RANGES_FILE';

    protected string $entryName = 'IP/CIDR 黑名单';

    protected function normalizeValue(string $value): ?string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        if ($prefix === null || !ctype_digit($prefix) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $maxPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
        return (int) $prefix <= $maxPrefix ? $value : null;
    }
}
