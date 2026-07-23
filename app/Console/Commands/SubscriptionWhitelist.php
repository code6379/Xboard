<?php

namespace App\Console\Commands;

class SubscriptionWhitelist extends SubscriptionListCommand
{
    protected $signature = 'sub:white {action : add, remove, or list} {value? : Full email address}';

    protected $description = '管理订阅邮箱白名单';

    protected string $environmentKey = 'SUBSCRIPTION_WHITELIST_EMAILS_FILE';

    protected string $entryName = '邮箱白名单';

    protected function normalizeValue(string $value): ?string
    {
        $value = strtolower(trim($value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
