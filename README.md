# 创高题库检索

> 本项目仅用于复习学习创高体育 App 内的学习题目。
> **开发者不提倡也不建议任何同学在考试时使用此题库。**

在线地址：[https://cgtk.wjlo.cc](https://cgtk.wjlo.cc)

## 这是什么

一个面向「四川大学创高体育理论考试」的题库检索工具。输入关键词即可查到题干、
选项与标准答案，支持按科目、题型筛选，以及随机练习与即时判分。

**后端为 PHP + MySQL，前端为原生 HTML/CSS/JS，无构建步骤、无框架、无外部依赖。**

## 功能

- **检索**：题干/答案全文匹配，关键词高亮，支持科目与题型组合筛选
- **练习**：按科目随机抽题，判断题与单选题点击即判，多选题需确认
- **统计**：题库总量、题型分布、各科题量
- **双主题**：纸 / 墨，跟随系统或手动切换
- **键盘优先**：`/` 聚焦搜索，`Esc` 清空
- **移动端适配**：390px 下工具栏折叠为两行，首屏仍可见 6 条结果

## 技术架构

```
浏览器 (原生 JS, 无构建)
   │  X-CG-Token: HMAC 令牌
   ▼
api.php ── 路由 / 校验 / 限流 ──► MySQL
   │
   ├── lib/db.php         PDO 连接
   ├── lib/ratelimit.php  限流与反爬
   ├── lib/admin.php      审阅页鉴权
   └── lib/response.php   统一 JSON 输出
```

## 目录结构

```
index.php            前端入口(下发令牌、写访问日志)
api.php              API 路由(search / subjects / stats / quiz / detail / refresh)
dedupe.php           重复题审阅页(管理用, 需 admin_key)
dedupe_api.php       审阅页接口
assets/app.js        前端逻辑
assets/style.css     设计系统(纸/墨双主题)
lib/                 后端公共库
sql/schema.sql       数据库结构(不含数据)
```

## 部署

前置：PHP 8.0+ (pdo_mysql、mbstring、openssl)、MySQL 8.0+。

```bash
# 1. 拉代码
git clone https://github.com/wjlfish/cgqas-fe.git && cd cgqas-fe

# 2. 建库建表
mysql -u root -p -e "CREATE DATABASE cgtk DEFAULT CHARSET utf8mb4"
mysql -u root -p cgtk < sql/schema.sql

# 3. 配置
cp lib/config.example.php lib/config.php
#   填入数据库账号、随机 secret、管理口令 admin_key

# 4. 授权目录给 web 用户
chown -R www:www . && chmod -R 755 .
```

Web 根目录指向本仓库根目录即可。`index.php` 为默认入口，
`.htaccess` 已屏蔽 `lib/`、`sql/`、`.git/` 与敏感后缀。

不需要 Node、不需要 npm install、不需要打包。

## 数据说明

**题库数据不在本仓库内。** 仓库只包含程序与表结构。

题目表 `questions` 的字段约定：

| 字段 | 说明 |
|---|---|
| `type` | `判断` / `单选` / `多选` / `填空` / `问答` |
| `stem` | 题干原文 |
| `stem_norm` | 题干归一化后的 SHA1，用于去重（唯一键） |
| `options` | JSON 数组 `[{"key":"A","text":"…"}]`，判断题为 `NULL` |
| `answer` | 判断题存 `正确`/`错误`；选择题存选项字母如 `A`、`AC` |
| `source` | 数据来源标记 |
| `subject_id` | 主科目 |

题目与科目是多对多关系，映射表为 `question_subjects`。

## 安全与反爬

站点是只读检索服务，接口做了多层限制：

| 措施 | 说明 |
|---|---|
| 令牌校验 | 页面下发 HMAC 令牌(`X-CG-Token`)，无令牌或伪造令牌直接拒绝 |
| 同源校验 | 校验 `Sec-Fetch-Site` / `Referer`，阻断跨站调用 |
| UA 黑名单 | curl、python-requests、HeadlessChrome、scrapy 等命中即封禁 24h |
| 频率限制 | 按 IP 统计每分钟 / 每 10 秒突发 / 每天三个维度，超限降速并累计违规，达阈值封禁 |
| 翻页枚举检测 | 5 分钟内翻页过多判定为爬虫，直接封禁 |
| 蜜罐路径 | `action=__trap` 命中即封禁 24h |
| 结果上限 | 每页 20 条、最大 50 页，避免被整库拖走 |

限流状态存 MySQL 的 `rate_limits` 表，按 IP 维度计数。

## License

见 [LICENSE](LICENSE)。

## 作者

[初音过去](https://github.com/wjlfish)
