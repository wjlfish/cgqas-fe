<?php
/**
 * 复制为 lib/config.php 后填写真实值。
 * lib/config.php 已在 .gitignore 中, 不会被提交。
 */
return [
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=cgtk;charset=utf8mb4',
        'user' => 'YOUR_DB_USER',
        'pass' => 'YOUR_DB_PASSWORD',
    ],

    // 用于签发前端令牌(HMAC); 务必换成 32 字节以上的随机串
    'secret'    => 'CHANGE_ME_RANDOM_STRING_32+',
    'token_ttl' => 10800,

    // 去重审阅页(/dedupe.php)的管理口令; 留空则禁止进入
    'admin_key' => 'CHANGE_ME_ADMIN_PASSWORD',

    'page_size'     => 20,
    'page_max'      => 50,
    'query_max_len' => 40,
    'quiz_max'      => 20,

    // 限流阈值(按 IP)
    'limit' => [
        'search_per_min'  => 40,
        'api_per_min'     => 100,
        'per_day'         => 1200,
        'burst_per_10s'   => 30,
        'strike_limit'    => 5,
        'ban_seconds'     => 900,
        'hard_ban_seconds'=> 86400,
        'hard_threshold'  => 150,
        'enum_pages_5min' => 40,
    ],

    // 命中即 24h 封禁的 UA 特征
    'ua_blacklist' => [
        'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'scrapy',
        'curl/', 'wget/', 'go-http-client', 'java/', 'okhttp', 'axios/',
        'node-fetch', 'undici', 'postmanruntime', 'insomnia', 'libwww-perl',
        'httpclient', 'apache-httpclient', 'guzzle', 'requests-html',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'masscan', 'nmap', 'zgrab', 'nuclei', 'sqlmap',
    ],
];
