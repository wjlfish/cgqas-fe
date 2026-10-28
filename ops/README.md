# 运维脚本

这些文件**不放在 web 根目录**，部署到 `/www/wwwroot/cgtk-ops/`（docroot 之外，外部访问不到）。

## health.php —— 健康检查

由 cron 每 5 分钟执行一次：

```cron
*/5 * * * * /www/server/php/81/bin/php /www/wwwroot/cgtk-ops/health.php >/dev/null 2>&1
```

检查项：

| 检查 | 触发告警的条件 |
|---|---|
| 数据库 | 连接失败 |
| 短信接口 | 近 30 分钟 `sms_send` **全部** 5xx 且次数 ≥ `sms_fail_min` |
| 接口错误率 | 近 30 分钟请求 ≥20 且 5xx 占比 ≥20% |
| PHP-FPM | socket 缺失或 master 进程为 0 |
| 磁盘 | 已用 ≥90%（≥95% 为严重） |
| 站点访问 | 首页非 200 或不含关键内容 |
| 致命错误 | 近 15 分钟 `cgtk-api-error.log` 出现 FATAL |

结果写入 `settings` 表（`health_last_at` / `health_last_state` / `health_last_detail`），
后台「站点管理」顶部会显示一条状态横幅。

恢复时会额外发一条「站点已恢复正常」（此前处于告警态才发）。

## notify.php —— 告警投递

支持四种机器人，按 URL 自动识别：

| 类型 | URL 特征 |
|---|---|
| 企业微信机器人 | `qyapi.weixin.qq.com` |
| 钉钉机器人 | `oapi.dingtalk.com` |
| Telegram | `api.telegram.org` |
| Server酱 | `sctapi.ftqq.com` / `sc.ftqq.com` |
| 通用 webhook | 其他，POST JSON `{title,text,level,ts}` |

配置在 `lib/config.php`：

```php
'alert' => [
    'enable'       => true,
    'webhook'      => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxxx',
    'kind'         => 'auto',
    'min_interval' => 1800,   // 同类告警 30 分钟内不重复
    'sms_fail_min' => 3,
],
```

`webhook` 留空时只写日志 `/www/wwwlogs/cgtk-alert.log`，不阻断检查。

## 日志位置

| 文件 | 内容 |
|---|---|
| `/www/wwwlogs/cgtk-alert.log` | 所有告警（无论是否配置 webhook） |
| `/www/wwwlogs/cgtk-api-error.log` | API 未捕获异常与致命错误 |

## 手动执行

```bash
/www/server/php/81/bin/php /www/wwwroot/cgtk-ops/health.php
tail -f /www/wwwlogs/cgtk-alert.log
```
