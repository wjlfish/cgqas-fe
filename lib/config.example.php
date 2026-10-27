<?php
/**
 * 复制为 lib/config.php 后填写真实值 (lib/config.php 已在 .gitignore 中)
 * 全局配置。SECRET 用于签发前端令牌, 部署后请勿外泄。
 */
return [
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=cgtk;charset=utf8mb4',
        'user' => 'YOUR_DB_USER',
        'pass' => 'YOUR_DB_PASSWORD',
    ],
    // 更换后所有已发令牌立即失效
    'secret'    => 'CHANGE_ME_RANDOM_STRING_32PLUS_',
    'token_ttl' => 10800,          // 前端令牌有效期(秒)

    // 维护/自测用旁路密钥, 通过请求头 X-CG-Admin 传入; 留空则禁用旁路
    'admin_key' => 'CHANGE_ME_ADMIN_PASSWORD',

    'page_size'       => 5,        // 每页条数
    'page_max'        => 50,       // 最大页码, 防止全量枚举
    'query_max_len'   => 40,       // 关键词长度上限

    // ── 限流阈值(按 IP) ──
    'limit' => [
        'search_per_min'  => 40,   // 检索: 每分钟
        'api_per_min'     => 100,  // 全部接口: 每分钟
        'per_day'         => 1200, // 每天总请求
        'burst_per_10s'   => 30,   // 10 秒突发(仅限速, 不计 strike)
        'strike_limit'    => 5,    // 触发次数→封禁
        'ban_seconds'     => 900,  // 常规封禁 15 分钟
        'hard_ban_seconds'=> 86400,// 严重滥用封禁 24 小时
        'hard_threshold'  => 150,  // 单分钟超过此值直接重封
        'enum_pages_5min' => 40,   // 5 分钟内翻页超过此数视为枚举
        // ── 防全库拖取 ──
        'kw_min_len'      => 2,    // 允许翻页所需的最小关键词长度
        'kw_pages_max'    => 10,   // 有关键词时最多可翻页数(每页5 → 50条/查询)
        'daily_items'     => 8000, // 单 IP 每日累计可返回的记录数
        'window_items'    => 1200,  // 单 IP 5 分钟内可返回的记录数
    ],

    // 阿里云短信
    'aliyun' => [
        'id'       => 'YOUR_ALIYUN_AK_ID',
        'secret'   => 'YOUR_ALIYUN_AK_SECRET',
        // dypns_platform: 用控制台赠送的签名+模板(推荐) | dypns_local: 自生成校验 | dev: 不发短信
        'mode'     => 'dev',   // 调试用 dev; 上线改 dypns_platform
        'sign'     => '',      // 号码认证控制台赠送的签名名称
        'template' => '',      // 赠送的模板 CODE
        'endpoint' => 'https://dypnsapi.aliyuncs.com/',
    ],

    // 阿里云验证码 2.0 (仅在发送短信前校验)
    'captcha2' => [
        'enable'   => false,   // 配置 scene_id 与 prefix 后改 true
        'scene_id' => '',      // 控制台「验证场景」的场景ID
        'prefix'   => '',      // 控制台概览页「身份标」
        'region'   => 'cn',
        'endpoint' => 'https://captcha.cn-shanghai.aliyuncs.com/',
    ],

    // 用户与额度
    'auth' => [
        'require'         => false,  // 短信配好后改 true
        'cookie'          => 'cg_sess',
        'session_ttl'     => 604800,   // 登录有效期 7 天
        'quota_default'   => 150,      // 每个用户搜题次数
        'sms_ttl'         => 300,      // 验证码 5 分钟
        'sms_max_attempts'=> 5,
        'sms_phone_gap'   => 60,       // 同一手机号发送间隔
        'sms_phone_daily' => 5,
        'sms_ip_hourly'   => 10,
        'sms_ip_daily'    => 30,
        'sms_global_10min'=> 20,    // 全站每 10 分钟最多发送条数(成本兜底)
    ],

    // 渐进式验证码: 正常用户不弹, 仅异常索取时要求验证
    'captcha' => [
        'enable'        => false,   // 改用手机号登录后关闭
        'items_5min'    => 120,   // 5 分钟内返回条数超过此值 → 要求验证
        'req_per_min'   => 40,    // 每分钟请求超过此值 → 要求验证
        'clear_seconds' => 600,   // 通过后免验证 10 分钟
        'ttl'           => 120,   // 验证码有效期
        'length'        => 4,
        'max_attempts'  => 3,
        'font'          => '',   // 留空使用站内 assets/captcha.ttf
    ],

    // 命中即 24h 封禁的 UA 特征(爬虫/脚本)
    'ua_blacklist' => [
        'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'scrapy',
        'curl/', 'wget/', 'go-http-client', 'java/', 'okhttp', 'axios/',
        'node-fetch', 'undici', 'postmanruntime', 'insomnia', 'libwww-perl',
        'httpclient', 'apache-httpclient', 'guzzle', 'requests-html',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'masscan', 'nmap', 'zgrab', 'nuclei', 'sqlmap',
    ],
];
