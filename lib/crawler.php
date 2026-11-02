<?php
declare(strict_types=1);
/**
 * 搜索引擎 / AI 爬虫验证
 *
 * 目标：在保留普通用户访问限制（地域/UA/限流）的前提下，让**经过验证**的
 * 搜索与 AI 爬虫读取公开页面。绝不只凭 User-Agent 判断——UA 可以伪造：
 *
 *   1) 反向 DNS 正反查（FCrDNS）：Googlebot / Bingbot / Baiduspider / Sogou /
 *      Bytespider / YisouSpider / 360Spider / Applebot / YandexBot
 *   2) 官方公布的 IP 段：Google（common-crawlers.json）、OpenAI
 *      （OAI-SearchBot / GPTBot / ChatGPT-User）、Perplexity（PerplexityBot /
 *      Perplexity-User）、Anthropic（ClaudeBot 等，官方出网段）
 *   3) 官方没有公布验证方式的（如 Qwen-User / TongyiBot / 豆包普通抓取），
 *      一律**不放行**，只在 robots.txt 里声明允许。
 *
 * 放行范围只有公开页面白名单（见 crawler_public_path_allowed），
 * /api/、源码目录、后台路径等一律不在此列。
 */

/** 各家族 UA 特征（小写子串 => 家族名），顺序敏感：先匹配更具体的 */
const CG_CRAWLER_UA = [
    'oai-searchbot'    => 'oai-searchbot',
    'chatgpt-user'     => 'chatgpt-user',
    'gptbot'           => 'gptbot',
    'perplexitybot'    => 'perplexitybot',
    'perplexity-user'  => 'perplexity-user',
    'claude-searchbot' => 'claude-searchbot',
    'claude-user'      => 'claude-user',
    'claudebot'        => 'claudebot',
    'googlebot'        => 'googlebot',
    'bingbot'          => 'bingbot',
    'baiduspider'      => 'baiduspider',
    'sogou web spider' => 'sogou',
    'sogou inst spider'=> 'sogou',
    'bytespider'       => 'bytespider',
    'yisouspider'      => 'yisouspider',
    'haosouspider'     => '360',
    '360spider'        => '360',
    'applebot'         => 'applebot',
    'yandexbot'        => 'yandex',
    'qwen-user'        => 'qwen-user',
    'tongyibot'        => 'tongyibot',
];

/** 反向 DNS 后缀（FCrDNS）。Baidu 官方口径：*.baidu.com / *.baidu.jp */
const CG_CRAWLER_RDNS = [
    'googlebot'   => ['googlebot.com'],
    'bingbot'     => ['search.msn.com'],
    'baiduspider' => ['baidu.com', 'baidu.jp'],
    'sogou'       => ['sogou.com'],
    'bytespider'  => ['crawl.bytedance.com'],
    'yisouspider' => ['yisou.com'],
    '360'         => ['360.cn', 'so.com'],
    'applebot'    => ['applebot.apple.com'],
    'yandex'      => ['yandex.com', 'yandex.ru', 'yandex.net'],
];

/** 走官方 IP 段的家族 => 段集合键名 */
const CG_CRAWLER_RANGES = [
    'googlebot'       => 'google',
    'oai-searchbot'   => 'openai-searchbot',
    'gptbot'          => 'openai-gptbot',
    'chatgpt-user'    => 'openai-chatgpt-user',
    'perplexitybot'   => 'perplexitybot',
    'perplexity-user' => 'perplexity-user',
    'claudebot'       => 'anthropic',
    'claude-user'     => 'anthropic',
    'claude-searchbot'=> 'anthropic',
];

function crawler_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $all = function_exists('cfg') ? cfg() : [];
        $cfg = $all['crawler'] ?? [];
    }
    return $cfg;
}

function crawler_enabled(): bool
{
    return (bool) (crawler_cfg()['enable'] ?? true);
}

/** UA => 家族名；不是已知爬虫返回 null */
function crawler_family(string $ua): ?string
{
    if ($ua === '') {
        return null;
    }
    $u = strtolower($ua);
    foreach (CG_CRAWLER_UA as $needle => $family) {
        if (strpos($u, $needle) !== false) {
            return $family;
        }
    }
    return null;
}

function crawler_cfg_path(string $key, string $default): string
{
    $p = (string) (crawler_cfg()[$key] ?? '');
    return $p !== '' ? $p : $default;
}

/* ───────────── 验证结果缓存（避免每个请求都做 DNS） ───────────── */

function crawler_verify_cache_file(): string
{
    return crawler_cfg_path('verify_cache', '/var/log/cgtk/crawler-verify.json');
}

function crawler_verify_cached(string $family, string $ip, callable $fn): bool
{
    $file = crawler_verify_cache_file();
    $key  = $ip . '|' . $family;
    $ttl  = max(600, (int) (crawler_cfg()['cache_ttl'] ?? 21600));
    $now  = time();
    $data = [];
    if (@is_readable($file)) {
        $raw = @file_get_contents($file);
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $data = $j;
            }
        }
    }
    if (isset($data[$key]) && is_array($data[$key])) {
        $exp = (int) ($data[$key]['exp'] ?? 0);
        if ($exp > $now) {
            return (bool) ($data[$key]['ok'] ?? false);
        }
    }
    $ok = (bool) $fn();
    $data[$key] = ['ok' => $ok, 'exp' => $now + $ttl];
    // 简单清理，避免缓存文件无限增长
    if (count($data) > 3000) {
        $cut = $now;
        foreach ($data as $k => $v) {
            if (!is_array($v) || (int) ($v['exp'] ?? 0) < $cut) {
                unset($data[$k]);
            }
        }
        $data = array_slice($data, -2000, null, true);
    }
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $ok;
}

/* ───────────── 反向 DNS 正反查 ───────────── */

function crawler_rdns_match(string $host, array $suffixes): bool
{
    $host = strtolower(rtrim($host, '.'));
    foreach ($suffixes as $suffix) {
        $suffix = strtolower($suffix);
        if ($host === $suffix || substr($host, -strlen($suffix) - 1) === '.' . $suffix) {
            return true;
        }
    }
    return false;
}

/**
 * 正向确认：返回 true=有记录且包含该 IP；false=有记录但都不匹配；
 * null=查不到任何 A/AAAA 记录（部分官方抓取主机名没有公网正向记录，
 * 此时以 rDNS 为准——PTR 前缀域名的解析权在对方手里，伪造不了）。
 */
function crawler_forward_confirm(string $host, string $ip): ?bool
{
    $found = false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $recs = @dns_get_record($host, DNS_A);
        $a = @gethostbyname($host);
        if (is_string($a) && $a !== $host && filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $found = true;
            if (strcasecmp($a, $ip) === 0) {
                return true;
            }
        }
        foreach ((array) $recs as $r) {
            $found = true;
            if (isset($r['ip']) && strcasecmp((string) $r['ip'], $ip) === 0) {
                return true;
            }
        }
        return $found ? false : null;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $recs = @dns_get_record($host, DNS_AAAA);
        foreach ((array) $recs as $r) {
            $found = true;
            if (isset($r['ipv6']) && strcasecmp((string) $r['ipv6'], $ip) === 0) {
                return true;
            }
        }
        return $found ? false : null;
    }
    return false;
}

function crawler_rdns_verify(string $ip, array $suffixes): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    $host = @gethostbyaddr($ip);
    if (!is_string($host) || $host === '' || $host === $ip) {
        return false;
    }
    if (!crawler_rdns_match($host, $suffixes)) {
        return false;
    }
    // 有正向记录时必须匹配；完全查不到正向记录时以 rDNS 为准
    return crawler_forward_confirm($host, $ip) !== false;
}

/* ───────────── 官方 IP 段 ───────────── */

function crawler_ranges_builtin(): array
{
    $file = __DIR__ . '/crawler-ranges.json';
    $raw  = @file_get_contents($file);
    $j    = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}

function crawler_fetch_json(string $url): ?array
{
    $timeout = 5;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'cgtk-crawler-ranges/1.0 (+internal)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $code === 200) {
            $j = json_decode($body, true);
            return is_array($j) ? $j : null;
        }
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'cgtk-crawler-ranges/1.0']]);
    $body = @file_get_contents($url, false, $ctx);
    $j = is_string($body) ? json_decode($body, true) : null;
    return is_array($j) ? $j : null;
}

function crawler_prefixes(?array $json): array
{
    $out = [];
    foreach ((array) ($json['prefixes'] ?? []) as $p) {
        if (!empty($p['ipv4Prefix'])) {
            $out[] = (string) $p['ipv4Prefix'];
        }
        if (!empty($p['ipv6Prefix'])) {
            $out[] = (string) $p['ipv6Prefix'];
        }
    }
    return $out;
}

/**
 * 拉取官方 IP 段并合并。失败时保留旧数据（内置快照兜底）。
 */
function crawler_fetch_ranges(): ?array
{
    $sources = [
        'google'             => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        'openai-searchbot'   => 'https://openai.com/searchbot.json',
        'openai-gptbot'      => 'https://openai.com/gptbot.json',
        'openai-chatgpt-user'=> 'https://openai.com/chatgpt-user.json',
        'perplexitybot'      => 'https://www.perplexity.ai/perplexitybot.json',
        'perplexity-user'    => 'https://www.perplexity.ai/perplexity-user.json',
    ];
    $data = crawler_ranges_builtin();
    $okAny = false;
    foreach ($sources as $key => $url) {
        $prefixes = crawler_prefixes(crawler_fetch_json($url));
        if ($prefixes) {
            $data[$key] = $prefixes;
            $okAny = true;
        }
    }
    if (!$okAny) {
        return null;
    }
    // Anthropic 只提供文档（无 JSON），用官方出网段常量
    $data['anthropic'] = ['160.79.104.0/21'];
    $data['_comment'] = '官方公布的爬虫 IP 段; 由 lib/crawler.php 自动刷新';
    return $data;
}

function crawler_ranges(): array
{
    static $ranges = null;
    if ($ranges !== null) {
        return $ranges;
    }
    $cache = crawler_cfg_path('range_cache', '/var/log/cgtk/crawler-ranges.json');
    $ttl   = max(3600, (int) (crawler_cfg()['ranges_ttl'] ?? 86400));
    $data  = null;
    $stale = true;
    if (@is_readable($cache)) {
        $raw = @file_get_contents($cache);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($j)) {
            $data  = $j;
            $stale = (time() - (int) @filemtime($cache)) > $ttl;
        }
    }
    if ($data === null || $stale) {
        $fresh = crawler_fetch_ranges();
        if (is_array($fresh)) {
            @file_put_contents($cache, json_encode($fresh, JSON_UNESCAPED_SLASHES), LOCK_EX);
            $data = $fresh;
        }
    }
    if (!is_array($data)) {
        $data = crawler_ranges_builtin();
    }
    return $ranges = $data;
}

function crawler_ip_in_cidrs(string $ip, array $cidrs): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($cidrs as $cidr) {
        $cidr = trim((string) $cidr);
        if ($cidr === '') {
            continue;
        }
        $parts = explode('/', $cidr, 2);
        $netBin = @inet_pton($parts[0]);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            continue;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : (strlen($bin) * 8);
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            continue;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr(0xFF << (8 - $rem));
        if ((ord($bin[$bytes]) & ord($mask)) === (ord($netBin[$bytes]) & ord($mask))) {
            return true;
        }
    }
    return false;
}

/* ───────────── 对外：验证 + 白名单 + 日志 ───────────── */

function crawler_verified(string $family, string $ip): bool
{
    return crawler_verify_cached($family, $ip, static function () use ($family, $ip): bool {
        if (isset(CG_CRAWLER_RDNS[$family])
            && crawler_rdns_verify($ip, CG_CRAWLER_RDNS[$family])) {
            return true;
        }
        $rangeKey = CG_CRAWLER_RANGES[$family] ?? null;
        if ($rangeKey !== null) {
            $all = crawler_ranges();
            $cidrs = (array) ($all[$rangeKey] ?? []);
            if ($cidrs && crawler_ip_in_cidrs($ip, $cidrs)) {
                return true;
            }
        }
        return false;
    });
}

/** 公开页面白名单：只有这些路径允许验证过的爬虫读取 */
function crawler_public_path_allowed(string $path): bool
{
    $path = '/' . ltrim(strtok($path, '?') ?: '/', '/');
    $exact = ['/', '/about', '/feedback', '/guide', '/review', '/robots.txt', '/sitemap.xml', '/llms.txt'];
    if (in_array($path, $exact, true)) {
        return true;
    }
    return strpos($path, '/assets/') === 0;
}

function crawler_log(string $family, string $ip, string $path, string $method): void
{
    $file = crawler_cfg_path('log', '/var/log/cgtk/crawler.log');
    $line = sprintf(
        "%s\t%s\t%s\t%s\t%s\t%s\n",
        date('c'),
        $family,
        $method,
        $ip,
        strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $path
    );
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * 当前请求是否可享受“公开页面”爬虫放行。
 * 只有：已知 UA + 验证通过 + 公开路径 + GET/HEAD 时才返回 true。
 */
function crawler_public_bypass(): bool
{
    if (!crawler_enabled()) {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $family = crawler_family($ua);
    if ($family === null) {
        return false;
    }
    $path = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if (!crawler_public_path_allowed($path)) {
        return false;
    }
    $ip = function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '' || !crawler_verified($family, $ip)) {
        return false;
    }
    $how = isset(CG_CRAWLER_RDNS[$family]) ? 'rdns' : 'iprange';
    crawler_log($family, $ip, strtok($path, '?') ?: '/', $how);
    return true;
}
