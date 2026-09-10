<?php

declare(strict_types=1);

namespace App\View;

use App\Core\Csrf;

final class Layout
{
    public static function render(string $title, string $content, string $variant = 'customer'): string
    {
        $isAdmin = $variant === 'admin';
        $nav = $isAdmin
            ? '<a href="/admin/dashboard">داشبورد</a><a href="/admin/customers">مشتریان</a><a href="/admin/settings">تنظیمات</a><a href="/admin/logout">خروج</a>'
            : '<a href="/dashboard">مصرف</a><a href="/logout">خروج</a>';

        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{$t}</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app {$variant}">
<header class="topbar">
    <div class="brand">پنل VPN</div>
    <nav class="nav">{$nav}</nav>
</header>
<main class="container">
{$content}
</main>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
HTML;
    }

    public static function card(string $body, ?string $title = null): string
    {
        $h = $title ? '<h2 class="card-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>' : '';
        return '<section class="card">' . $h . $body . '</section>';
    }

    public static function loginPage(string $title, string $action, string $variant): string
    {
        $csrf = Csrf::field();
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$t}</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login {$variant}">
<div class="login-box">
    <h1>{$t}</h1>
    <form method="post" action="{$action}">
        {$csrf}
        <label>نام کاربری</label>
        <input type="text" name="username" required autocomplete="username">
        <label>رمز عبور</label>
        <input type="password" name="password" required autocomplete="current-password">
        <button type="submit" class="btn primary">ورود</button>
    </form>
</div>
</body>
</html>
HTML;
    }
}
