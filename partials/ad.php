<?php
/** 顶部广告位 · 直接访问该文件时不做任何输出 */
if (!defined('CG_SITE')) {
    http_response_code(404);
    exit;
}

// 落地页: 注册页比登录页转化好得多(登录页对新用户是死路)
// 拿到推广码后形如: https://oc.qiyun.site/register?aff=你的推广码
$AD_URL   = 'https://oc.qiyun.site/register';
$AD_LABEL = 'api.qiyun.site';
?>
<a class="adtop" href="<?= htmlspecialchars($AD_URL, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer nofollow sponsored"
   aria-label="广告：企云 API 中转站">
  <span class="adtop-tag">广告</span>
  <span class="adtop-main">Claude 老封号？GPT 订阅贵还降智？</span>
  <span class="adtop-sub">中转站国内直连：不用梯子，一个 Key 用遍 DeepSeek / GPT-6 Astra / Claude CC Max</span>
  <span class="adtop-go"><?= htmlspecialchars($AD_LABEL, ENT_QUOTES) ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"></path></svg></span>
</a>
