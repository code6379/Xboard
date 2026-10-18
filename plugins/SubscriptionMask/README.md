# 订阅风控插件

插件根据 IP 归属、邮箱名单和流量规则判断订阅风险。未命中时正常返回用户节点；命中时清除用户的正常节点，改为返回一个独立的 Shadowsocks 提示节点，默认提示“⚠️ 请关闭代理后更新订阅”。随后优先级为 20 的 `FreeServer` 插件仍按自身分组配置追加 CF 免费节点。用户不在 FreeServer 允许分组内或免费节点获取失败时，仍会收到提示节点。

两个插件只通过订阅过滤的执行顺序配合：`SubscriptionMask` 优先级为 10，`FreeServer` 优先级为 20。插件之间不传递请求标记或节点数据。FreeServer 未启用或订阅地址不可用时，风控提示节点仍会返回。

## 插件配置

| 配置项 | 说明 |
| --- | --- |
| `enabled` | 插件总开关 |
| `notice_message` | 风控提示节点显示的内容 |
| `ip2location_api_keys` | IP2Location API 密钥，多个密钥使用英文逗号分隔，必须由后台配置 |
| `allowlist_ips` | 允许 IP 名单，每行一条 IP/CIDR |
| `blacklist_ip_ranges` | IP 黑名单，每行一条 IP/CIDR |
| `blacklist_emails` | 邮箱黑名单，每行一个邮箱 |
| `whitelist_emails` | 邮箱白名单，跳过低流量规则，不放行国外 IP 或黑名单 |
| `low_traffic_days` | 低流量统计天数 |
| `low_traffic_limit` | 每日流量阈值，单位为字节 |

所有配置只从插件配置读取，不读取 `.env`。名单直接存储在插件配置中，每行一条，支持 `#` 注释。黑名单优先于白名单。IP 白名单可以跳过国家归属判断；非大陆 IP、IP 归属未知和低流量等规则仍按原有条件触发风控。邮箱黑名单和低流量条件不会因关闭代理而自动解除。
