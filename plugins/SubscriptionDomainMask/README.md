# 订阅域名伪装插件

## 插件配置

可在后台插件配置中设置以下项目：

| 配置项                                  | 说明                                                           |
|-----------------------------------------|----------------------------------------------------------------|
| `fake_domain`                           | 命中伪装规则时替换节点使用的域名；留空则不启用域名替换         |
| `ip2location_api_keys`                  | IP2Location API 密钥，多个密钥使用英文逗号分隔，必须由后台配置 |
| `allowlist_ips`                          | 允许 IP 名单，每行一条 IP/CIDR                                  |
| `blacklist_ip_ranges`                    | IP 黑名单，每行一条 IP/CIDR                                    |
| `blacklist_emails`                       | 邮箱黑名单，每行一个邮箱                                       |
| `whitelist_emails`                       | 邮箱白名单，每行一个邮箱                                       |
| `low_traffic_days`                      | 低流量统计天数                                                 |
| `low_traffic_limit`                     | 每日流量阈值，单位为字节                                       |
| `low_traffic_alert_interval`            | Telegram 告警间隔，单位为秒                                    |
| `telegram_alert_chat_id`                | Telegram 告警频道或群组 ID                                     |

所有配置只从插件配置读取，不读取 `.env`。四类名单直接存储在插件配置中，不再使用文件路径和命令行 CRUD；每行一条数据，支持 `#` 注释。
