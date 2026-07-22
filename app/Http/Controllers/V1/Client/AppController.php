<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\SubscriptionDomainService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class AppController extends Controller
{
    public function getConfig(Request $request)
    {
        $servers = [];
        $user = $request->user();
        $userService = new UserService();
        $subscriptionDomainService = app(SubscriptionDomainService::class);
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
            // 与普通订阅保持一致，低流量用户只拿到替换后的节点域名。
            $servers = $subscriptionDomainService->maskServersForUser($user, $servers);
        }
        $defaultConfig = base_path() . '/resources/rules/app.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.app.clash.yaml';
        if (File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            $protocol_settings = $item['protocol_settings'];
            if ($item['type'] === 'shadowsocks'
                && in_array(data_get($protocol_settings, 'cipher'), [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305'
                ])
            ) {
                array_push($proxy, \App\Protocols\Clash::buildShadowsocks($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'vmess') {
                array_push($proxy, \App\Protocols\Clash::buildVmess($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'trojan') {
                array_push($proxy, \App\Protocols\Clash::buildTrojan($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $response = Yaml::dump($config);

        // YAML 成功生成后才记录和通知，避免配置构建失败时误报。
        $subscriptionDomainService->notifySuccessfulMaskedSubscription($user, $request, 'Clash 配置');

        return $response;
    }

    public function getVersion(Request $request)
    {
        if (strpos($request->header('user-agent'), 'tidalab/4.0.0') !== false
            || strpos($request->header('user-agent'), 'tunnelab/4.0.0') !== false
        ) {
            if (strpos($request->header('user-agent'), 'Win64') !== false) {
                $data = [
                        'version' => admin_setting('windows_version'),
                        'download_url' => admin_setting('windows_download_url')
                ];
            } else {
                $data = [
                        'version' => admin_setting('macos_version'),
                        'download_url' => admin_setting('macos_download_url')
                ];
            }
        }else{
            $data = [
                'windows_version' => admin_setting('windows_version'),
                'windows_download_url' => admin_setting('windows_download_url'),
                'macos_version' => admin_setting('macos_version'),
                'macos_download_url' => admin_setting('macos_download_url'),
                'android_version' => admin_setting('android_version'),
                'android_download_url' => admin_setting('android_download_url')
            ];
        }
        return $this->success($data);
    }
}
