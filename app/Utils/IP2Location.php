<?php

namespace App\Utils;

use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IP2Location
{
    protected string $baseUrl = 'https://api.ip2location.io/';

    /** 配置中的 key 池 */
    protected array $keys = [];

    protected int $timeout = 5;

    /** 轮询计数缓存 key */
    protected string $roundRobinCacheKey = 'ip2location:rr_index';

    public function __construct(array $keys = [])
    {
        $this->keys = !empty($keys) ? $keys : $this->parseKeysFromEnv();
    }

    /**
     * 直接从 .env 读取 IP2LOCATION_API_KEYS,逗号分隔多个 key
     */
    private function parseKeysFromEnv(): array
    {
        $raw = env('IP2LOCATION_API_KEYS', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * 查询 IP(支持指定 key,或从 key 池中自动选取,失败自动切换下一个)
     *
     * @param string      $ip
     * @param string|null $key                显式指定某个 key(优先级最高)
     * @param bool        $autoRetryOtherKeys 当前 key 失败时是否自动尝试池中其他 key
     *
     * @return array
     */
    public function lookup(string $ip, string $key = null, bool $autoRetryOtherKeys = true): array
    {
        // 1. 显式传入 key,直接用,不涉及池
        if ($key) {
            return $this->request($ip, $key);
        }

        $pool = $this->keys;
        if (empty($pool)) {
            throw new RuntimeException('IP2Location 未配置任何 API key');
        }

        // 2. 从池中轮询取一个作为起点
        $startIndex = $this->nextRoundRobinIndex(count($pool));
        $ordered    = $this->reorderFrom($pool, $startIndex);

        $lastException = null;

        foreach ($ordered as $candidateKey) {
            try {
                return $this->request($ip, $candidateKey);
            } catch (Throwable $e) {
                $lastException = $e;
                Log::warning('IP2Location key 请求失败,尝试下一个', [
                    'key_suffix' => substr($candidateKey, -6), // 只记录后6位,避免完整key入日志
                    'message'    => $e->getMessage(),
                ]);

                if (!$autoRetryOtherKeys) {
                    break;
                }
            }
        }

        throw new RuntimeException(
            'IP2Location 所有可用 key 均请求失败: ' . ($lastException?->getMessage() ?? '未知错误')
        );
    }

    /**
     * 实际发起请求
     */
    protected function request(string $ip, string $apiKey): array
    {
        $params = [
            'key'    => $apiKey,
            'ip'     => $ip,
            'format' => 'json',
            'lang'   => 'zh-cn',
        ];

        try {
            $response = Http::timeout($this->timeout)->get($this->baseUrl, $params);
        } catch (Throwable $e) {
            throw new RuntimeException('IP2Location 请求异常: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('IP2Location 请求失败,HTTP状态码: ' . $response->status());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            $message = $data['error']['error_message'] ?? '未知错误';
            throw new RuntimeException('IP2Location 接口错误: ' . $message);
        }

        return $data;
    }

    /**
     * 获取轮询起始下标(基于缓存自增,保证多请求间轮询而非每次都从0开始)
     */
    protected function nextRoundRobinIndex(int $poolSize): int
    {
        if ($poolSize <= 1) {
            return 0;
        }

        $index = Cache::get($this->roundRobinCacheKey, 0);
        $next  = ($index + 1) % $poolSize;
        Cache::put($this->roundRobinCacheKey, $next, now()->addDay());

        return $index % $poolSize;
    }

    /**
     * 将数组从指定下标开始重新排序(用于故障转移时按顺序尝试)
     */
    protected function reorderFrom(array $pool, int $startIndex): array
    {
        $pool = array_values($pool);
        return array_merge(
            array_slice($pool, $startIndex),
            array_slice($pool, 0, $startIndex)
        );
    }

    public function formatImportant(array $raw): array
    {
        $proxy     = $raw['proxy'] ?? [];
        $country   = $raw['country'] ?? [];
        $region    = $raw['region'] ?? [];
        $city      = $raw['city'] ?? [];
        $continent = $raw['continent'] ?? [];

        return [
            // 基础信息(优先取中文翻译,取不到再降级用英文)
            'ip'           => $raw['ip'] ?? null,
            'continent'    => $this->zhOrFallback($continent, $raw['continent']['name'] ?? null),
            'country'      => $this->zhOrFallback($country, $raw['country_name'] ?? null),
            'country_code' => $raw['country_code'] ?? null,
            'region'       => $this->zhOrFallback($region, $raw['region_name'] ?? null),
            'city'         => $this->zhOrFallback($city, $raw['city_name'] ?? null),

            // 归属/线路信息
            'isp'          => $raw['isp'] ?? null,
            'as_name'      => $raw['as'] ?? null,
            'asn'          => $raw['asn'] ?? null,
            'domain'       => $raw['domain'] ?? null,
            'usage_type'   => $raw['usage_type'] ?? null,
            'net_speed'    => $raw['net_speed'] ?? null,

            // 风险核心指标
            'is_proxy'     => $raw['is_proxy'] ?? null,
            'fraud_score'  => $raw['fraud_score'] ?? null,
            'proxy_type'   => $proxy['proxy_type'] ?? null,
            'threat'       => $proxy['threat'] ?? null,
            'provider'     => $proxy['provider'] ?? null,
            'last_seen'    => $proxy['last_seen'] ?? null,

            'risk_flags'   => $this->extractTrueFlags($proxy),
        ];
    }

    /**
     * 从带 translation 的字段结构中取中文值,取不到则降级用英文原值
     *
     * 结构示例:
     * [
     *   "name" => "Singapore",
     *   "translation" => ["lang" => "zh-cn", "value" => "新加坡"]
     * ]
     */
    protected function zhOrFallback(array $field, ?string $fallback): ?string
    {
        return $field['translation']['value'] ?? $fallback;
    }

    /**
     * 从 proxy 信息中提取值为 true 的风险标记
     */
    protected function extractTrueFlags(array $proxy): array
    {
        $labels = [
            'is_vpn'                        => 'VPN',
            'is_tor'                        => 'TOR匿名网络',
            'is_data_center'                => '数据中心IP',
            'is_public_proxy'               => '公共代理',
            'is_web_proxy'                  => 'Web代理',
            'is_web_crawler'                => '网络爬虫',
            'is_ai_crawler'                 => 'AI爬虫',
            'is_residential_proxy'          => '住宅代理',
            'is_consumer_privacy_network'   => '消费者隐私网络',
            'is_enterprise_private_network' => '企业专用网络',
            'is_spammer'                    => '垃圾邮件发送者',
            'is_scanner'                    => '扫描器',
            'is_botnet'                     => '僵尸网络',
            'is_bogon'                      => '非法/保留地址',
        ];

        $flags = [];

        foreach ($labels as $key => $label) {
            if (!empty($proxy[$key])) {
                $flags[] = $label;
            }
        }

        return $flags;
    }

    /**
     * 带缓存的安全查询(用于高频场景,如中间件记录日志)
     *
     * @param string $ip
     * @param int    $ttlMinutes 缓存时长(分钟),默认缓存1天,同一个IP不用重复查API
     *
     * @return array
     */
    public function lookupCached(string $ip, int $ttlMinutes = 24): array
    {
        $cacheKey = 'ip2location:info:' . $ip;

        return Cache::remember($cacheKey, now()->addHours($ttlMinutes), function () use ($ip) {
            return $this->formatImportant($this->lookup($ip));
        });
    }
}
