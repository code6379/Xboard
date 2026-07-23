<?php

namespace App\Console\Commands;

class SubscriptionEmailList extends SubscriptionListCommand
{
    protected $signature = 'sub:email {action : add, remove, or list} {value? : Full email address}';

    protected $description = '管理订阅邮箱黑名单';

    protected string $environmentKey = 'SUBSCRIPTION_BLACKLIST_EMAILS_FILE';

    protected string $entryName = '邮箱黑名单';

    protected function normalizeValue(string $value): ?string
    {
        $value = strtolower(trim($value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
