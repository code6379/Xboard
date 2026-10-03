<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\MaskAnalysisService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

class MaskAnalysisController extends Controller
{
    public function page(Request $request)
    {
        return view(
            $this->authenticated($request) ? 'mask-analysis.dashboard' : 'mask-analysis.login',
            ['analysisBaseUrl' => $this->analysisBasePath()]
        );
    }

    public function login(Request $request)
    {
        $request->validate(['password' => 'required|string|max:512']);

        $password = $this->getPluginConfig('mask_analysis_password');
        if (!is_string($password) || $password === '') {
            return response()->json(['message' => 'Mask analysis password is not configured.'], 503);
        }

        $key = 'mask-analysis-login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, config('mask-analysis.login_attempts'))) {
            return response()->json(['message' => 'Too many login attempts.'], 429)
                ->header('Retry-After', RateLimiter::availableIn($key));
        }

        if (!hash_equals($password, $request->string('password')->toString())) {
            RateLimiter::hit($key, config('mask-analysis.login_decay_seconds'));

            return response()->json(['message' => 'Password is invalid.'], 422);
        }

        RateLimiter::clear($key);

        return response()->json(['data' => ['authenticated' => true]])->withCookie(
            Cookie::make(
                config('mask-analysis.cookie_name'),
                Crypt::encryptString('1'),
                config('mask-analysis.cookie_minutes'),
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            )
        );
    }

    public function logout()
    {
        return response()->json(['data' => ['authenticated' => false]])
            ->withoutCookie(config('mask-analysis.cookie_name'));
    }

    public function rankings(Request $request, MaskAnalysisService $analysis)
    {
        if (!$this->authenticated($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($analysis->analyseRankings($this->analysisFilters($request)));
    }

    public function logs(Request $request, MaskAnalysisService $analysis)
    {
        if (!$this->authenticated($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($analysis->listAccessLogs($this->analysisFilters($request, true)));
    }

    public function accountIps(Request $request, MaskAnalysisService $analysis)
    {
        if (!$this->authenticated($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|in:10,20,50',
        ]);

        return response()->json($analysis->listAccountIps(
            (int) $validated['user_id'],
            $this->analysisFilters($request),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['page_size'] ?? 10)
        ));
    }

    public function blacklist(Request $request)
    {
        if (!$this->authenticated($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $type = $request->validate(['type' => 'required|in:ip,email'])['type'];
        $request->validate(['action' => 'nullable|in:add,remove']);
        $action = $request->input('action', 'add');
        $value = trim((string) $request->validate(['value' => 'required|string|max:128'])['value']);
        if ($type === 'email') {
            validator(['value' => $value], ['value' => 'required|email:rfc|max:64'])->validate();
            $value = strtolower($value);
        } elseif ($action === 'add') {
            if (!$this->isIpOrCidr($value)) {
                return response()->json(['message' => 'IP 或 CIDR 格式无效'], 422);
            }
            $value = $this->normalizeBlacklistIp($value);
        } elseif (!$this->isIpOrCidr($value)) {
            return response()->json(['message' => 'IP 或 CIDR 格式无效'], 422);
        }

        $plugin = Plugin::query()
            ->where('code', 'subscription_mask')
            ->where('is_enabled', true)
            ->first();
        if (!$plugin) {
            return response()->json(['message' => '订阅风控插件尚未启用'], 409);
        }

        $config = $plugin->config ? (json_decode($plugin->config, true) ?: []) : [];
        if (empty($config['enabled']) || trim((string) ($config['fake_domain'] ?? '')) === '') {
            return response()->json(['message' => '请先启用订阅伪装并配置替换域名'], 409);
        }

        $configKey = $type === 'ip' ? 'blacklist_ip_ranges' : 'blacklist_emails';
        $lines = preg_split('/\R/', (string) ($config[$configKey] ?? '')) ?: [];
        $existing = array_values(array_filter(
            array_map('trim', $lines),
            fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));

        if ($action === 'remove') {
            $removed = false;
            $remaining = [];
            foreach ($lines as $line) {
                $entry = trim($line);
                if ($entry === '' || str_starts_with($entry, '#')) {
                    if ($entry !== '') {
                        $remaining[] = $entry;
                    }
                    continue;
                }

                $matches = $type === 'email'
                    ? strtolower($entry) === $value
                    : $entry === $value;
                if ($matches) {
                    $removed = true;
                    continue;
                }
                $remaining[] = $entry;
            }

            if (!$removed) {
                return response()->json(['data' => ['type' => $type, 'value' => $value, 'removed' => false]]);
            }

            $config[$configKey] = implode("\n", $remaining);
            $plugin->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

            return response()->json(['data' => ['type' => $type, 'value' => $value, 'removed' => true]]);
        }

        foreach ($existing as $entry) {
            $alreadyListed = $type === 'email'
                ? strtolower($entry) === $value
                : $this->ipRangeCovers($entry, $value);
            if ($alreadyListed) {
                if ($type === 'ip') {
                    $cleaned = array_values(array_filter($lines, fn (string $line): bool => trim($line) === $entry || !$this->ipRangeCovers($value, trim($line))));
                    if ($cleaned !== $lines) {
                        $config[$configKey] = implode("\n", $cleaned);
                        $plugin->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    }
                }
                return response()->json(['data' => ['type' => $type, 'value' => $value, 'already_blacklisted' => true, 'matched_entry' => $entry]]);
            }
        }

        if ($type === 'ip') {
            // 用完整网段替换其中已有的单 IP 或更小网段，解除时不会遗留重叠规则。
            $lines = array_values(array_filter($lines, fn (string $line): bool => !$this->ipRangeCovers($value, trim($line))));
        }
        $lines[] = $value;
        $config[$configKey] = implode("\n", array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== '')));
        $plugin->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return response()->json(['data' => ['type' => $type, 'value' => $value, 'already_blacklisted' => false, 'matched_entry' => $value]]);
    }

    private function authenticated(Request $request): bool
    {
        try {
            return Crypt::decryptString(urldecode((string) $request->cookie(config('mask-analysis.cookie_name')))) === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function analysisBasePath(): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        return '/api/v2/' . trim((string) $securePath, '/') . '/mask-analysis';
    }

    private function getPluginConfig(string $key): mixed
    {
        $plugin = \App\Models\Plugin::query()
            ->where('code', 'subscription_mask')
            ->where('is_enabled', true)
            ->first();

        if (!$plugin || !$plugin->config) {
            return null;
        }

        return json_decode($plugin->config, true)[$key] ?? null;
    }

    private function configuredLines(mixed $value): array
    {
        $lines = preg_split('/\R/', (string) $value) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    }

    private function isIpOrCidr(string $value): bool
    {
        $parts = explode('/', $value);
        if (count($parts) > 2 || !filter_var($parts[0], FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        if (!ctype_digit($parts[1])) {
            return false;
        }

        $maxPrefix = str_contains($parts[0], ':') ? 128 : 32;
        return (int) $parts[1] <= $maxPrefix;
    }

    /** 分析页新增的单个 IPv4 默认转换成 /24；显式 CIDR 和 IPv6 保留原范围。 */
    private function normalizeBlacklistIp(string $value): string
    {
        $parts = explode('/', $value);
        if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && (!isset($parts[1]) || (int) $parts[1] === 24)) {
            $octets = explode('.', $parts[0]);
            return implode('.', array_slice($octets, 0, 3)) . '.0/24';
        }

        return $value;
    }

    /** 判断一个名单条目是否覆盖整个目标范围，避免把只覆盖半个 /24 的规则当成已拉黑。 */
    private function ipRangeCovers(string $range, string $target): bool
    {
        if (!$this->isIpOrCidr($range) || !$this->isIpOrCidr($target)) {
            return false;
        }
        $rangeParts = explode('/', $range);
        $targetParts = explode('/', $target);
        $rangeAddress = inet_pton($rangeParts[0]);
        $targetAddress = inet_pton($targetParts[0]);
        if (strlen($rangeAddress) !== strlen($targetAddress)) {
            return false;
        }
        $maxPrefix = strlen($rangeAddress) === 4 ? 32 : 128;
        $rangePrefix = isset($rangeParts[1]) ? (int) $rangeParts[1] : $maxPrefix;
        $targetPrefix = isset($targetParts[1]) ? (int) $targetParts[1] : $maxPrefix;

        return $rangePrefix <= $targetPrefix
            && \Symfony\Component\HttpFoundation\IpUtils::checkIp($targetParts[0], $range);
    }

    private function analysisFilters(Request $request, bool $includeLogPagination = false): array
    {
        $rules = [
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'registered_start' => 'nullable|date_format:Y-m-d',
            'registered_end' => 'nullable|date_format:Y-m-d',
            'email' => 'nullable|string|max:64',
            'ip_range' => 'nullable|string|max:128',
            'ip' => 'nullable|string|max:128',
            'country' => 'nullable|string|size:2',
            'reason' => 'nullable|string|max:32',
            'user_agent' => 'nullable|string|max:512',
            'proxy_only' => 'nullable|boolean',
            'masked_only' => 'nullable|boolean',
            'min_fraud_score' => 'nullable|integer|min:0|max:100',
        ];
        if ($includeLogPagination) {
            $rules['log_page'] = 'nullable|integer|min:1';
            $rules['log_page_size'] = 'nullable|integer|min:1|max:100';
        }

        $validated = $request->validate($rules);
        $end = isset($validated['end']) ? Carbon::parse($validated['end'])->endOfDay() : now()->endOfDay();
        $start = isset($validated['start']) ? Carbon::parse($validated['start'])->startOfDay() : $end->copy()->subDays(6)->startOfDay();
        $registeredStart = isset($validated['registered_start']) ? Carbon::parse($validated['registered_start'])->startOfDay() : null;
        $registeredEnd = isset($validated['registered_end']) ? Carbon::parse($validated['registered_end'])->endOfDay() : null;

        if ($registeredStart && $registeredEnd && $registeredEnd->lt($registeredStart)) {
            abort(response()->json(['message' => '注册结束日期不能早于注册开始日期'], 422));
        }

        if ($end->lt($start) || $start->diffInDays($end) > 30) {
            abort(response()->json(['message' => 'The selected date range must not exceed 31 days.'], 422));
        }

        $ipRange = trim((string) ($validated['ip_range'] ?? ''));
        if ($ipRange !== '' && !$this->isIpOrCidr($ipRange)) {
            abort(response()->json(['message' => 'IP 或 CIDR 格式无效'], 422));
        }

        $plugin = $this->getSubscriptionMaskPlugin();
        $pluginConfig = $plugin && $plugin->config ? (json_decode($plugin->config, true) ?: []) : [];

        return [
            'start' => $start,
            'end' => $end,
            'registered_start' => $registeredStart,
            'registered_end' => $registeredEnd,
            'email' => $validated['email'] ?? null,
            'ip_range' => $ipRange !== '' ? $ipRange : null,
            'ip' => $validated['ip'] ?? null,
            'country' => isset($validated['country']) ? strtoupper($validated['country']) : null,
            'reason' => $validated['reason'] ?? null,
            'user_agent' => $validated['user_agent'] ?? null,
            'proxy_only' => $request->boolean('proxy_only'),
            'masked_only' => $request->boolean('masked_only'),
            'min_fraud_score' => $validated['min_fraud_score'] ?? null,
            'log_page' => $validated['log_page'] ?? 1,
            'log_page_size' => $validated['log_page_size'] ?? 10,
            'blacklist_ip_ranges' => $this->configuredLines($pluginConfig['blacklist_ip_ranges'] ?? ''),
            'blacklist_emails' => $this->configuredLines($pluginConfig['blacklist_emails'] ?? ''),
        ];
    }

    private function getSubscriptionMaskPlugin(): ?Plugin
    {
        return Plugin::query()
            ->where('code', 'subscription_mask')
            ->where('is_enabled', true)
            ->first();
    }
}
