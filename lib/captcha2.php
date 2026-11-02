<?php
/**
 * 阿里云「验证码 2.0」服务端校验
 *
 * 前端通过 AliyunCaptcha.js 拿到 captchaVerifyParam, 服务端调用
 * VerifyIntelligentCaptcha 校验。只用于短信发送前置, 不影响正常检索。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sms.php';

function captcha2_cfg(): array
{
    $c = require __DIR__ . '/config.php';
    return $c['captcha2'] ?? [];
}

function captcha2_enabled(): bool
{
    $c = captcha2_cfg();
    return !empty($c['enable']) && !empty($c['scene_id']) && !empty($c['prefix']);
}

/**
 * 校验前端回传的 captchaVerifyParam
 * 返回 [ok(bool), message]
 */
function captcha2_verify(string $verifyParam): array
{
    $c = captcha2_cfg();
    if (!captcha2_enabled()) {
        return [true, 'disabled'];
    }
    if ($verifyParam === '') {
        return [false, '缺少验证参数'];
    }

    $r = aliyun_rpc(
        (string) ($c['endpoint'] ?? 'https://captcha.cn-shanghai.aliyuncs.com/'),
        'VerifyIntelligentCaptcha',
        [
            'SceneId'           => (string) $c['scene_id'],
            'CaptchaVerifyParam'=> $verifyParam,
        ],
        '2023-03-05'
    );

    // 阿里云该接口成功时 Code 可能是 OK 或 Success
    $code = strtoupper((string) ($r['code'] ?? ''));
    if (!in_array($code, ['OK', 'SUCCESS'], true)) {
        return [false, '验证服务异常(' . ($r['code'] ?: 'unknown') . ')'];
    }
    $raw = $r['raw'] ?? [];
    $result = $raw['Result'] ?? [];
    // 成功时 Result.VerifyResult 为 true
    $passed = ($result['VerifyResult'] ?? ($raw['VerifyResult'] ?? null));
    if ($passed === true || $passed === 'true') {
        return [true, ''];
    }
    return [false, '人机验证未通过'];
}
