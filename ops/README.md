# 运维脚本

这些文件**不放在 web 根目录**，部署到 `/opt/cgtk-ops/`（docroot 之外，外部访问不到），
与 IP 库放在同一目录，属主 `www-data`。

## health.php —— 健康检查

由 cron 每 5 分钟执行一次：

```cron
*/5 * * * * /usr/bin/php8.5 /opt/cgtk-ops/health.php >/dev/null 2>&1
```

检查项：

| 检查 | 触发告警的条件 |
|---|---|
| 数据库 | 连接失败 |
| 短信接口 | 近 30 分钟 `sms_send` **全部** 5xx 且次数 ≥ `sms_fail_min` |
| 接口错误率 | 近 30 分钟请求 ≥20 且 5xx 占比 ≥20% |
| PHP-FPM | socket 缺失或 master 进程为 0 |
| 磁盘 | 已用 ≥90%（≥95% 为严重） |
| 站点访问 | 首页非 200 或不含关键内容（自检固定解析到 127.0.0.1，避免境外服务器被自己的地域限制拦成提示页） |
| 接口错误 | 近 15 分钟 `cgtk-api-error.log` 出现任意错误（未捕获异常与致命错误） |

结果写入 `settings` 表（`health_last_at` / `health_last_state` / `health_last_detail`），
后台「站点管理」顶部会显示一条状态横幅。

恢复时会额外发一条「站点已恢复正常」（此前处于告警态才发）。

## 爬虫验证（搜索引擎 / AI 搜索）

公开页面允许验证过的爬虫读取（`/`、`/about`、`/guide`、`/review`、`/feedback`、`/assets/`、
`/robots.txt`、`/sitemap.xml`、`/llms.txt`），`/api/`、`/go/`、源码目录、后台一律不放行。

- 代码：`lib/crawler.php`
- 官方 IP 段快照：`lib/crawler-ranges.json`（Google / OpenAI / Perplexity / Anthropic；
  运行时另存 `/var/log/cgtk/crawler-ranges.json`，每 24 小时自动从官方 JSON 刷新）
- 验证结果缓存：`/var/log/cgtk/crawler-verify.json`（6 小时）
- 命中日志：`/var/log/cgtk/crawler.log`

验证方式：

| 类型 | 适用 |
|---|---|
| 反向 DNS 正反查 | Googlebot、Bingbot、Baiduspider、Sogou、Bytespider、YisouSpider、360Spider、Applebot、YandexBot |
| 官方 IP 段 | Google common-crawlers、OpenAI（OAI-SearchBot / GPTBot / ChatGPT-User）、Perplexity、Anthropic（`160.79.104.0/21` + ClaudeBot 自有段 `216.73.216.0/22`） |

Qwen-User、TongyiBot 等官方未公布验证方式的爬虫，服务端**不做放行**，只在 robots.txt 声明允许；
它们从境内 IP 访问时本身就不受地域限制影响。

手动刷新/自查：

```bash
sudo /usr/bin/php8.5 -r '
require "/var/www/cgtk/lib/db.php";
require "/var/www/cgtk/lib/ratelimit.php";
require "/var/www/cgtk/lib/crawler.php";
$r = crawler_ranges();
foreach (["google","openai-searchbot","openai-gptbot","openai-chatgpt-user","perplexitybot"] as $k) {
    printf("%-20s %d\n", $k, count((array)($r[$k] ?? [])));
}
'
sudo rm -f /var/log/cgtk/crawler-verify.json   # 改了验证逻辑后清缓存
sudo chown www-data:www-data /var/log/cgtk/crawler-*.{json,log} 2>/dev/null
```

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

`webhook` 留空时只写日志 `/var/log/cgtk/cgtk-alert.log`，不阻断检查。

## 日志位置

| 文件 | 内容 |
|---|---|
| `/var/log/cgtk/cgtk-alert.log` | 所有告警（无论是否配置 webhook） |
| `/var/log/cgtk/cgtk-api-error.log` | API 未捕获异常与致命错误 |

## 手动执行

```bash
/usr/bin/php8.5 /opt/cgtk-ops/health.php
tail -f /var/log/cgtk/cgtk-alert.log
```
