<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
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
            ['analysisBaseUrl' => $this->analysisBaseUrl()]
        );
    }

    public function login(Request $request)
    {
        $request->validate(['password' => 'required|string|max:512']);

        $password = config('mask-analysis.password');
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
            'page_size' => $validated['page_size'] ?? 50,
        ]));
    }

    private function authenticated(Request $request): bool
    {
        try {
            return Crypt::decryptString(urldecode((string) $request->cookie(config('mask-analysis.cookie_name')))) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function analysisBaseUrl(): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        return url('api/v2/' . trim((string) $securePath, '/') . '/mask-analysis');
    }
}
