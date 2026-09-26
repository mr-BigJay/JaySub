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
        'telegram' => 'ربات تلگرام',
        'backup' => 'بک‌آپ',
        'ssl_backup' => 'بکاپ ssl',
        'settings' => 'تنظیمات',
    ];

    /** @var array<string, array{href: string, label: string, icon: string}> */
    private const ADMIN_BOTTOM = [
        'menu' => ['href' => '#', 'label' => 'منو', 'icon' => ''],
        'dashboard' => ['href' => '/admin/dashboard', 'label' => 'خانه', 'icon' => '⌂'],
        'users' => ['href' => '/admin/customers', 'label' => 'کاربران', 'icon' => '👤'],
        'reports' => ['href' => '/admin/reports', 'label' => 'گزارش', 'icon' => '📊'],
    ];

    /** @var array<string, array{href: string, label: string, icon: string}> */
    private const CUSTOMER_NAV = [
        'home' => ['href' => '/dashboard', 'label' => 'داشبورد', 'icon' => '⌂'],
        'subscription' => ['href' => '/app/subscription', 'label' => 'اشتراک', 'icon' => '◈'],
        'profile' => ['href' => '/app/profile', 'label' => 'پروفایل', 'icon' => '👤'],
    ];

    public static function admin(string $title, string $active, string $content, string $headerActionHtml = ''): string
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
            'telegram' => '/admin/telegram',
            'backup' => '/admin/backup',
            'ssl_backup' => '/admin/ssl-backup',
            'settings' => '/admin/settings',
        ];
        foreach (self::ADMIN_NAV as $key => $label) {
            $href = $paths[$key] ?? '#';
            $cls = $key === $active ? 'active' : '';
            $navHtml .= '<a class="sidebar-link ' . $cls . '" href="' . $href . '"><span class="ico">' . self::adminIcon($key) . '</span>' . $label . '</a>';
        }
        $bottom = '';
        foreach (self::ADMIN_BOTTOM as $key => $item) {
            if ($key === 'menu') {
                $bottom .= '<button type="button" class="bottom-nav-item bottom-nav-menu-trigger" data-open-admin-menu aria-label="باز کردن منو" aria-expanded="false">'
                    . '<span class="bn-icon bn-icon-menu" aria-hidden="true"><span></span><span></span><span></span></span>'
                    . '<span>' . $item['label'] . '</span></button>';
                continue;
            }
            $cls = $key === $active ? 'active' : '';
            $bottom .= '<a class="bottom-nav-item ' . $cls . '" href="' . $item['href'] . '"><span class="bn-icon">' . $item['icon'] . '</span><span>' . $item['label'] . '</span></a>';
        }

        $isDashboard = $active === 'dashboard';
        $topClass = 'admin-top' . ($isDashboard ? ' admin-top-dashboard' : '');
        $backHtml = $isDashboard
            ? '<span class="admin-top-slot admin-top-slot-start" aria-hidden="true"></span>'
            : '<a class="admin-back" href="/admin/dashboard" data-admin-back aria-label="بازگشت"><span aria-hidden="true">←</span></a>';

        $headerEndHtml = $headerActionHtml !== ''
            ? '<div class="admin-header-end">' . $headerActionHtml . '</div>'
            : '<span class="admin-top-slot admin-top-slot-end" aria-hidden="true"></span>';

        $menuSheet = '<div class="admin-nav-sheet" id="admin-nav-sheet" hidden>
            <div class="admin-nav-sheet-backdrop" data-close-admin-menu tabindex="-1" aria-hidden="true"></div>
            <div class="admin-nav-sheet-panel" role="dialog" aria-modal="true" aria-label="منوی ادمین">
                <div class="admin-nav-sheet-handle" aria-hidden="true"></div>
                <div class="admin-nav-sheet-brand">JaySub <span>منو</span></div>
                <nav class="admin-nav-sheet-links">' . $navHtml . '</nav>
                <a class="admin-nav-sheet-logout" href="/admin/logout">خروج</a>
            </div>
        </div>';

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
        <header class="{$topClass}">
            {$backHtml}
            <h1 class="page-title">{$t}</h1>
            {$headerEndHtml}
        </header>
        <main class="admin-content app-container">{$content}</main>
    </div>
</div>
{$menuSheet}
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

    public static function copyLinkField(string $inputId, string $url, string $title, string $hint): string
    {
        $e = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $body = '<p class="muted sub-link-hint">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>
            <div class="sub-link-row">
                <input type="text" readonly class="sub-link-input" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" value="' . $e . '">
                <button type="button" class="btn btn-primary btn-copy" data-copy-target="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '">کپی</button>
            </div>';
        return self::card($body, $title);
    }

    public static function publicUsagePage(string $title, string $content): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
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
<body class="theme-jaysub customer-app public-usage-page usage-link-page">
<main class="customer-main usage-view-shell">{$content}</main>
<script src="{$jsSrc}" defer></script>
</body>
</html>
HTML;
    }

    public static function publicUsageViewContent(
        string $brandName,
        float $usedBytes,
        float $quotaBytes,
        float $pct,
        ?string $endsAt,
        string $statusKey,
    ): string {
        $remaining = max(0.0, $quotaBytes - $usedBytes);
        $pctDisplay = (int) round($pct);
        $pctBar = min(100.0, $pct);
        $expiryText = $endsAt ? self::formatUsageExpiry((string) $endsAt) : '—';
        $brandEsc = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');
        $usedLabel = Format::gbPrefix($usedBytes);
        $quotaSuffix = number_format($quotaBytes / (1024 ** 3), 2, '.', '') . ' GB';
        $statusHtml = self::publicUsageStatusPill($statusKey);

        return '<div class="usage-view-page">
            <header class="uv-header">
                <div class="uv-brand">
                    <span class="uv-brand-name">' . $brandEsc . '</span>
                    <span class="uv-signal" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                </div>
                <p class="uv-section-label">مصرف سرویس</p>
            </header>

            <section class="uv-card uv-usage-card">
                <div class="uv-usage-main">
                    <div class="uv-donut" style="--pct:' . $pctBar . '">
                        <span>' . $pctDisplay . '٪<br><small>از حجم کل</small></span>
                    </div>
                    <div class="uv-usage-text">
                        <div class="uv-usage-label">مصرف:</div>
                        <div class="uv-usage-values"><span class="uv-cyan">' . htmlspecialchars($usedLabel, ENT_QUOTES, 'UTF-8') . '</span> / ' . htmlspecialchars($quotaSuffix, ENT_QUOTES, 'UTF-8') . '</div>
                        <p class="uv-usage-sub">مصرف کل سرویس</p>
                    </div>
                </div>
                <div class="progress uv-progress"><span style="width:' . $pctBar . '%"></span></div>
            </section>

            <section class="uv-card uv-status-card">
                <header class="uv-card-head">
                    <h2 class="uv-card-title">وضعیت اشتراک</h2>
                    <button type="button" class="uv-refresh" onclick="location.reload()" aria-label="به‌روزرسانی">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>
                    </button>
                </header>
                ' . self::publicUsageRow('سقف حجم', Format::gbPrefix($quotaBytes), 'db') . '
                ' . self::publicUsageRow('مصرف‌شده', Format::gbPrefix($usedBytes), 'traffic') . '
                ' . self::publicUsageRow('باقی‌مانده', Format::gbPrefix($remaining), 'clock', true) . '
                ' . self::publicUsageRow('انقضا', $expiryText, 'calendar') . '
                ' . self::publicUsageRow('وضعیت', $statusHtml, 'shield', false, true) . '
            </section>

            <footer class="uv-footer">
                <span class="uv-rocket" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.5 16.5c4-4 6-9 6-9s5 5 9 6c0 0-5 1-9 6-4 5-6 9-6 9s-4-5-6-9z"/><path d="M9 15l-1 4 4-1"/></svg>
                </span>
                <p>به‌روزرسانی هر حدود یک دقیقه</p>
            </footer>
            <div class="uv-wave" aria-hidden="true"></div>
        </div>';
    }

    private static function formatUsageExpiry(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return htmlspecialchars($datetime, ENT_QUOTES, 'UTF-8');
        }
        return htmlspecialchars(Format::dateTehran('Y/m/d', $ts), ENT_QUOTES, 'UTF-8');
    }

    private static function publicUsageStatusPill(string $statusKey): string
    {
        $map = [
            'active' => ['فعال', ''],
            'exhausted' => ['اتمام حجم', 'danger'],
            'disabled' => ['غیرفعال', 'danger'],
            'expired' => ['منقضی', 'danger'],
            'warning' => ['هشدار', 'warn'],
            'inactive' => ['غیرفعال', 'danger'],
        ];
        [$label, $tone] = $map[$statusKey] ?? ['نامشخص', 'warn'];
        $cls = 'uv-status-pill' . ($tone !== '' ? ' ' . $tone : '');
        return '<span class="' . $cls . '"><span class="uv-status-dot"></span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    private static function publicUsageRow(
        string $label,
        string $valueHtml,
        string $icon,
        bool $iconTeal = false,
        bool $valueIsHtml = false,
    ): string {
        $value = $valueIsHtml
            ? $valueHtml
            : htmlspecialchars($valueHtml, ENT_QUOTES, 'UTF-8');
        $iconCls = 'uv-row-icon' . ($iconTeal ? ' teal' : '');
        return '<div class="uv-row">
            <div class="uv-row-meta">
                <span class="' . $iconCls . '">' . self::publicUsageIcon($icon) . '</span>
                <span class="uv-row-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>
            </div>
            <div class="uv-row-value">' . $value . '</div>
        </div>';
    }

    private static function publicUsageIcon(string $kind): string
    {
        return match ($kind) {
            'db' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>',
            'traffic' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>',
            'clock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
            'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>',
            'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 4v6c0 5-3.5 8-8 8s-8-3-8-8V7l8-4z"/><path d="M9 12l2 2 4-4"/></svg>',
            default => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="6"/></svg>',
        };
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
                <span>مصرف: <strong>' . Format::usageVolume($used) . ' / ' . Format::bytesToGb($quota) . '</strong></span>
                <span class="usage-pct">' . $pct . '٪</span>
            </div>
            <div class="progress ' . $progClass . '" data-progress="' . $pct . '"><span style="width:' . $pct . '%"></span></div>
        </div>';
    }

    /**
     * @param list<array{id:int,name:string,connection_status:string,is_active:int|string}> $panels
     */
    public static function adminCustomerManagePage(
        int $customerId,
        string $displayName,
        string $subtitle,
        string $statusKey,
        ?string $endsAt,
        float $usedBytes,
        float $quotaBytes,
        array $panels,
        string $subscriptionLink,
        string $usageViewUrl,
        string $csrfField,
        string $quotaGbValue,
        string $flashHtml = '',
    ): string {
        $pct = Format::percent($usedBytes, $quotaBytes);
        $remaining = max(0.0, $quotaBytes - $usedBytes);
        $days = Format::daysUntil($endsAt);
        $expiryText = $endsAt ? Format::jalaliOrGregorian((string) $endsAt) : '—';
        $daysHtml = $days !== null
            ? '<div class="sub-mgmt-days"><span class="sub-mgmt-days-num">' . $days . '</span><span class="sub-mgmt-days-label">روز</span><span class="sub-mgmt-days-date">تا ' . htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') . '</span></div>'
            : '<div class="sub-mgmt-days sub-mgmt-days-muted"><span class="sub-mgmt-days-label">بدون تاریخ انقضا</span></div>';

        $statusBadge = self::statusPill($statusKey);
        $nameEsc = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $subEsc = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');

        $panelChips = '';
        foreach ($panels as $p) {
            $conn = (string) ($p['connection_status'] ?? '');
            $isOn = (int) ($p['is_active'] ?? 0) === 1;
            $dot = match ($conn) {
                'connected' => 'on',
                'sync_error' => 'warn',
                default => 'off',
            };
            $connLabel = $conn === 'connected' ? 'متصل' : ($conn === 'sync_error' ? 'خطای sync' : 'قطع');
            if (!$isOn) {
                $connLabel = 'خارج از سرویس';
                $dot = 'off';
            }
            if ((int) ($p['internet_cut'] ?? 0) === 1) {
                $connLabel = 'اینترنت قطع';
                $dot = 'warn';
            }
            $panelChips .= '<div class="sub-mgmt-panel-chip">
                <span class="sub-mgmt-panel-dot ' . $dot . '"></span>
                <span class="sub-mgmt-panel-name">' . htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8') . '</span>
                <span class="sub-mgmt-panel-state">' . htmlspecialchars($connLabel, ENT_QUOTES, 'UTF-8') . '</span>
                <a class="sub-mgmt-panel-link" href="/admin/panels/' . (int) $p['id'] . '/clients">کلاینت‌ها</a>
            </div>';
        }
        if ($panelChips === '') {
            $panelChips = '<p class="muted sub-mgmt-empty">پنلی ثبت نشده — از دکمهٔ پایین پنل اضافه کنید.</p>';
        }

        $usedGb = Format::bytesToGb($usedBytes);
        $quotaGb = Format::bytesToGb($quotaBytes);
        $remainGb = Format::bytesToGb($remaining);
        $pctInt = (int) round($pct);
        $progClass = $pct >= 90 ? 'danger' : ($pct >= 80 ? 'warn' : '');

        $subLinkBlock = '';
        if ($subscriptionLink !== '') {
            $subEscLink = htmlspecialchars($subscriptionLink, ENT_QUOTES, 'UTF-8');
            $subLinkBlock = '<section class="ui-card sub-mgmt-card">
                <h2 class="card-title sub-mgmt-card-title"><span class="sub-mgmt-ico">🔗</span> لینک اشتراک</h2>
                <p class="muted sub-link-hint">این لینک را در اپ VPN مشتری Import کنید.</p>
                <div class="sub-link-row">
                    <input type="text" readonly class="sub-link-input" id="admin-subscription-link" value="' . $subEscLink . '">
                    <button type="button" class="btn btn-primary btn-copy" data-copy-target="admin-subscription-link">کپی</button>
                </div>
            </section>';
        } else {
            $subLinkBlock = '<section class="ui-card sub-mgmt-card"><h2 class="card-title">لینک اشتراک</h2><p class="muted">در <a href="/admin/customers/' . $customerId . '/service">راه‌اندازی سرویس</a> تنظیم کنید.</p></section>';
        }

        $usageLinkBlock = '';
        if ($usageViewUrl !== '') {
            $usageEsc = htmlspecialchars($usageViewUrl, ENT_QUOTES, 'UTF-8');
            $usageLinkBlock = '<section class="ui-card sub-mgmt-card">
                <h2 class="card-title sub-mgmt-card-title"><span class="sub-mgmt-ico">📊</span> لینک مشاهده مصرف</h2>
                <p class="muted sub-link-hint">بدون ورود — فقط نمایش مصرف برای مشتری.</p>
                <div class="sub-link-row">
                    <input type="text" readonly class="sub-link-input" id="customer-usage-link" value="' . $usageEsc . '">
                    <button type="button" class="btn btn-primary btn-copy" data-copy-target="customer-usage-link">کپی</button>
                </div>
            </section>';
        }

        $statusDetail = self::statusPill($statusKey);

        return '<div class="sub-mgmt-page">' . $flashHtml . '
            <header class="sub-mgmt-toolbar">
                <a class="sub-mgmt-back" href="/admin/customers" aria-label="بازگشت">←</a>
                <h2 class="sub-mgmt-title">مدیریت اشتراک</h2>
                <a class="sub-mgmt-menu" href="/admin/customers/' . $customerId . '/service" title="تنظیمات سرویس">⋯</a>
            </header>

            <section class="ui-card sub-mgmt-hero">
                <div class="sub-mgmt-hero-icon" aria-hidden="true">🛡</div>
                <div class="sub-mgmt-hero-main">
                    <div class="sub-mgmt-hero-head">
                        <h3 class="sub-mgmt-username">' . $nameEsc . '</h3>
                        ' . $statusBadge . '
                    </div>
                    <p class="muted sub-mgmt-hero-sub">' . $subEsc . '</p>
                </div>
                ' . $daysHtml . '
            </section>

            <section class="ui-card sub-mgmt-card">
                <h2 class="card-title sub-mgmt-card-title"><span class="sub-mgmt-ico">⬡</span> پنل‌های 3X-UI</h2>
                <div class="sub-mgmt-panels">' . $panelChips . '</div>
            </section>

            <section class="ui-card sub-mgmt-card sub-mgmt-usage">
                <h2 class="card-title sub-mgmt-card-title"><span class="sub-mgmt-ico">🗄</span> مصرف حجم</h2>
                <div class="sub-mgmt-usage-visual">
                    <div class="usage-donut" style="--pct:' . $pct . '">
                        <span>' . $pctInt . '٪<br><small>مصرف</small></span>
                    </div>
                    <div class="sub-mgmt-usage-bar-wrap">
                        <div class="sub-mgmt-usage-bar-label">' . $usedGb . ' / ' . $quotaGb . '</div>
                        <div class="progress sub-mgmt-bar ' . $progClass . '"><span style="width:' . min(100, $pct) . '%"></span></div>
                    </div>
                </div>
                <div class="sub-mgmt-stats">
                    <div class="sub-mgmt-stat"><span class="sub-mgmt-stat-label">مجموع حجم</span><span class="sub-mgmt-stat-val">' . $quotaGb . '</span></div>
                    <div class="sub-mgmt-stat"><span class="sub-mgmt-stat-label">مصرف‌شده</span><span class="sub-mgmt-stat-val">' . $usedGb . '</span></div>
                    <div class="sub-mgmt-stat"><span class="sub-mgmt-stat-label">باقی‌مانده</span><span class="sub-mgmt-stat-val">' . $remainGb . '</span></div>
                </div>
                <form class="sub-mgmt-quota-form" method="post" action="/admin/customers/' . $customerId . '/set-quota">' . $csrfField . '
                    <label for="quota_gb_admin">سقف حجم (GB) — قابل ویرایش</label>
                    <div class="sub-mgmt-quota-row">
                        <input id="quota_gb_admin" name="quota_gb" type="number" step="0.1" min="0" required value="' . $quotaGbValue . '">
                        <button class="btn btn-primary" type="submit">ذخیره سقف</button>
                    </div>
                    <p class="muted form-hint">مصرف واقعی از 3x-ui sync می‌شود؛ این فیلد فقط سقف را تعیین می‌کند.</p>
                </form>
            </section>

            <section class="ui-card sub-mgmt-card sub-mgmt-details">
                <h2 class="card-title">جزئیات اشتراک</h2>
                <div class="sub-mgmt-detail-row"><span>نوع اشتراک</span><span>سرویس VPN</span></div>
                <div class="sub-mgmt-detail-row"><span>تاریخ انقضا</span><span>' . htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') . '</span></div>
                <div class="sub-mgmt-detail-row"><span>وضعیت</span><span>' . $statusDetail . '</span></div>
            </section>

            ' . $subLinkBlock . '
            ' . $usageLinkBlock . '

            <div class="sub-mgmt-actions">
                <a class="btn btn-primary block sub-mgmt-btn-primary" href="/admin/customers/' . $customerId . '/service">↻ تمدید / تنظیم سرویس</a>
                <a class="btn btn-secondary block" href="/admin/customers/' . $customerId . '/sync">هم‌اکنون Sync مصرف</a>
                <a class="btn btn-ghost block" href="/admin/customers/' . $customerId . '/panels/new">افزودن پنل 3X-UI</a>
            </div>
        </div>';
    }

    /**
     * @param array{customers:int,active:int,services:int,panels_connected:int,total_traffic:int} $summary
     * @param array{today:int,week:int,month:int,total:int} $periods
     * @param list<array{label:string,bytes:int}> $chartPoints
     */
    public static function adminDashboardPage(array $summary, array $periods, array $chartPoints): string
    {
        $heroVal = Format::bytesAuto((float) $summary['total_traffic']);
        $todayVal = Format::bytesAuto((float) $periods['today']);
        $weekVal = Format::bytesAuto((float) $periods['week']);
        $monthVal = Format::bytesAuto((float) $periods['month']);

        $chartHtml = self::barChart($chartPoints);
        $chartHtml = str_replace('class="traffic-chart"', 'class="traffic-chart admin-dash-chart"', $chartHtml);

        return '<div class="admin-dash-page">
            <section class="admin-dash-hero ui-card">
                <div class="admin-dash-hero-head">
                    <span class="admin-dash-globe" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.8 4 6 4 9s-1.5 6.2-4 9M12 3c-2.5 2.8-4 6-4 9s1.5 6.2 4 9"/></svg>
                    </span>
                    <span class="admin-dash-hero-label">مصرف کل اینترنت</span>
                    <span class="admin-dash-mini-chart" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 18V6M10 18V10M16 18V14M22 18V4"/></svg>
                    </span>
                </div>
                <div class="admin-dash-hero-value">' . htmlspecialchars($heroVal, ENT_QUOTES, 'UTF-8') . '</div>
                <div class="admin-dash-periods">
                    <div class="admin-dash-period"><span class="adp-label">ماه</span><span class="adp-val">' . htmlspecialchars($monthVal, ENT_QUOTES, 'UTF-8') . '</span></div>
                    <div class="admin-dash-period"><span class="adp-label">هفته</span><span class="adp-val">' . htmlspecialchars($weekVal, ENT_QUOTES, 'UTF-8') . '</span></div>
                    <div class="admin-dash-period active"><span class="adp-label">امروز</span><span class="adp-val">' . htmlspecialchars($todayVal, ENT_QUOTES, 'UTF-8') . '</span></div>
                </div>
                ' . $chartHtml . '
            </section>

            <div class="admin-dash-stats">
                ' . self::adminDashStatCard(
                    'کاربران فعال',
                    (string) $summary['active'],
                    'آنلاین',
                    'users-active',
                    true,
                )
                . self::adminDashStatCard(
                    'کاربران کل',
                    (string) $summary['customers'],
                    '',
                    'users-total',
                    false,
                )
                . self::adminDashStatCard(
                    'پنل‌های متصل',
                    (string) $summary['panels_connected'],
                    'فعال',
                    'panels',
                    true,
                )
                . self::adminDashStatCard(
                    'سرویس‌های فعال',
                    (string) $summary['services'],
                    'فعال',
                    'services',
                    true,
                ) . '
            </div>

            <section class="admin-dash-quick">
                <div class="admin-dash-quick-head">
                    <h3>عملیات سریع</h3>
                    <p class="muted">دسترسی سریع به امکانات اصلی</p>
                </div>
                <div class="admin-dash-actions">
                    ' . self::adminDashAction('/admin/customers/new', 'ایجاد کاربر', 'plus', true) . '
                    ' . self::adminDashAction('/admin/panels', 'پنل‌های XUI', 'hex') . '
                    ' . self::adminDashAction('/admin/services', 'راه‌اندازی سرویس', 'service') . '
                    ' . self::adminDashAction('/admin/customers', 'کاربران', 'users') . '
                    ' . self::adminDashAction('/admin/reports', 'گزارش مصرف', 'chart') . '
                    ' . self::adminDashAction('/admin/notifications', 'اعلان‌ها', 'bell') . '
                </div>
            </section>
        </div>';
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    public static function adminBackupPage(
        string $flashHtml,
        string $activeTab,
        array $files,
        int $jalaliYear,
        int $jalaliMonth,
        string $cardTitle,
        string $csrfField,
    ): string {
        $tabDefs = [
            'latest' => ['label' => 'آخرین', 'icon' => 'doc'],
            'week' => ['label' => 'هفته جاری', 'icon' => 'clock'],
            'month' => ['label' => 'ماه', 'icon' => 'cal'],
        ];
        $nav = '';
        foreach ($tabDefs as $key => $def) {
            $cls = $key === $activeTab ? 'active' : '';
            $href = '/admin/backup?tab=' . rawurlencode($key);
            if ($key === 'month') {
                $href .= '&jy=' . $jalaliYear . '&jm=' . $jalaliMonth;
            }
            $nav .= '<a class="backup-hub-tab ' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
                . '<span class="backup-hub-tab-ico">' . self::backupHubSvg($def['icon']) . '</span>'
                . htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }

        $list = '';
        $i = 0;
        foreach ($files as $f) {
            $list .= self::backupPanelFileCard($f, $i++);
        }
        if ($list === '') {
            $list = '<div class="backup-hub-empty">موردی برای نمایش نیست.</div>';
        }

        $monthPills = '';
        if ($activeTab === 'month') {
            $monthPills = '<nav class="backup-month-nav" aria-label="انتخاب ماه شمسی">';
            for ($m = 1; $m <= 12; ++$m) {
                $cls = $m === $jalaliMonth ? 'active' : '';
                $monthPills .= '<a class="backup-month-pill ' . $cls . '" href="/admin/backup?tab=month&jy='
                    . $jalaliYear . '&jm=' . $m . '">' . $m . '</a>';
            }
            $monthPills .= '</nav>';
        }

        $lastTs = 0;
        foreach ($files as $f) {
            $ts = (int) ($f['backup_ts'] ?? $f['mtime'] ?? 0);
            if ($ts > $lastTs) {
                $lastTs = $ts;
            }
        }

        $entryCount = count($files);

        return '<div class="sub-mgmt-page xui-backup-page">' . $flashHtml
            . '<section class="ui-card sub-mgmt-hero sub-mgmt-panels-hero">'
            . '<div class="sub-mgmt-hero-icon xui-backup-hero-icon" aria-hidden="true">💾</div>'
            . '<div class="sub-mgmt-hero-main">'
            . '<h3 class="sub-mgmt-username">بکاپ پنل‌های 3x-ui</h3>'
            . '<p class="muted sub-mgmt-hero-sub">' . $entryCount . ' مورد در این نما · فایل برای بازیابی</p>'
            . '</div>'
            . '<div class="sub-mgmt-days sub-mgmt-days-muted">'
            . '<span class="sub-mgmt-days-label">هر ۴ ساعت</span>'
            . '</div>'
            . '</section>'
            . '<section class="ui-card sub-mgmt-card xui-backup-main-card">'
            . '<nav class="backup-hub-tabs" aria-label="تب‌های بک‌آپ">' . $nav . '</nav>'
            . '<h2 class="backup-hub-section-title">' . self::backupHubSvg('doc') . ' '
            . htmlspecialchars($cardTitle, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<div class="backup-hub-list">' . $list . '</div>'
            . $monthPills
            . self::backupHubFooter($lastTs)
            . '</section>'
            . '</div>';
    }

    /** @param array<string, mixed> $f */
    private static function backupPanelFileCard(array $f, int $index): string
    {
        $dl = '/admin/backup/download?panel=' . (int) $f['panel_id'] . '&file=' . rawurlencode((string) $f['filename']);
        $edit = '/admin/panels/' . (int) $f['panel_id'] . '/edit';
        $accent = 'accent-' . ($index % 4);
        $name = htmlspecialchars((string) $f['panel_name'], ENT_QUOTES, 'UTF-8');
        $baseUrl = (string) ($f['panel_base_url'] ?? '');
        $copyLine = $baseUrl !== '' ? $baseUrl : (string) $f['filename'];
        $copyAttr = htmlspecialchars($copyLine, ENT_QUOTES, 'UTF-8');
        $ts = (int) ($f['backup_ts'] ?? $f['mtime'] ?? 0);
        $dateStr = htmlspecialchars(Format::fromTimestamp($ts), ENT_QUOTES, 'UTF-8');
        $sizeStr = htmlspecialchars(Format::bytesAuto((float) $f['bytes']), ENT_QUOTES, 'UTF-8');
        $warn = !empty($f['host_mismatch'])
            ? '<p class="backup-warn">⚠ نام فایل با آدرس پنل یکی نیست</p>'
            : '';
        $weekNote = isset($f['week_key'])
            ? '<span class="muted backup-week-tag">هفته ' . htmlspecialchars((string) $f['week_key'], ENT_QUOTES, 'UTF-8') . '</span>'
            : '';

        return '<article class="backup-hub-card ' . $accent . '"><div class="backup-hub-card-inner">'
            . '<div class="backup-hub-card-main">'
            . '<div class="backup-hub-card-head">'
            . '<span class="backup-hub-card-avatar" aria-hidden="true">⬡</span>'
            . '<div><p class="backup-hub-card-name">' . $name . '</p><span class="backup-hub-badge">پنل</span> ' . $weekNote . '</div>'
            . '</div>'
            . '<div class="backup-hub-url-wrap"><span class="backup-hub-link-ico" aria-hidden="true">🔗</span>'
            . '<button type="button" class="backup-hub-url" data-copy-text="' . $copyAttr . '">' . $copyAttr . '</button></div>'
            . '<a class="backup-hub-edit" href="' . htmlspecialchars($edit, ENT_QUOTES, 'UTF-8') . '">'
            . self::backupHubSvg('pencil') . ' ویرایش پنل</a>'
            . $warn
            . '</div>'
            . '<div class="backup-hub-card-side">'
            . '<span class="backup-hub-stat">' . self::backupHubSvg('db') . ' ' . $sizeStr . '</span>'
            . '<span class="backup-hub-stat">' . self::backupHubSvg('cal') . ' ' . $dateStr . '</span>'
            . '<a class="btn btn-primary backup-hub-dl" href="' . htmlspecialchars($dl, ENT_QUOTES, 'UTF-8') . '">'
            . self::backupHubSvg('down') . ' دانلود</a>'
            . '</div></div></article>';
    }

    /**
     * @param list<array<string, mixed>> $servers
     * @param list<array<string, mixed>> $files
     */
    public static function adminSslBackupPage(
        string $flashHtml,
        string $activeTab,
        array $servers,
        array $files,
        string $csrfField,
        bool $openAddModal = false,
    ): string {
        if (!in_array($activeTab, ['files', 'servers'], true)) {
            $activeTab = 'files';
        }
        $tabDefs = [
            'files' => ['label' => 'آخرین', 'icon' => 'doc'],
            'servers' => ['label' => 'سرورها', 'icon' => 'clock'],
        ];
        $nav = '';
        foreach ($tabDefs as $key => $def) {
            $cls = $key === $activeTab ? 'active' : '';
            $href = '/admin/ssl-backup?tab=' . rawurlencode($key);
            $nav .= '<a class="backup-hub-tab ' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
                . '<span class="backup-hub-tab-ico">' . self::backupHubSvg($def['icon']) . '</span>'
                . htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }

        $body = '';
        $lastTs = 0;
        if ($activeTab === 'files') {
            $list = '';
            $i = 0;
            foreach ($files as $f) {
                $ts = (int) ($f['mtime'] ?? 0);
                if ($ts > $lastTs) {
                    $lastTs = $ts;
                }
                $list .= self::backupSslFileCard($f, $i++);
            }
            if ($list === '') {
                $list = '<div class="backup-hub-empty">هنوز فایل بکاپی ذخیره نشده.</div>';
            }
            $body = '<h2 class="backup-hub-section-title">' . self::backupHubSvg('doc') . ' آخرین بکاپ‌های SSL</h2>'
                . '<div class="backup-hub-list">' . $list . '</div>';
        } elseif ($activeTab === 'servers') {
            $list = '';
            $i = 0;
            foreach ($servers as $s) {
                $list .= self::backupSslServerCard($s, $csrfField, $i++);
            }
            if ($list === '') {
                $list = '<div class="backup-hub-empty">هنوز سروری ثبت نشده — «افزودن سرور جدید» را بزنید.</div>';
            }
            $body = '<h2 class="backup-hub-section-title">' . self::backupHubSvg('clock') . ' سرورهای ثبت‌شده</h2>'
                . '<div class="backup-hub-list">' . $list . '</div>';
        }
        foreach ($files as $f) {
            $ts = (int) ($f['mtime'] ?? 0);
            if ($ts > $lastTs) {
                $lastTs = $ts;
            }
        }

        $modalAuto = $openAddModal ? ' data-auto-open' : '';
        $activeCount = 0;
        foreach ($servers as $s) {
            if ((int) ($s['is_active'] ?? 0) === 1) {
                ++$activeCount;
            }
        }
        $totalServers = count($servers);

        return '<div class="sub-mgmt-page ssl-backup-page">' . $flashHtml
            . '<section class="ui-card sub-mgmt-hero sub-mgmt-panels-hero">'
            . '<div class="sub-mgmt-hero-icon ssl-backup-hero-icon" aria-hidden="true">🔒</div>'
            . '<div class="sub-mgmt-hero-main">'
            . '<h3 class="sub-mgmt-username">مدیریت بکاپ SSL</h3>'
            . '<p class="muted sub-mgmt-hero-sub">' . $activeCount . ' فعال از ' . $totalServers . ' سرور ثبت‌شده</p>'
            . '</div>'
            . '<div class="sub-mgmt-days sub-mgmt-days-muted">'
            . '<span class="sub-mgmt-days-label">هفتگی · SSH</span>'
            . '</div>'
            . '</section>'
            . '<section class="ui-card sub-mgmt-card ssl-backup-main-card">'
            . '<nav class="backup-hub-tabs" aria-label="تب‌های بکاپ SSL">' . $nav . '</nav>'
            . '<form method="post" action="/admin/ssl-backup/run" class="backup-hub-run-form">' . $csrfField
            . '<button class="btn btn-primary backup-hub-run-btn" type="submit">'
            . self::backupHubSvg('cloud') . ' بکاپ‌گیری آنی (همه سرورهای فعال)</button></form>'
            . $body
            . self::backupHubFooter($lastTs)
            . '</section>'
            . self::sslAddServerModal($csrfField, $modalAuto)
            . '</div>';
    }

    private static function sslAddServerModal(string $csrfField, string $autoOpenAttr = ''): string
    {
        return '<div class="app-modal" id="ssl-add-server-modal" hidden' . $autoOpenAttr . '>
                <div class="app-modal-backdrop" data-close-modal tabindex="-1" aria-hidden="true"></div>
                <div class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ssl-add-server-title">
                    <button type="button" class="app-modal-close" data-close-modal aria-label="بستن">×</button>
                    <h2 class="card-title sub-mgmt-card-title" id="ssl-add-server-title"><span class="sub-mgmt-ico">＋</span> افزودن سرور جدید</h2>
                    ' . self::sslAddServerForm($csrfField) . '
                </div>
            </div>';
    }

    private static function sslAddServerForm(string $csrfField): string
    {
        return '<form class="stack" method="post" action="/admin/ssl-backup">' . $csrfField . '
            <label>نام سرور</label>
            <input name="name" required placeholder="مثلاً Bell-SSL">
            <label>آدرس میزبان (IP یا دامنه)</label>
            <input name="host" required dir="ltr" placeholder="203.0.113.10">
            <label>پورت SSH</label>
            <input name="ssh_port" type="number" min="1" max="65535" value="22" dir="ltr">
            <label>کاربر SSH</label>
            <input name="ssh_username" value="root" dir="ltr">
            <label>نوع احراز هویت</label>
            <select name="auth_type">
                <option value="password">رمز عبور</option>
                <option value="key">کلید خصوصی (PEM)</option>
            </select>
            <label>رمز SSH یا کلید خصوصی</label>
            <textarea name="ssh_secret" required rows="4" dir="ltr" placeholder="رمز root یا محتوای id_rsa"></textarea>
            <label>مسیر پوشهٔ گواهی روی سرور</label>
            <input name="cert_path" value="/root/cert" dir="ltr">
            <p class="muted form-hint">هر هفته این پوشه zip (یا tar.gz) و روی JaySub ذخیره می‌شود. روی سرور remote: <code>zip</code> یا <code>tar</code>؛ برای پسورد روی JaySub: <code>sshpass</code> و پوشهٔ <code>storage/ssl-ssh</code> برای www-data.</p>
            <button class="btn btn-primary block" type="submit">ثبت سرور</button>
        </form>';
    }

    /** @param array<string, mixed> $f */
    private static function backupSslFileCard(array $f, int $index): string
    {
        $dl = '/admin/ssl-backup/download?server=' . (int) $f['server_id'] . '&file=' . rawurlencode((string) $f['filename']);
        $edit = '/admin/ssl-backup/' . (int) $f['server_id'] . '/edit';
        $accent = 'accent-' . ($index % 4);
        $name = htmlspecialchars((string) $f['server_name'], ENT_QUOTES, 'UTF-8');
        $fname = htmlspecialchars((string) $f['filename'], ENT_QUOTES, 'UTF-8');
        $ts = (int) ($f['mtime'] ?? 0);
        $dateStr = htmlspecialchars(Format::fromTimestamp($ts), ENT_QUOTES, 'UTF-8');
        $sizeStr = htmlspecialchars(Format::bytesAuto((float) $f['bytes']), ENT_QUOTES, 'UTF-8');

        return '<article class="backup-hub-card ' . $accent . '"><div class="backup-hub-card-inner">'
            . '<div class="backup-hub-card-main">'
            . '<div class="backup-hub-card-head">'
            . '<span class="backup-hub-card-avatar" aria-hidden="true">🔒</span>'
            . '<div><p class="backup-hub-card-name">' . $name . '</p><span class="backup-hub-badge">SSL</span></div>'
            . '</div>'
            . '<div class="backup-hub-url-wrap"><span class="backup-hub-link-ico" aria-hidden="true">📦</span>'
            . '<button type="button" class="backup-hub-url" data-copy-text="' . $fname . '">' . $fname . '</button></div>'
            . '<a class="backup-hub-edit" href="' . htmlspecialchars($edit, ENT_QUOTES, 'UTF-8') . '">'
            . self::backupHubSvg('pencil') . ' ویرایش سرور</a>'
            . '</div>'
            . '<div class="backup-hub-card-side">'
            . '<span class="backup-hub-stat">' . self::backupHubSvg('db') . ' ' . $sizeStr . '</span>'
            . '<span class="backup-hub-stat">' . self::backupHubSvg('cal') . ' ' . $dateStr . '</span>'
            . '<a class="btn btn-primary backup-hub-dl" href="' . htmlspecialchars($dl, ENT_QUOTES, 'UTF-8') . '">'
            . self::backupHubSvg('down') . ' دانلود</a>'
            . '</div></div></article>';
    }

    /** @param array<string, mixed> $s */
    private static function backupSslServerCard(array $s, string $csrfField, int $index): string
    {
        $sid = (int) $s['id'];
        $active = (int) ($s['is_active'] ?? 0) === 1;
        $accent = 'accent-' . ($index % 4);
        $name = htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8');
        $endpoint = htmlspecialchars(
            (string) $s['ssh_username'] . '@' . (string) $s['host'] . ':' . (int) $s['ssh_port'],
            ENT_QUOTES,
            'UTF-8',
        );
        $cert = htmlspecialchars((string) $s['cert_path'], ENT_QUOTES, 'UTF-8');
        $copyAttr = $endpoint;
        $last = $s['last_backup_at'] ?? null;
        $lastStr = is_string($last) && $last !== ''
            ? htmlspecialchars(Format::jalaliOrGregorian($last), ENT_QUOTES, 'UTF-8')
            : '—';
        $err = trim((string) ($s['last_error'] ?? ''));
        $errHtml = $err !== ''
            ? '<p class="backup-warn">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $toggleLabel = $active ? 'غیرفعال' : 'فعال';
        $toggleVal = $active ? '0' : '1';
        $badge = $active ? 'فعال' : 'غیرفعال';

        return '<article class="backup-hub-card ' . $accent . '"><div class="backup-hub-card-inner">'
            . '<div class="backup-hub-card-main">'
            . '<div class="backup-hub-card-head">'
            . '<span class="backup-hub-card-avatar" aria-hidden="true">🖥</span>'
            . '<div><p class="backup-hub-card-name">' . $name . '</p><span class="backup-hub-badge">' . $badge . '</span></div>'
            . '</div>'
            . '<div class="backup-hub-url-wrap"><span class="backup-hub-link-ico" aria-hidden="true">🔗</span>'
            . '<button type="button" class="backup-hub-url" data-copy-text="' . $copyAttr . '">' . $copyAttr . '</button></div>'
            . '<span class="muted" style="font-size:0.72rem">مسیر: ' . $cert . '</span>'
            . $errHtml
            . '<div class="backup-hub-card-actions">'
            . '<a class="btn btn-sm btn-ghost" href="/admin/ssl-backup/' . $sid . '/edit">ویرایش</a>'
            . '<form method="post" action="/admin/ssl-backup/' . $sid . '/run" class="inline-form">' . $csrfField
            . '<button type="submit" class="btn btn-sm btn-primary">بکاپ الان</button></form>'
            . '<form method="post" action="/admin/ssl-backup/' . $sid . '/active" class="inline-form">' . $csrfField
            . '<input type="hidden" name="is_active" value="' . $toggleVal . '">'
            . '<button type="submit" class="btn btn-sm btn-ghost">' . $toggleLabel . '</button></form>'
            . '</div></div>'
            . '<div class="backup-hub-card-side">'
            . '<span class="backup-hub-stat">' . self::backupHubSvg('clock') . ' ' . $lastStr . '</span>'
            . '</div></div></article>';
    }

    private static function backupHubHero(string $title, string $subtitle, string $icon, bool $ssl): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $sub = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
        $iconCls = $ssl ? ' backup-hub-hero-icon ssl' : ' backup-hub-hero-icon';
        $inner = $icon === 'lock' ? '🔒' : self::backupHubSvg('case');

        return '<header class="backup-hub-hero">'
            . '<div class="' . trim($iconCls) . '" aria-hidden="true">' . $inner . '</div>'
            . '<div><h1 class="backup-hub-hero-title">' . $t . '</h1><p class="backup-hub-hero-sub">' . $sub . '</p></div>'
            . '<a class="backup-hub-back" href="/admin/dashboard" aria-label="بازگشت">←</a>'
            . '</header>';
    }

    private static function backupHubFooter(int $lastTs): string
    {
        $updated = $lastTs > 0
            ? htmlspecialchars(Format::fromTimestamp($lastTs), ENT_QUOTES, 'UTF-8')
            : '—';

        return '<footer class="backup-hub-footer">'
            . '<span>' . self::backupHubSvg('shield') . ' فایل‌ها به‌صورت امن نگهداری می‌شوند</span>'
            . '<span>' . self::backupHubSvg('clock') . ' آخرین بروزرسانی: ' . $updated . '</span>'
            . '</footer>';
    }

    private static function backupHubSvg(string $id): string
    {
        $common = 'class="backup-hub-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
        return match ($id) {
            'doc' => '<svg ' . $common . '><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>',
            'clock' => '<svg ' . $common . '><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
            'cal' => '<svg ' . $common . '><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
            'cloud' => '<svg ' . $common . '><path d="M4 14.5A4.5 4.5 0 0 1 12 12a4.5 4.5 0 0 1 8 2.5"/><path d="M8 17h8"/><path d="M12 12v9"/></svg>',
            'db' => '<svg ' . $common . '><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>',
            'down' => '<svg ' . $common . '><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
            'pencil' => '<svg ' . $common . '><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
            'shield' => '<svg ' . $common . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            'case' => '<svg ' . $common . '><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>',
            default => '',
        };
    }

    /** @param array<string, mixed>|null $server */
    public static function adminSslServerEditPage(?array $server, string $flashHtml, string $csrfField): string
    {
        if ($server === null) {
            return self::card('<p class="muted">سرور یافت نشد.</p><p><a href="/admin/ssl-backup">بازگشت</a></p>');
        }
        $sid = (int) $server['id'];
        $auth = (string) ($server['auth_type'] ?? 'password');
        $passSel = $auth === 'key' ? '' : ' selected';
        $keySel = $auth === 'key' ? ' selected' : '';
        $form = '<form class="stack" method="post" action="/admin/ssl-backup/' . $sid . '/edit">' . $csrfField . '
            <label>نام سرور</label>
            <input name="name" required value="' . htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') . '">
            <label>آدرس میزبان</label>
            <input name="host" required dir="ltr" value="' . htmlspecialchars((string) $server['host'], ENT_QUOTES, 'UTF-8') . '">
            <label>پورت SSH</label>
            <input name="ssh_port" type="number" min="1" max="65535" value="' . (int) $server['ssh_port'] . '" dir="ltr">
            <label>کاربر SSH</label>
            <input name="ssh_username" dir="ltr" value="' . htmlspecialchars((string) $server['ssh_username'], ENT_QUOTES, 'UTF-8') . '">
            <label>نوع احراز هویت</label>
            <select name="auth_type">
                <option value="password"' . $passSel . '>رمز عبور</option>
                <option value="key"' . $keySel . '>کلید خصوصی</option>
            </select>
            <label>رمز یا کلید جدید (خالی = بدون تغییر)</label>
            <textarea name="ssh_secret" rows="4" dir="ltr"></textarea>
            <label>مسیر پوشهٔ گواهی</label>
            <input name="cert_path" dir="ltr" value="' . htmlspecialchars((string) $server['cert_path'], ENT_QUOTES, 'UTF-8') . '">
            <div class="sub-mgmt-actions" style="margin-top:0.75rem">
                <button class="btn btn-primary" type="submit">ذخیره</button>
                <a class="btn btn-secondary" href="/admin/ssl-backup">بازگشت</a>
            </div>
        </form>';
        $inner = '<div class="backup-hub-page">' . $flashHtml . '<div class="backup-hub-form-card">' . $form . '</div></div>';
        return $inner;
    }

    /**
     * @param array{token:string, enabled:bool, admin_chat_id:string, proxy_enabled:bool, proxy_url:string, v2ray_config:string, bot_info:array{ok:bool, username?:string, name?:string, error?:string}} $state
     */
    public static function adminTelegramPage(string $activeTab, string $flashHtml, array $state, string $csrfField): string
    {
        $tabs = [
            'overview' => 'نمای کلی',
            'setup' => 'ستاپ ربات',
            'proxy' => 'پروکسی',
        ];
        $nav = '';
        foreach ($tabs as $key => $label) {
            $cls = $key === $activeTab ? 'active' : '';
            $nav .= '<a class="tg-tab ' . $cls . '" href="/admin/telegram?tab=' . $key . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        $content = '';
        if ($activeTab === 'overview') {
            $botLine = '—';
            $statusCls = 'tg-pill off';
            $statusLabel = 'قطع';
            if ($state['bot_info']['ok']) {
                $botLine = htmlspecialchars(trim($state['bot_info']['username'] . ' ' . ($state['bot_info']['name'] ?? '')), ENT_QUOTES, 'UTF-8');
                $statusCls = 'tg-pill on';
                $statusLabel = 'متصل';
            } elseif (($state['token'] ?? '') !== '') {
                $botLine = htmlspecialchars((string) ($state['bot_info']['error'] ?? 'خطا'), ENT_QUOTES, 'UTF-8');
                $statusCls = 'tg-pill warn';
                $statusLabel = 'خطا';
            }
            $content = '<div class="tg-overview-grid">
                <div class="tg-stat-card"><span class="tg-stat-label">وضعیت API</span><span class="' . $statusCls . '">' . $statusLabel . '</span></div>
                <div class="tg-stat-card"><span class="tg-stat-label">ربات</span><span class="tg-stat-val">' . $botLine . '</span></div>
                <div class="tg-stat-card"><span class="tg-stat-label">توکن</span><span class="tg-stat-val mono">' . htmlspecialchars(\App\Services\TelegramService::maskToken($state['token']), ENT_QUOTES, 'UTF-8') . '</span></div>
                <div class="tg-stat-card"><span class="tg-stat-label">اعلان مشتری</span><span class="tg-stat-val">' . ($state['enabled'] ? 'فعال' : 'غیرفعال') . '</span></div>
                <div class="tg-stat-card"><span class="tg-stat-label">Chat ID ادمین</span><span class="tg-stat-val">' . htmlspecialchars($state['admin_chat_id'] !== '' ? $state['admin_chat_id'] : '—', ENT_QUOTES, 'UTF-8') . '</span></div>
                <div class="tg-stat-card"><span class="tg-stat-label">پروکسی</span><span class="tg-stat-val">' . ($state['proxy_enabled'] ? htmlspecialchars($state['proxy_url'], ENT_QUOTES, 'UTF-8') : 'مستقیم (بدون پروکسی)') . '</span></div>
            </div>
            <form method="post" action="/admin/telegram?tab=overview" class="toolbar" style="margin-top:1rem">' . $csrfField . '
                <input type="hidden" name="action" value="test">
                <button class="btn btn-primary" type="submit">تست اتصال و ارسال پیام</button>
            </form>
            <p class="muted form-hint">پیام تست به Chat ID ادمین (تب ستاپ) ارسال می‌شود.</p>';
        } elseif ($activeTab === 'setup') {
            $tokenEsc = htmlspecialchars($state['token'], ENT_QUOTES, 'UTF-8');
            $chatEsc = htmlspecialchars($state['admin_chat_id'], ENT_QUOTES, 'UTF-8');
            $chk = $state['enabled'] ? ' checked' : '';
            $content = '<form class="stack" method="post" action="/admin/telegram?tab=setup">' . $csrfField . '
                <input type="hidden" name="action" value="save_setup">
                <label>توکن ربات (از @BotFather)</label>
                <input name="telegram_bot_token" value="' . $tokenEsc . '" autocomplete="off" placeholder="123456789:AAH...">
                <label>Chat ID ادمین / تست</label>
                <input name="telegram_admin_chat_id" value="' . $chatEsc . '" placeholder="مثلاً 123456789 یا -100...">
                <p class="muted form-hint">برای دریافت Chat ID به ربات @userinfobot پیام دهید یا از گروه استفاده کنید.</p>
                <label class="check-row"><input type="checkbox" name="telegram_notifications_enabled" value="1"' . $chk . '> ارسال اعلان مصرف به مشتریان (Chat ID در پروفایل هر کاربر)</label>
                <button class="btn btn-primary" type="submit">ذخیره ستاپ</button>
            </form>';
        } else {
            $proxyUrl = htmlspecialchars($state['proxy_url'], ENT_QUOTES, 'UTF-8');
            $v2ray = htmlspecialchars($state['v2ray_config'], ENT_QUOTES, 'UTF-8');
            $proxyOn = $state['proxy_enabled'] ? ' checked' : '';
            $content = '<form class="stack" method="post" action="/admin/telegram?tab=proxy">' . $csrfField . '
                <input type="hidden" name="action" value="save_proxy">
                <p class="muted form-hint">سرور در ایران: ابتدا Xray/V2Ray را روی همین VPS با کانفیگ خودتان اجرا کنید (inbound SOCKS یا HTTP روی localhost). سپس آدرس پروکسی محلی را اینجا وارد کنید.</p>
                <label class="check-row"><input type="checkbox" name="telegram_proxy_enabled" value="1"' . $proxyOn . '> استفاده از پروکسی برای api.telegram.org</label>
                <label>آدرس پروکسی (برای cURL)</label>
                <input name="telegram_proxy_url" value="' . $proxyUrl . '" placeholder="socks5h://127.0.0.1:10808">
                <p class="muted form-hint">مثال‌ها: <code>socks5h://127.0.0.1:10808</code> · <code>http://127.0.0.1:10809</code></p>
                <label>کانفیگ V2Ray / Xray (JSON) — ذخیره در پنل</label>
                <textarea name="telegram_v2ray_config" rows="12" placeholder="{\"outbounds\":[...]}">' . $v2ray . '</textarea>
                <p class="muted form-hint">این JSON فقط در JaySub ذخیره می‌شود؛ سرویس Xray را جداگانه با systemd یا دستی اجرا کنید و پورت inbound را در فیلد بالا بزنید.</p>
                <div class="form-actions-row">
                    <button class="btn btn-primary" type="submit">ذخیره پروکسی</button>
                </div>
            </form>
            <form method="post" action="/admin/telegram?tab=proxy" class="toolbar" style="margin-top:0.75rem">' . $csrfField . '
                <input type="hidden" name="action" value="test">
                <button class="btn btn-secondary" type="submit">تست اتصال از طریق پروکسی</button>
            </form>';
        }

        return '<div class="tg-admin-page">' . $flashHtml . '
            <nav class="tg-tabs" aria-label="تب‌های تلگرام">' . $nav . '</nav>
            <section class="ui-card tg-tab-panel">' . $content . '</section>
        </div>';
    }

    private static function adminDashStatCard(
        string $label,
        string $value,
        string $badge,
        string $icon,
        bool $showBadge,
    ): string {
        $badgeHtml = $showBadge && $badge !== ''
            ? '<span class="ads-badge"><span class="ads-badge-dot"></span>' . htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') . '</span>'
            : '';
        return '<article class="admin-dash-stat">
            <div class="ads-icon ads-icon-' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '">' . self::adminDashIcon($icon) . '</div>
            <div class="ads-body">
                <div class="ads-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>
                <div class="ads-value-row">
                    <span class="ads-value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>
                    ' . $badgeHtml . '
                </div>
            </div>
        </article>';
    }

    private static function adminDashAction(string $href, string $label, string $icon, bool $accent = false): string
    {
        $cls = 'admin-dash-action' . ($accent ? ' accent' : '');
        return '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">
            <span class="ada-chevron" aria-hidden="true">‹</span>
            <span class="ada-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>
            <span class="ada-icon">' . self::adminDashIcon($icon) . '</span>
        </a>';
    }

    private static function adminDashIcon(string $kind): string
    {
        return match ($kind) {
            'plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>',
            'hex' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l8.5 5v10L12 22l-8.5-5V7L12 2z"/></svg>',
            'service' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 10h8M8 14h5"/></svg>',
            'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 2.5-5 6-5s6 2 6 5M16 8a3 3 0 110 6M21 20c0-2.5-1.5-4-4-4"/></svg>',
            'chart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 18V8M10 18V4M16 18v-6M22 18V10"/></svg>',
            'bell' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 8a6 6 0 0112 0v5l2 2H4l2-2V8"/><path d="M10 19a2 2 0 004 0"/></svg>',
            'users-active' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="3"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/></svg>',
            'users-total' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="9" r="2.5"/><circle cx="16" cy="9" r="2.5"/><path d="M4 19c0-2.5 2-4 4-4M16 15c2 0 4 1.5 4 4"/></svg>',
            'panels' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l8.5 5v10L12 22l-8.5-5V7L12 2z"/></svg>',
            'services' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 7h11M8 12h11M8 17h11M5 7h.01M5 12h.01M5 17h.01"/></svg>',
            default => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/></svg>',
        };
    }

    /**
     * @param list<array<string, mixed>> $panels
     */
    public static function adminXuiPanelsPage(
        string $flashError,
        string $flashSuccess,
        string $csrfField,
        string $customerOptionsHtml,
        array $panels,
    ): string {
        $connected = 0;
        foreach ($panels as $p) {
            if (($p['connection_status'] ?? '') === 'connected') {
                ++$connected;
            }
        }
        $total = count($panels);

        $panelChips = '';
        foreach ($panels as $p) {
            $conn = (string) ($p['connection_status'] ?? '');
            $dot = match ($conn) {
                'connected' => 'on',
                'sync_error' => 'warn',
                default => 'off',
            };
            $connLabel = $conn === 'connected' ? 'متصل' : ($conn === 'sync_error' ? 'خطای sync' : 'قطع');
            $internetCut = (int) ($p['internet_cut'] ?? 0) === 1;
            $netClass = $internetCut ? ' internet-cut' : '';
            $netBadge = $internetCut
                ? '<span class="xui-panel-tag xui-panel-tag-off">اینترنت قطع</span>'
                : '<span class="xui-panel-tag xui-panel-tag-on">اینترنت وصل</span>';
            $netBtn = $internetCut
                ? '<button type="submit" class="btn btn-sm btn-primary">وصل اینترنت</button>'
                : '<button type="submit" class="btn btn-sm btn-secondary">قطع اینترنت</button>';
            $nameEsc = htmlspecialchars((string) ($p['name'] ?? 'پنل'), ENT_QUOTES, 'UTF-8');
            $custLabel = trim((string) ($p['customer_username'] ?? ''));
            if ($custLabel === '') {
                $custLabel = (string) ($p['customer_name'] ?? '—');
            }
            $cust = htmlspecialchars($custLabel, ENT_QUOTES, 'UTF-8');
            $pid = (int) $p['id'];
            $baseUrl = (string) ($p['base_url'] ?? '');
            $baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
            $baseAttr = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
            $traffic = Format::bytesAuto((float) ($p['traffic_bytes'] ?? 0));
            $err = '';
            if (!empty($p['last_error'])) {
                $err = '<p class="muted sub-mgmt-panel-err">' . htmlspecialchars((string) $p['last_error'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $panelChips .= '<article class="xui-panel-item' . $netClass . '">
                <div class="xui-panel-link-row">
                    <span class="xui-panel-dot ' . $dot . '" title="' . htmlspecialchars($connLabel, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($connLabel, ENT_QUOTES, 'UTF-8') . '"></span>
                    <strong class="xui-panel-name">' . $nameEsc . '</strong>
                    <button type="button" class="xui-panel-url" data-copy-text="' . $baseAttr . '" title="کلیک برای کپی آدرس">' . $baseEsc . '</button>
                </div>
                <div class="xui-panel-footer">
                    <div class="xui-panel-tags">
                        <span class="xui-panel-tag">' . $cust . '</span>
                        ' . $netBadge . '
                        <span class="xui-panel-tag xui-panel-tag-traffic" title="مصرف sync‌شده">' . htmlspecialchars($traffic, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="sub-mgmt-panel-actions">
                        <form method="post" action="/admin/panels/' . $pid . '/toggle-internet" class="inline-form">' . $csrfField
                . $netBtn . '</form>
                        <a class="btn btn-sm btn-ghost" href="/admin/panels/' . $pid . '/clients">کلاینت‌ها</a>
                        <a class="btn btn-sm btn-ghost" href="/admin/panels/' . $pid . '/edit">ویرایش</a>
                        <form method="post" action="/admin/panels/' . $pid . '/test" class="inline-form">' . $csrfField
                . '<button type="submit" class="btn btn-sm btn-primary">تست</button></form>
                    </div>
                </div>
            </article>' . $err;
        }
        if ($panelChips === '') {
            $panelChips = '<p class="muted sub-mgmt-empty">هنوز پنلی ثبت نشده — «اتصال پنل جدید» را بزنید.</p>';
        }

        $connectForm = '<form class="stack sub-mgmt-connect-form" method="post" action="/admin/panels">' . $csrfField . '
                    <label>مشتری (سرویس)</label>
                    <select name="customer_id" required>' . $customerOptionsHtml . '</select>
                    <label>نام پنل</label>
                    <input name="name" required placeholder="مثلاً Bell1">
                    <label>آدرس پنل</label>
                    <input name="base_url" required placeholder="https://example.com:2415/SecretPath">
                    <label>API Token</label>
                    <input name="api_token" required autocomplete="off" placeholder="توکن از Panel settings → API Tokens">
                    <div class="sub-mgmt-actions" style="margin-top:0.75rem">
                        <button class="btn btn-primary block sub-mgmt-btn-primary" type="submit" name="action" value="save">ذخیره پنل</button>
                        <button class="btn btn-secondary block" type="submit" name="action" value="save_test">ذخیره و تست اتصال</button>
                    </div>
                </form>';

        $flashModal = '';
        if ($flashSuccess !== '') {
            $flashModal = self::adminFlashToastModal($flashSuccess, 'success');
        } elseif ($flashError !== '') {
            $flashModal = self::adminFlashToastModal($flashError, 'error');
        }

        return '<div class="sub-mgmt-page xui-panels-page">' . $flashModal . '
            <section class="ui-card sub-mgmt-hero sub-mgmt-panels-hero">
                <div class="sub-mgmt-hero-icon" aria-hidden="true">⬡</div>
                <div class="sub-mgmt-hero-main">
                    <h3 class="sub-mgmt-username">مدیریت پنل‌های XUI</h3>
                    <p class="muted sub-mgmt-hero-sub">' . $connected . ' متصل از ' . $total . ' پنل ثبت‌شده</p>
                </div>
                <div class="sub-mgmt-days sub-mgmt-days-muted">
                    <span class="sub-mgmt-days-label">MHSanaei / 3x-ui</span>
                </div>
            </section>

            <section class="ui-card sub-mgmt-card">
                <h2 class="card-title sub-mgmt-card-title"><span class="sub-mgmt-ico">⬡</span> پنل‌های ثبت‌شده</h2>
                <div class="sub-mgmt-panels xui-panels-list">' . $panelChips . '</div>
            </section>

            <div class="app-modal" id="panel-connect-modal" hidden>
                <div class="app-modal-backdrop" data-close-modal tabindex="-1" aria-hidden="true"></div>
                <div class="app-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="panel-connect-title">
                    <button type="button" class="app-modal-close" data-close-modal aria-label="بستن">×</button>
                    <h2 class="card-title sub-mgmt-card-title" id="panel-connect-title"><span class="sub-mgmt-ico">＋</span> اتصال پنل جدید</h2>
                    ' . $connectForm . '
                </div>
            </div>
        </div>';
    }

    private static function adminFlashToastModal(string $message, string $variant): string
    {
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $cls = $variant === 'success' ? 'app-toast-success' : 'app-toast-error';
        $icon = $variant === 'success' ? '✓' : '!';

        return '<div class="app-modal app-flash-toast" id="panels-flash-toast" data-auto-open hidden>
                <div class="app-modal-backdrop" data-close-modal tabindex="-1" aria-hidden="true"></div>
                <div class="app-modal-dialog app-toast-dialog" role="alertdialog" aria-modal="true">
                    <div class="app-toast-icon ' . $cls . '" aria-hidden="true">' . $icon . '</div>
                    <p class="app-toast-msg ' . $cls . '">' . $msg . '</p>
                    <button type="button" class="btn btn-primary app-toast-ok" data-close-modal>باشه</button>
                </div>
            </div>';
    }

    private static function statusPill(string $statusKey): string
    {
        $map = [
            'active' => ['sub-mgmt-pill active', 'فعال'],
            'exhausted' => ['sub-mgmt-pill danger', 'اتمام حجم'],
            'disabled' => ['sub-mgmt-pill danger', 'غیرفعال'],
            'expired' => ['sub-mgmt-pill danger', 'منقضی'],
            'warning' => ['sub-mgmt-pill warn', 'هشدار'],
            'inactive' => ['sub-mgmt-pill danger', 'غیرفعال'],
        ];
        [$cls, $label] = $map[$statusKey] ?? ['sub-mgmt-pill warn', $statusKey];
        return '<span class="' . $cls . '"><span class="sub-mgmt-pill-dot"></span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
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
            'telegram' => '✈',
            'backup' => '💾',
            'ssl_backup' => '🔒',
            'settings' => '⚙',
            default => '•',
        };
    }
}
