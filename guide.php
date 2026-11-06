<?php
declare(strict_types=1);
/**
 * 公开说明页：题库使用指南
 * 只讲现有功能与规则，不写任何爬虫/风控实现细节。
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ratelimit.php';

define('CG_SITE', true);

require_once __DIR__ . '/lib/geo.php';
require_once __DIR__ . '/lib/crawler.php';
require_once __DIR__ . '/lib/asset.php';

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

if (geo_blocked() && !crawler_public_bypass()) {
    geo_blocked_page();
}

$ip  = client_ip();
$t   = issue_token($ip);
$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN" data-theme="paper">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="csrf-token" content="<?= $esc($t['token']) ?>">
<meta name="theme-color" content="#F5F3ED">
<title>题库使用指南 · 创高体育理论考试题库怎么用</title>
<meta name="description" content="创高题库使用指南：手机号验证码登录、按题干或答案关键词检索、选择自己的体育课科目、查看剩余检索次数、错题申报与提额申请的完整说明。">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://cgtk.org/guide">
<link rel="icon" href="/assets/favicon.ico" sizes="any">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "FAQPage",
      "@id": "https://cgtk.org/guide#faq",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "使用创高题库需要注册吗？",
          "acceptedAnswer": { "@type": "Answer", "text": "需要登录。用手机号接收验证码即可，未注册的手机号会自动创建账号，登录状态可保持 7 天。" }
        },
        {
          "@type": "Question",
          "name": "检索关键词有什么要求？",
          "acceptedAnswer": { "@type": "Answer", "text": "关键词至少 2 个字，可以填题干或答案里的内容；还可以叠加科目和题型筛选，缩小结果范围。" }
        },
        {
          "@type": "Question",
          "name": "为什么检索结果和我的体育课科目有关？",
          "acceptedAnswer": { "@type": "Answer", "text": "首次进入时可以选自己的体育课科目，检索范围是公共题库加上你选的科目；科目可以更换，有次数限制。" }
        },
        {
          "@type": "Question",
          "name": "检索次数用完怎么办？",
          "acceptedAnswer": { "@type": "Answer", "text": "每个账号初始有 110 次检索额度。用完可以在反馈页提交提额申请，说明用途后由后台人工审批。" }
        },
        {
          "@type": "Question",
          "name": "发现题目或答案有错怎么反馈？",
          "acceptedAnswer": { "@type": "Answer", "text": "登录后可在「反馈」中提交错题申报；核实并修正后，会返还本次检索消耗的额度。" }
        }
      ]
    },
    {
      "@type": "Article",
      "@id": "https://cgtk.org/guide#article",
      "headline": "创高题库使用指南",
      "about": "创高体育理论考试题库的检索方法与使用规则",
      "inLanguage": "zh-CN",
      "mainEntityOfPage": "https://cgtk.org/guide"
    },
    {
      "@type": "BreadcrumbList",
      "@id": "https://cgtk.org/guide#breadcrumb",
      "itemListElement": [
        {"@type": "ListItem", "position": 1, "name": "首页", "item": "https://cgtk.org/"},
        {"@type": "ListItem", "position": 2, "name": "使用指南", "item": "https://cgtk.org/guide"}
      ]
    }
  ]
}
</script>
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
<header class="topbar">
  <div class="shell topbar-in">
    <a class="brand" href="/" aria-label="创高题库"><span>创高题库</span></a>
    <nav class="nav" aria-label="主导航">
      <a class="nav-link nav-link--ext" href="/">检索</a>
      <a class="nav-link is-active" href="/guide" aria-current="page">指南</a>
      <a class="nav-link nav-link--ext" href="/review">复习</a>
      <a class="nav-link nav-link--ext" href="/about">关于</a>
    </nav>
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

<main class="fb-page">
  <div class="shell">
    <nav class="crumb" aria-label="面包屑">
      <a href="/">首页</a><span class="sep">/</span><span aria-current="page">使用指南</span>
    </nav>
    <div class="view-actions">
      <h1 class="view-title">题库使用指南</h1>
    </div>

    <section class="about about--page">
      <p class="about-lead">创高题库是一个面向高校学生的创高体育理论考试题库检索工具。下面按实际使用顺序说明：登录、检索、科目范围、额度与反馈。</p>

      <div class="about-grid">
        <div>
          <h2 class="about-h2">1. 登录</h2>
          <p>用手机号接收短信验证码登录。未注册的手机号会自动创建账号，不需要单独注册。登录状态在同一浏览器内保持 7 天，过期后重新获取验证码即可。</p>
        </div>
        <div>
          <h2 class="about-h2">2. 检索</h2>
          <p>在检索框输入题干或答案里的关键词，至少 2 个字。可以再叠加「科目」「题型」筛选。检索结果会显示题目、题型与标准答案，支持分页浏览。</p>
        </div>
        <div>
          <h2 class="about-h2">3. 选择科目</h2>
          <p>首次进入时会让你选择自己的体育课科目，检索范围是公共题库加上你选的科目。选错了可以更换，但次数有限，建议先确认自己的课程名称。</p>
        </div>
        <div>
          <h2 class="about-h2">4. 检索额度</h2>
          <p>每个账号初始有 110 次检索额度，每检索一次消耗一次。额度用完后，可在反馈页提交提额申请并说明用途，后台会人工审批。</p>
        </div>
        <div>
          <h2 class="about-h2">5. 反馈与纠错</h2>
          <p>登录后可以提交「错题申报」：指出题目或答案的问题，核实修正后会返还本次检索额度。Bug 反馈和点赞留言无需登录，但需要完成人机验证。</p>
        </div>
        <div>
          <h2 class="about-h2">6. 使用须知</h2>
          <p>题库与答案仅供平时复习参考，请勿在正式考试中使用。本站是个人维护的非官方工具，与创高体育官方无关；涉及考试范围与评分，请以学校和老师的要求为准。</p>
        </div>
      </div>

      <h2 class="about-faq-title">常见问题</h2>
      <dl class="about-faq">
        <dt>搜不到想要的题怎么办？</dt>
        <dd>先缩短关键词，只保留题干里最关键的 2—4 个字；再确认所选科目是否包含这道题。公共题库的题对所有科目都可见，其他科目的题不会出现在你的检索范围里。</dd>
        <dt>换手机或换浏览器后要重新登录吗？</dt>
        <dd>登录状态保存在浏览器 Cookie 里，换设备或清除浏览器数据后需要重新用手机号验证码登录，账号里的额度不会被清空。</dd>
        <dt>为什么检索要限制关键词长度和翻页？</dt>
        <dd>这些限制是为了防止题库被批量抓取，保护站点能持续正常使用。正常按关键词复习不受影响。</dd>
        <dt>题目答案一定正确吗？</dt>
        <dd>题库来自公开资料与同学反馈，可能存在少数录入错误。发现错误请用错题申报反馈，核实后会修正。</dd>
        <dt>需要付费吗？</dt>
        <dd>不需要，本站免费使用。搜索过程中也不会要求付费，遇到收费提示请勿相信。</dd>
      </dl>

      <p class="foot-meta" style="margin-top:26px">
        继续阅读：<a href="/review">创高体育理论考试怎么复习</a> ·
        <a href="/about">关于本站</a> ·
        <a href="/feedback">错题申报与提额</a>
      </p>
    </section>
  </div>
</main>

<footer class="foot">
  <div class="shell foot-in">
    <p class="foot-meta"><span>最后更新 <b>2026-11-06</b></span></p>
    <p class="foot-sign">
      <a href="/">检索</a>
      <span class="dot">·</span>
      <a href="/review">复习建议</a>
      <span class="dot">·</span>
      <a href="/feedback">问题反馈</a>
    </p>
  </div>
</footer>

<script>
(function () {
  var root = document.documentElement;
  var btn = document.getElementById('themeBtn');
  if (btn) btn.addEventListener('click', function () {
    var next = root.dataset.theme === 'ink' ? 'paper' : 'ink';
    root.dataset.theme = next;
    try { localStorage.setItem('cgtk-theme', next); } catch (e) {}
  });
})();
</script>
</body>
</html>
