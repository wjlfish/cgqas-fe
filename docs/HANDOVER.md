# 项目现状与进度

> 给新会话的交接文档。服务器地址、凭据、数据量等敏感信息在 `.private/state.md`。
> 改动本文件时保持"公开可读"：不写主机、账号、口令、具体数据量。

## 这是什么

创高体育理论考试的题库检索站点，已上线运行。用户按关键词检索题目与答案，
需手机号验证码登录，按账号计检索次数。

## 当前形态

- 单站点，源码即本仓库；线上由 Nginx + PHP-FPM + MySQL 承载
- 代码无构建步骤，原生 HTML/CSS/JS + `api.php` 单入口 JSON 接口
- 已从旧域名迁移完毕，旧域名仅保留 301 跳转（数据与源码备份在旧机，PHP 已删除）

## 已实现的功能

| 模块 | 说明 |
|---|---|
| 检索 | 关键词（题干/答案）+ 科目 + 题型筛选，分页上限 10 页 |
| 账号 | 短信验证码登录，未注册自动建号，初始额度 110 次 |
| 科目范围 | 首次进入选科目，检索范围 = 公共题库 + 所选科目；可换 2 次 |
| 跨科目提示 | 可见范围无结果但别的科目有时，提示切换 |
| 推荐词 | 首页空态按科目给词，离线索引 `assets/suggest.json` |
| 反馈 | 错题申报（需登录）、Bug 反馈（匿名需人机验证）、点赞留言（匿名可用） |
| 提额 | 用户申请，后台人工审批 |
| 广告位 | 顶部两块并排，点击经 `/go/<target>` 跳转并记日志 |
| 公开说明 | 首页介绍+常见问题、`/about`、`/guide` 使用指南、`/review` 复习建议；不登录可读 |
| 爬虫放行 | 验证过的搜索引擎/AI 爬虫可读公开页面（见下文「爬虫验证」），接口与后台不放行 |
| 访问控制 | 后台「概览 → 访问控制」可一键开启/关闭境外 IP 地域限制，支持临时关闭 1/6/24 小时（到期自动恢复） |
| 后台 | 随机路径；用户/日志/限流/反馈/提额/去重审阅 |
| 防滥用 | UA 黑名单、关键词门槛、每 IP 每日预算、翻页枚举检测、IP 封禁 |

## URL 规则

```
/               检索页
/about          关于页
/guide          题库使用指南（公开）
/review         创高体育理论考试怎么复习（公开）
/feedback       反馈与额度
/api/<action>   接口（如 /api/search?q=xxx）
/go/<target>    广告跳转
/<随机串>/      后台（路径私密，见 .private/）
```

所有 `.php` 直连返回 404，仅内部重写可达。旧页面 URL（`/about.php` 等）301 到新路径。

## 已知遗留问题（按优先级）

> 2026-11-02 做过一次只读健康检查：站点可用，外部功能正常。完整报告在 `.private/health-<日期>.md`（不入库）。

1. **双机同步单向故障 + 有期限**：新站→旧机方向自 2026-11-01 起每小时失败（`last_ip` 字段长度不兼容，旧库列偏短）；
   旧机→新站方向正常。新站产生的数据目前没有回流到旧机备份。同步任务另设了到期日，到期后需决定是否继续。
2. **健康检查未部署**：脚本已按当前部署更新（`ops/health.php`），但服务器上尚未安装、未配 cron；
   `settings.health_last_at` 停在部署当天，后台横幅显示的是旧状态。告警 `webhook` 仍为空，异常只落日志。
3. **后台分页链接**（已修，待部署）：后台分页按钮曾丢失随机路径前缀，点击会跳出后台；`admin/index.php` 已修正。
4. **旧域名残留**（已修，待部署）：canonical/og/robots/llms 曾指向旧域名，已统一为 `cgtk.org`；
   指向 apex 的 `www` 跳转已写入 `ops/nginx.conf`，同样待部署。
5. **静态资源与遗留文件**（待服务器清理）：线上 `assets/` 下的 CSS/JS 源文件仍是旧版，与 manifest 指向的哈希产物不一致；
   站点根目录还留有一个游离的旧版 `app.*.js`。需在服务器上重建资源并清理。
6. **历史用户未选科目**：迁移前的账号 `subject_id` 为空，下次访问会弹一次科目选择（占用户多数）。
7. **推荐词索引是快照**：`ops/build-suggest.php` 生成，题库或检索习惯变化后需重跑；
   该文件文件名不含哈希，已改为短缓存（1 小时），重建后最多 1 小时内生效。
8. **GitHub 旧对象残留**：历史已重写，但旧提交对象在平台上有保留期，介意需删库重建。

## 运维注意事项

- 部署脚本尚未迁移到新环境，目前按 `AGENTS.md` 的 5 步手工部署。
- `.private/admin_path.txt` 曾与实际后台目录不一致，已按 `secrets.txt` 的 `ADMIN_PATH` 校正。
- 同步脚本文本含明文口令，`/tmp` 下还有临时副本；建议收紧权限并轮换口令。

## 待确认的产品问题

- 广告位两块谁在左谁在右，目前靠人工判断，可等点击数据积累后再定
- 提额审批标准（目前靠人工看理由）
- 是否要加异常账号检测面板（同 UA 多账号、检索序列高度重合等）

## 爬虫验证

站点对境外 IP 与脚本 UA 有限制，但搜索引擎和 AI 搜索需要能读到公开页面。
放行**不依据 User-Agent**（UA 可伪造），实现见 `lib/crawler.php`：

- **反向 DNS 正反查**：Googlebot（`*.googlebot.com`）、Bingbot（`*.search.msn.com`）、
  Baiduspider（`*.baidu.com` / `*.baidu.jp`）、Sogou（`*.sogou.com`）、
  Bytespider（`*.crawl.bytedance.com`，社区观测约定）、YisouSpider（`*.yisou.com`）、
  360Spider、Applebot、YandexBot
- **官方 IP 段**：Google（`common-crawlers.json`）、OpenAI（`searchbot.json` / `gptbot.json` /
  `chatgpt-user.json`）、Perplexity（`perplexitybot.json` / `perplexity-user.json`）、
  Anthropic（API 出网段 `160.79.104.0/21` + ClaudeBot 抓取所用的自有段 `216.73.216.0/22`，
  后者经 RDAP 确认为 Anthropic, PBC 登记）
- 官方**没有**公布验证方式的（Qwen-User、TongyiBot 等）不做服务端放行，只在 robots.txt 声明允许
- 放行范围仅限公开页面白名单（`/`、`/about`、`/guide`、`/review`、`/feedback`、`/assets/`、
  `/robots.txt`、`/sitemap.xml`、`/llms.txt`），`/api/`、`/go/`、源码目录与后台一律不放行
- 验证结果与官方 IP 段都有本地缓存；命中会写 `/var/log/cgtk/crawler.log`

## SEO 要点（2026-11-06）

- 每个可索引页面只有一个 H1；标题/描述唯一，不含关键词堆砌
- 内页有可见面包屑，并配 `BreadcrumbList` 结构化数据；首页有 `WebSite`
- `/feedback`、`404` 均 `noindex`，不放进 sitemap；sitemap 只列 `/`、`/guide`、`/review`、`/about`
- `www`→apex、`http`→`https`、旧 `.php` URL、尾斜杠 URL 统一 301
- 404 走自定义页面（保留 404 状态码 + `X-Robots-Tag: noindex`），未知路径不会返回软 404
- favicon 为真实文件（`/favicon.ico` 与 `/assets/favicon.ico`），爬虫可抓取
- 爬虫看到的内容与用户一致（无 cloaking）：验证过的爬虫只是跳过地域限制，页面本身不变
- 上线后到 Google Search Console 重新提交 sitemap，并对新页面 `/guide`、`/review` 请求编入索引

- 地域限制开关存在 `settings` 表：`geo_enable`（1 开 / 0 关）、`geo_off_until`（临时关闭到期时间戳）；
  改这两个值等同于后台按钮，其他防护（UA/限流/登录/接口）不受影响。

## 开发约定

见 `AGENTS.md`：改接口路径先跑 `ops/check-urls.sh`；改 CSS/JS 必须重建资源；
不对外公示题库规模；README 面向外部读者。

## 关键决策记录

- **单入口 API**：所有接口走 `api.php`，靠 `action` 分发，便于统一做限流/鉴权/日志
- **静态资源内容哈希**：避免 `?v=` 被 CDN/代理忽略导致缓存不更新
- **科目范围限制**：正常用户只该看到公共题库 + 自己科目的题，减少无关结果
- **反馈匿名化**：Bug 反馈不登录也能提（否则用户有问题没法反馈），用人机验证防刷
- **首页不展示题库规模**：不公示总题量与科目题数
