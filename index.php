<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ratelimit.php';

// 访问日志(保留原表, 供统计使用)
$ip    = client_ip();
$ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
$dev   = preg_match('/mobile|android|iphone|ipad|phone/i', $ua) ? 'Mobile'
       : (preg_match('/tablet|ipad/i', $ua) ? 'Tablet' : 'Desktop');
$model = '';
if ($dev !== 'Desktop' && preg_match('/\(([^)]{1,60})\)/', $ua, $m)) {
    $model = $m[1];
}
try {
    db()->prepare(
        "INSERT INTO access_logs (ip, user_agent, device_type, device_model, hostname, created_at)
         VALUES (?,?,?,?,?,NOW())"
    )->execute([substr($ip, 0, 250), substr($ua, 0, 2000), $dev, substr($model, 0, 200), null]);
} catch (Throwable $e) {
    // 日志失败不影响页面
}

$cfgAll = require __DIR__ . '/lib/config.php';
$cap2   = $cfgAll['captcha2'] ?? [];
$cap2On = !empty($cap2['enable']) && !empty($cap2['scene_id']);

$t   = issue_token($ip);
$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="paper">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= $esc($t['token']) ?>">
<meta name="theme-color" content="#F5F3ED">
<title>题库检索 · 创高体育理论考试</title>
<meta name="description" content="四川大学创高体育理论考试题库检索：按关键词、科目、题型查找题目与标准答案">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' fill='%23F5F3ED'/><text x='16' y='23' font-size='18' text-anchor='middle' fill='%23B03A2E' font-family='serif'>题</text></svg>">
<link rel="stylesheet" href="/assets/style.css?v=4">
<?php if ($cap2On): ?>
<script>
  window.AliyunCaptchaConfig = {
    region: <?= json_encode((string) ($cap2['region'] ?? 'cn')) ?>,
    prefix: <?= json_encode((string) $cap2['prefix']) ?>
  };
  window.__CG_CAPTCHA2 = {
    sceneId: <?= json_encode((string) $cap2['scene_id']) ?>,
    prefix: <?= json_encode((string) $cap2['prefix']) ?>
  };
</script>
<script src="https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js"></script>
<?php endif; ?>
<script>
  (function(){
    try{
      var t = localStorage.getItem('cgtk-theme');
      if (t === 'ink' || t === 'paper') document.documentElement.dataset.theme = t;
      else if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.dataset.theme = 'ink';
    }catch(e){}
  })();
</script>
</head>
<body>
<div class="scroll-line" id="scrollLine" aria-hidden="true"></div>

<!-- ══ 顶栏 ══ -->
<header class="topbar">
  <div class="shell topbar-in">
    <a class="brand" href="/" aria-label="创高题库">
      <span>创高题库</span><em>SCU</em>
    </a>
    <nav class="nav" aria-label="主导航">
      <button class="nav-link is-active" data-tab="search">检索</button>
      <button class="nav-link" data-tab="stats">统计</button>
    </nav>
    <span class="pill" id="topCount">—</span>
    <span class="pill quota" id="quotaPill" hidden>剩余 —</span>
    <button class="login-btn" id="loginBtn" type="button">手机号登录</button>
    <span class="user-pill" id="userPill" hidden>
      <span id="userPhone">—</span>
      <button class="logout-btn" id="logoutBtn" type="button">退出</button>
    </span>
    <button class="theme-btn" id="themeBtn" title="切换主题" aria-label="切换主题">
      <svg class="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4.2"></circle>
        <path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6"></path>
      </svg>
      <svg class="ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20.5 14.3A8.6 8.6 0 1 1 9.7 3.5a6.9 6.9 0 0 0 10.8 10.8z"></path>
      </svg>
    </button>
  </div>
</header>

<!-- ══ 工具栏 (吸顶) ══ -->
<div class="toolbar">
  <div class="shell toolbar-in">
    <form class="search" id="search-form" role="search" autocomplete="off">
      <svg class="sicon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
        <circle cx="11" cy="11" r="6.4"></circle><path d="M16 16l4.2 4.2"></path>
      </svg>
      <input id="q" name="q" type="search" maxlength="40" enterkeyhint="search"
             placeholder="搜索题干或答案，例如：拦网、奥林匹克精神">
      <kbd class="kbd">/</kbd>
      <button class="go" type="submit">检索</button>
    </form>

    <label class="sel">
      <span>科目</span>
      <select id="subject"><option value="">全部科目</option></select>
    </label>
    <label class="sel">
      <span>题型</span>
      <select id="type">
        <option value="">全部题型</option>
        <option>判断</option><option>单选</option>
        <option>多选</option><option>填空</option><option>问答</option>
      </select>
    </label>
    <button class="reset-btn" id="reset" title="清除筛选">重置</button>
  </div>
</div>

<!-- ══ 检索 ══ -->
<main class="view is-active" id="view-search">
  <div class="shell">
    <div class="list-head">
      <span class="count" id="meta">正在加载…</span>
      <span class="count" id="pagePos"></span>
    </div>
    <ol class="results" id="list" aria-live="polite"></ol>
    <nav class="pager" id="pager" aria-label="分页"></nav>
  </div>
</main>

<!-- ══ 统计 ══ -->
<main class="view" id="view-stats">
  <div class="shell">
    <div class="view-actions">
      <h1 class="view-title">题库统计</h1>
    </div>
    <div class="stat-grid" id="statGrid"></div>
    <h2 class="sec-title">题量最大的科目</h2>
    <div class="bars" id="statBars"></div>
  </div>
</main>

<footer class="foot">
  <div class="shell foot-in">
    <p class="foot-meta">
      <span>题库总计 <b id="footTotal">—</b> 题</span>
      <span class="dot">·</span>
      <span>最后更新 <b id="footUpdated">—</b></span>
    </p>
    <p class="foot-sign">
      <a href="https://github.com/wjlfish" target="_blank" rel="noopener noreferrer">初音过去<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"></path></svg></a>
    </p>
  </div>
</footer>

<div class="modal" id="loginModal" hidden>
  <div class="modal-mask" data-close="1"></div>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
    <button class="modal-close" data-close="1" aria-label="关闭">×</button>
    <h2 id="loginTitle">手机号验证</h2>
    <p class="modal-sub">未注册的手机号将自动创建账号</p>
    <div class="modal-field">
      <span class="pre">+86</span>
      <input id="loginPhone" type="tel" inputmode="numeric" maxlength="11"
             placeholder="请输入手机号" autocomplete="tel">
    </div>
    <div class="modal-field">
      <input id="loginCode" type="text" inputmode="numeric" maxlength="6"
             placeholder="6 位验证码" autocomplete="one-time-code">
      <button class="sms-btn" id="smsBtn" type="button">获取验证码</button>
    </div>
    <div id="captcha-element"></div>
    <button class="go solid full" id="loginSubmit" type="button">登录 / 注册</button>
    <p class="modal-hint" id="loginHint">每个账号可搜题 150 次</p>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="/assets/app.js?v=4"></script>
</body>
</html>
