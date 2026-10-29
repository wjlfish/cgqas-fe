<?php
/** 顶部广告位 · 直接访问该文件时不做任何输出 */
if (!defined('CG_SITE')) {
    http_response_code(404);
    exit;
}

// 每块广告: key 对应 go.php 的 AD_TARGETS
$ADS = [
    [
        'to'   => 'qiyun',
        'main' => 'Claude 老封号？GPT 订阅贵还降智？',
        'sub'  => '中转站国内直连 · 一个 Key 用遍 DeepSeek / GPT-6 Astra / Claude Fable / Opus',
    ],
    [
        'to'   => 'skyrouter',
        'main' => '同样的模型，花更少的钱',
        'sub'  => 'skyrouter · 国内直连 · 一个 Key 通用主流模型 · 按量计费不浪费',
    ],
];
?>
<div class="adgrid">
  <?php foreach ($ADS as $ad): ?>
    <a class="adcard" href="/go.php?to=<?= htmlspecialchars($ad['to'], ENT_QUOTES) ?>"
       target="_blank" rel="noopener noreferrer nofollow sponsored">
      <span class="adcard-top">
        <span class="adcard-tag">广告</span>
        <span class="adcard-main"><?= htmlspecialchars($ad['main'], ENT_QUOTES) ?></span>
        <span class="adcard-cta">免费注册
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12h14M13 6l6 6-6 6"></path>
          </svg>
        </span>
      </span>
      <span class="adcard-sub"><?= htmlspecialchars($ad['sub'], ENT_QUOTES) ?></span>
    </a>
  <?php endforeach; ?>
</div>
