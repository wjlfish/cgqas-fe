<?php
/** 管理鉴权: 用 config.admin_key 作为口令, HMAC 签名 cookie */
require_once __DIR__ . '/config.php';

function admin_secret(): string
{
    $c = require __DIR__ . '/config.php';
    return $c['secret'];
}

function admin_cookie_value(): string
{
    return hash_hmac('sha256', 'cgtk-admin-v1', admin_secret());
}

function admin_logged_in(): bool
{
    $got = $_COOKIE['cg_admin'] ?? '';
    return $got !== '' && hash_equals(admin_cookie_value(), $got);
}

function admin_login(string $pass): bool
{
    $c = require __DIR__ . '/config.php';
    if ($pass === '' || !hash_equals((string) $c['admin_key'], $pass)) {
        return false;
    }
    setcookie('cg_admin', admin_cookie_value(), [
        'expires'  => time() + 7 * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
    ]);
    return true;
}

function admin_logout(): void
{
    setcookie('cg_admin', '', ['expires' => time() - 3600, 'path' => '/']);
}
