<?php
/** 顶部广告位 · 直接访问该文件时不做任何输出 */
if (!defined('CG_SITE')) {
    http_response_code(404);
    exit;
}

// 经 /go.php 跳转, 便于统计点击(同一 IP 10 分钟只记一次)
// 推广码 XTCF2MLXZHZB (需站主开启 affiliate_enabled 才会记录归属)
$AD_URL   = 'https://oc.qiyun.site/register?aff=XTCF2MLXZHZB';
$AD_LABEL = 'api.qiyun.site';
?>
<div class="adtop-wrap">
  <div class="adtop">
    <a class="adtop-body" href="/go.php?to=qiyun" target="_blank"
       rel="noopener noreferrer nofollow sponsored">
      <span class="adtop-line1">
        <span class="adtop-tag">广告</span>
        <span class="adtop-main">Claude 老封号？GPT 订阅贵还降智？</span>
      </span>
      <span class="adtop-sub">中转站国内直连 · 一个 Key 用遍 <b>DeepSeek</b> / <b>GPT-6 Astra</b> / <b>Claude Fable / Opus</b> · 按量付费</span>
    </a>
    <a class="adtop-cta" href="/go.php?to=qiyun" target="_blank"
       rel="noopener noreferrer nofollow sponsored">
      免费注册<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
    </a>
  </div>
</div>
