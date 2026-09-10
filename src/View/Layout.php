<?php

declare(strict_types=1);

namespace App\View;

use App\Core\Csrf;
use App\Core\Format;

final class Layout
{
    private const CRITICAL_CSS = '<style>body.theme-jaysub{margin:0;background:#0b1020;color:#eef2ff;font-family:Vazirmatn,Tahoma,sans-serif}.admin-mobile-nav{display:none}.sidebar-link{text-decoration:none;color:inherit}@media(max-width:900px){.admin-mobile-nav{display:flex;overflow-x:auto}}</style>';

    /** @var array<string, string> */
    private const ADMIN_NAV = [
        'dashboard' => 'داشبورد',
        'users' => 'کاربران',
        'panels' => 'پنل‌های XUI',
        'services' => 'سرویس‌ها',
        'reports' => 'گزارش مصرف',
        'notifications' => 'اعلان‌ها',
        'settings' => 'تنظیمات',
    ];

    /** @var array<string, array{href: string, label: string, icon: string}> */
    private const ADMIN_BOTTOM = [
        'dashboard' => ['href' => '/admin/dashboard', 'label' => 'خانه', 'icon' => '⌂'],
        'users' => ['href' => '/admin/customers', 'label' => 'کاربران', 'icon' => '👤'],
        'reports' => ['href' => '/admin/reports', 'label' => 'گزارش', 'icon' => '📊'],
        'settings' => ['href' => '/admin/settings', 'label' => 'تنظیمات', 'icon' => '⚙'],
    ];

    /** @var array<string, array{href: string, label: string, icon: string}> */
    private const CUSTOMER_NAV = [
        'home' => ['href' => '/dashboard', 'label' => 'داشبورد', 'icon' => '⌂'],
        'subscription' => ['href' => '/app/subscription', 'label' => 'اشتراک', 'icon' => '◈'],
        'profile' => ['href' => '/app/profile', 'label' => 'پروفایل', 'icon' => '👤'],
    ];

    public static function admin(string $title, string $active, string $content): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $navHtml = '';
        $paths = [
            'dashboard' => '/admin/dashboard',
            'users' => '/admin/customers',
            'panels' => '/admin/panels',
            'services' => '/admin/services',
            'reports' => '/admin/reports',
            'notifications' => '/admin/notifications',
            'settings' => '/admin/settings',
        ];
        foreach (self::ADMIN_NAV as $key => $label) {
            $href = $paths[$key] ?? '#';
            $cls = $key === $active ? 'active' : '';
            $navHtml .= '<a class="sidebar-link ' . $cls . '" href="' . $href . '"><span class="ico">' . self::adminIcon($key) . '</span>' . $label . '</a>';
        }
        $bottom = '';
        foreach (self::ADMIN_BOTTOM as $key => $item) {
            $cls = $key === $active ? 'active' : '';
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
    <title>{$t} | JaySub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
    {$criticalCss}
    <link rel="stylesheet" href="{$cssHref}">
</head>
<body class="theme-jaysub admin-app has-bottom-nav">
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
        <main class="admin-content app-container">{$content}</main>
    </div>
</div>
<nav class="bottom-nav admin-bottom-nav">{$bottom}</nav>
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

    public static function hubTile(string $href, string $icon, string $label, bool $accent = false): string
    {
        $cls = 'action-tile hub-tile' . ($accent ? ' accent' : '');
        return '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"><span class="at-ico">' . $icon . '</span><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
    }

    public static function hubGrid(string $tilesHtml): string
    {
        return '<div class="action-grid hub-grid">' . $tilesHtml . '</div>';
    }

    /** @param list<array{label:string,bytes:int}> $points */
    public static function barChart(array $points): string
    {
        if ($points === []) {
            return '<p class="muted chart-empty">هنوز دادهٔ مصرف ثبت نشده. پس از sync کارگر، نمودار پر می‌شود.</p>';
        }
        $max = max(array_column($points, 'bytes'));
        if ($max <= 0) {
            $max = 1;
        }
        $bars = '';
        foreach ($points as $p) {
            $h = max(4, (int) round(($p['bytes'] / $max) * 100));
            $bars .= '<div class="chart-bar-wrap"><div class="chart-bar" style="height:' . $h . '%"></div><span class="chart-lbl">' . htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') . '</span></div>';
        }
        return '<div class="traffic-chart">' . $bars . '</div>';
    }

    public static function subscriptionLinkCard(?string $url): string
    {
        if ($url === null || $url === '') {
            return self::card('<p class="muted">لینک اشتراک هنوز توسط مدیر تنظیم نشده است.</p>', 'Subscription Link');
        }
        $e = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $body = '<p class="muted sub-link-hint">لینک را در اپ VPN خود Import کنید.</p>
            <div class="sub-link-row">
                <input type="text" readonly class="sub-link-input" id="subscription-link-field" value="' . $e . '">
                <button type="button" class="btn btn-primary btn-copy" data-copy-target="subscription-link-field">Copy</button>
            </div>';
        return self::card($body, 'Subscription Link');
    }

    public static function usageProgress(float $used, float $quota, float $pct): string
    {
        $progClass = $pct >= 90 ? 'danger' : ($pct >= 80 ? 'warn' : '');
        return '<div class="usage-progress-block">
            <div class="usage-progress-head">
                <span>مصرف: <strong>' . Format::bytesToGb($used) . ' / ' . Format::bytesToGb($quota) . '</strong></span>
                <span class="usage-pct">' . $pct . '٪</span>
            </div>
            <div class="progress ' . $progClass . '" data-progress="' . $pct . '"><span style="width:' . $pct . '%"></span></div>
        </div>';
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
            'panels' => '⬡',
            'services' => '◈',
            'reports' => '📊',
            'notifications' => '🔔',
            'settings' => '⚙',
            default => '•',
        };
    }
}
