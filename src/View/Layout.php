<?php

declare(strict_types=1);

namespace App\View;

use App\Core\Csrf;

final class Layout
{
    private const CRITICAL_CSS = '<style>body.theme-jaysub{margin:0;background:#0b1020;color:#eef2ff;font-family:Vazirmatn,Tahoma,sans-serif}.admin-mobile-nav{display:none}.sidebar-link{text-decoration:none;color:inherit}@media(max-width:900px){.admin-mobile-nav{display:flex;overflow-x:auto}}</style>';

    /** @var array<string, string> */
    private const ADMIN_NAV = [
        'dashboard' => 'داشبورد',
        'users' => 'کاربران',
        'subscriptions' => 'اشتراک‌ها',
        'sales' => 'فروش',
        'payments' => 'پرداخت‌ها',
        'messages' => 'پیام‌ها',
        'reports' => 'گزارش‌ها',
        'settings' => 'تنظیمات',
    ];

    /** @var array<string, array{href: string, label: string, icon: string}> */
    private const CUSTOMER_NAV = [
        'home' => ['href' => '/dashboard', 'label' => 'داشبورد', 'icon' => '⌂'],
        'subscription' => ['href' => '/app/subscription', 'label' => 'اشتراک', 'icon' => '◈'],
        'messages' => ['href' => '/app/messages', 'label' => 'پیام‌ها', 'icon' => '✉'],
        'more' => ['href' => '/app/more', 'label' => 'بیشتر', 'icon' => '☰'],
    ];

    public static function admin(string $title, string $active, string $content): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $navHtml = '';
        $paths = [
            'dashboard' => '/admin/dashboard',
            'users' => '/admin/customers',
            'subscriptions' => '/admin/subscriptions',
            'sales' => '/admin/sales',
            'payments' => '/admin/payments',
            'messages' => '/admin/messages',
            'reports' => '/admin/reports',
            'settings' => '/admin/settings',
        ];
        foreach (self::ADMIN_NAV as $key => $label) {
            $href = $paths[$key] ?? '#';
            $cls = $key === $active ? 'active' : '';
            $navHtml .= '<a class="sidebar-link ' . $cls . '" href="' . $href . '"><span class="ico">' . self::adminIcon($key) . '</span>' . $label . '</a>';
        }
        $mobileNav = '';
        foreach (self::ADMIN_NAV as $key => $label) {
            $href = $paths[$key] ?? '#';
            $cls = $key === $active ? 'active' : '';
            $mobileNav .= '<a class="' . $cls . '" href="' . $href . '">' . $label . '</a>';
        }

        $criticalCss = self::CRITICAL_CSS;
        $cssHref = Assets::url('css/app.css');
        $jsSrc = Assets::url('js/app.js');

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{$t} | JaySub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
    {$criticalCss}
    <link rel="stylesheet" href="{$cssHref}">
</head>
<body class="theme-jaysub admin-app">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="sidebar-brand">JaySub <span>ادمین</span></div>
        <nav class="sidebar-nav">{$navHtml}</nav>
        <a class="sidebar-logout" href="/admin/logout">خروج</a>
    </aside>
    <div class="admin-main">
        <header class="admin-top">
            <button type="button" class="menu-toggle" aria-label="منو" onclick="document.body.classList.toggle('sidebar-open')">☰</button>
            <h1 class="page-title">{$t}</h1>
        </header>
        <div class="admin-mobile-nav">{$mobileNav}</div>
        <main class="admin-content">{$content}</main>
    </div>
</div>
<script src="{$jsSrc}" defer></script>
</body>
</html>
HTML;
    }

    public static function customer(string $title, string $activeNav, string $content): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $bottom = '';
        foreach (self::CUSTOMER_NAV as $key => $item) {
            $cls = $key === $activeNav ? 'active' : '';
            $bottom .= '<a class="bottom-nav-item ' . $cls . '" href="' . $item['href'] . '"><span class="bn-icon">' . $item['icon'] . '</span><span>' . $item['label'] . '</span></a>';
        }

        $criticalCss = self::CRITICAL_CSS;
        $cssHref = Assets::url('css/app.css');
        $jsSrc = Assets::url('js/app.js');

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{$t}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
    {$criticalCss}
    <link rel="stylesheet" href="{$cssHref}">
</head>
<body class="theme-jaysub customer-app has-bottom-nav">
<main class="customer-main">{$content}</main>
<nav class="bottom-nav">{$bottom}</nav>
<script src="{$jsSrc}" defer></script>
</body>
</html>
HTML;
    }

    public static function card(string $body, ?string $title = null, string $extraClass = ''): string
    {
        $h = $title ? '<h2 class="card-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>' : '';
        return '<section class="ui-card ' . $extraClass . '">' . $h . $body . '</section>';
    }

    public static function statGrid(string $html): string
    {
        return '<div class="stat-grid">' . $html . '</div>';
    }

    public static function statCard(string $label, string $value, string $tone = ''): string
    {
        return '<div class="stat-card ' . $tone . '"><div class="stat-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div><div class="stat-value">' . $value . '</div></div>';
    }

    /** @param list<array<string, string>> $rows */
    public static function responsiveTable(array $headers, array $rows, string $mobileTitleKey = 'name'): string
    {
        $thead = '';
        foreach ($headers as $h) {
            $thead .= '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $tbody = '';
        $cards = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($row as $cell) {
                $tbody .= '<td>' . $cell . '</td>';
            }
            $tbody .= '</tr>';
            $title = $row[$mobileTitleKey] ?? ($row[0] ?? '');
            $cards .= '<article class="data-card">' . $title;
            $i = 0;
            foreach ($headers as $h) {
                if ($i === (int) $mobileTitleKey && is_numeric($mobileTitleKey)) {
                    ++$i;
                    continue;
                }
                $key = is_string($mobileTitleKey) ? $mobileTitleKey : (string) $i;
                if (isset($row[$key]) && $key !== $mobileTitleKey) {
                    $cards .= '<div class="data-card-row"><span>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</span><span>' . $row[$key] . '</span></div>';
                } elseif (!is_string($mobileTitleKey) && isset($row[$i])) {
                    $cards .= '<div class="data-card-row"><span>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</span><span>' . $row[$i] . '</span></div>';
                }
                ++$i;
            }
            $cards .= '</article>';
        }
        // Simpler mobile cards from rows as numeric arrays
        if ($rows !== [] && !isset($rows[0]['name'])) {
            $cards = '';
            foreach ($rows as $row) {
                $cards .= '<article class="data-card">';
                foreach ($headers as $i => $h) {
                    $cards .= '<div class="data-card-row"><span>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</span><span>' . ($row[$i] ?? '') . '</span></div>';
                }
                $cards .= '</article>';
            }
        }

        return '<div class="table-desktop"><div class="table-scroll"><table class="data-table"><thead><tr>' . $thead . '</tr></thead><tbody>' . $tbody . '</tbody></table></div></div><div class="table-mobile">' . $cards . '</div>';
    }

    public static function publicHomePage(): string
    {
        $criticalCss = self::CRITICAL_CSS;
        $cssHref = Assets::url('css/app.css');

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>پنل مشتریان VPN</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600&display=swap" rel="stylesheet">
    {$criticalCss}
    <link rel="stylesheet" href="{$cssHref}">
</head>
<body class="login customer theme-jaysub">
<div class="login-box">
    <h1>پنل مشتریان VPN</h1>
    <p class="muted login-hint">برای مشاهده مصرف و وضعیت سرویس وارد شوید.</p>
    <a class="btn btn-primary block" href="/login">ورود مشتری</a>
</div>
</body>
</html>
HTML;
    }

    public static function loginPage(string $title, string $action, string $variant, ?string $errorMessage = null): string
    {
        $csrf = Csrf::field();
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $adminHint = $variant === 'admin'
            ? '<p class="muted login-hint">فقط مدیر سرویس. مشتریان: <a href="/">صفحه ورود مشتری</a></p>'
            : '<p class="muted login-hint">مشاهده مصرف و وضعیت سرویس VPN</p>';
        $errorBanner = '';
        if ($errorMessage !== null && $errorMessage !== '') {
            $errorBanner = '<div class="alert alert-error">' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $criticalCss = self::CRITICAL_CSS;
        $cssHref = Assets::url('css/app.css');

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$t}</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600&display=swap" rel="stylesheet">
    {$criticalCss}
    <link rel="stylesheet" href="{$cssHref}">
</head>
<body class="login {$variant} theme-jaysub">
<div class="login-box">
    <h1>{$t}</h1>
    {$adminHint}
    {$errorBanner}
    <form class="stack login-form" method="post" action="{$action}">
        {$csrf}
        <label for="login-username">نام کاربری</label>
        <input id="login-username" type="text" name="username" required autocomplete="username">
        <label for="login-password">رمز عبور</label>
        <input id="login-password" type="password" name="password" required autocomplete="current-password">
        <button type="submit" class="btn btn-primary">ورود</button>
    </form>
</div>
</body>
</html>
HTML;
    }

    /** @deprecated use admin() or customer() */
    public static function render(string $title, string $content, string $variant = 'customer'): string
    {
        return $variant === 'admin'
            ? self::admin($title, 'dashboard', $content)
            : self::customer($title, 'home', $content);
    }

    private static function adminIcon(string $key): string
    {
        return match ($key) {
            'dashboard' => '▣',
            'users' => '👤',
            'subscriptions' => '◈',
            'sales' => '₪',
            'payments' => '💳',
            'messages' => '✉',
            'reports' => '📊',
            'settings' => '⚙',
            default => '•',
        };
    }
}
