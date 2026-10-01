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
            $this->authenticated($request) ? 'mask-analysis.index' : 'mask-analysis.login',
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

    public function data(Request $request, MaskAnalysisService $analysis)
    {
        if (!$this->authenticated($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'email' => 'nullable|string|max:64',
            'ip' => 'nullable|string|max:128',
            'country' => 'nullable|string|size:2',
            'reason' => 'nullable|string|max:32',
            'user_agent' => 'nullable|string|max:512',
            'proxy_only' => 'nullable|boolean',
            'masked_only' => 'nullable|boolean',
            'min_fraud_score' => 'nullable|integer|min:0|max:100',
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:100',
        ]);

        $end = isset($validated['end'])
            ? Carbon::parse($validated['end'])->endOfDay()
            : now()->endOfDay();
        $start = isset($validated['start'])
            ? Carbon::parse($validated['start'])->startOfDay()
            : $end->copy()->subDays(6)->startOfDay();

        if ($end->lt($start) || $start->diffInDays($end) > 30) {
            return response()->json([
                'message' => 'The selected date range must not exceed 31 days.',
            ], 422);
        }

        return response()->json($analysis->analyse([
            'start' => $start,
            'end' => $end,
            'email' => $validated['email'] ?? null,
            'ip' => $validated['ip'] ?? null,
            'country' => isset($validated['country']) ? strtoupper($validated['country']) : null,
            'reason' => $validated['reason'] ?? null,
            'user_agent' => $validated['user_agent'] ?? null,
            'proxy_only' => $request->boolean('proxy_only'),
            'masked_only' => $request->boolean('masked_only'),
            'min_fraud_score' => $validated['min_fraud_score'] ?? null,
            'page' => $validated['page'] ?? 1,
            'page_size' => $validated['page_size'] ?? 10,
        ]));
    }

    public function blacklist(Request $request)
    {
        if (!$this->authenticated($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $type = $request->validate(['type' => 'required|in:ip,email'])['type'];
        $valueRule = $type === 'ip' ? 'required|ip|max:128' : 'required|email:rfc|max:64';
        $value = trim((string) $request->validate(['value' => $valueRule])['value']);
        if ($type === 'email') {
            $value = strtolower($value);
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

        foreach ($existing as $entry) {
            $alreadyListed = $type === 'email'
                ? strtolower($entry) === $value
                : ($entry === $value || \Symfony\Component\HttpFoundation\IpUtils::checkIp($value, $entry));
            if ($alreadyListed) {
                return response()->json(['data' => ['type' => $type, 'value' => $value, 'already_blacklisted' => true]]);
            }
        }

        $lines[] = $value;
        $config[$configKey] = implode("\n", array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== '')));
        $plugin->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        return response()->json(['data' => ['type' => $type, 'value' => $value, 'already_blacklisted' => false]]);
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
}
