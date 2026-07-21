<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Utils\Helper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GlobalRequestLog
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'token', 'auth_data', 'authorization', 'secret', 'key', 'api_key'];

    public function handle(Request $request, Closure $next)
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $response  = $next($request);

        try {
            $user = $this->resolveUser($request);
            Log::channel('request')->info('request', [
                'time'              => now()->toDateTimeString(),
                'ip'                => $request->ip(),
                'method'            => $request->method(),
                'uri'               => $request->getRequestUri(),
                'status'            => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
                'duration_ms'       => round((microtime(true) - $startedAt) * 1000, 2),
                'user_id'           => $user['id'] ?? null,
                'email'             => $user['email'] ?? null,
                'used_traffic_text' => $user['used_traffic_text'] ?? null,
                'params'            => $this->clean($request->all()),
                'user-agent'        => $request->header('User-Agent'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Global request log failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function shouldSkip(Request $request): bool
    {
        if ($request->method() === 'OPTIONS') {
            return true;
        }

        $path = ltrim($request->path(), '/');

        $securePath = trim((string)admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), '/');
        if ($securePath !== '' && ($path === $securePath || str_starts_with($path, $securePath . '/'))) {
            return true;
        }

        if ($securePath !== '' && str_starts_with($path, 'api/v2/' . $securePath)) {
            return true;
        }

        if (str_starts_with($path, 'api/v1/server') || str_starts_with($path, 'api/v2/server')) {
            return true;
        }

        return false;
    }

    private function resolveUser(Request $request): array
    {
        $user = $request->user();
        if ($user instanceof User) {
            return [
                'id'                => $user->id,
                'email'             => $user->email,
                'is_admin'          => (bool)$user->is_admin,
                'is_staff'          => (bool)$user->is_staff,
                'used_traffic_text' => Helper::trafficConvert($user->getTotalUsedTraffic()),
            ];
        }

        $sanctumUser = Auth::guard('sanctum')->user();
        if ($sanctumUser instanceof User) {
            return ['id' => $sanctumUser->id, 'email' => $sanctumUser->email];
        }

        $requestUser = $request->input('user');
        if (is_array($requestUser) && isset($requestUser['id'], $requestUser['email'])) {
            return [
                'id'    => $requestUser['id'],
                'email' => $requestUser['email'],
            ];
        }

        return [];
    }

    private function clean(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower($key) : $key;
            if (is_string($normalizedKey) && in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $payload[$key] = '[filtered]';
                continue;
            }
            if (is_array($value)) {
                $payload[$key] = $this->clean($value);
            }
        }

        return $payload;
    }
}
