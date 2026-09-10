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
        return htmlspecialchars(date('Y/m/d', $ts), ENT_QUOTES, 'UTF-8');
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
                <span>مصرف: <strong>' . Format::bytesToGb($used) . ' / ' . Format::bytesToGb($quota) . '</strong></span>
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
                $connLabel = 'غیرفعال';
                $dot = 'off';
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
            <header class="admin-dash-top">
                <h2 class="admin-dash-title">داشبورد</h2>
                <button type="button" class="admin-dash-menu" aria-label="منو" onclick="document.body.classList.toggle(\'sidebar-open\')">
                    <span></span><span></span><span></span>
                </button>
            </header>

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
        string $flashHtml,
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
            $cust = htmlspecialchars((string) ($p['customer_username'] ?? ''), ENT_QUOTES, 'UTF-8');
            $pid = (int) $p['id'];
            $err = '';
            if (!empty($p['last_error'])) {
                $err = '<p class="muted sub-mgmt-panel-err">' . htmlspecialchars((string) $p['last_error'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $panelChips .= '<a class="sub-mgmt-panel-chip sub-mgmt-panel-chip-link" href="/admin/panels/' . $pid . '/clients">
                <span class="sub-mgmt-panel-dot ' . $dot . '"></span>
                <span class="sub-mgmt-panel-name">' . htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8') . '</span>
                <span class="sub-mgmt-panel-state">' . htmlspecialchars($connLabel, ENT_QUOTES, 'UTF-8') . '</span>
                <span class="sub-mgmt-panel-cust muted">' . $cust . '</span>
                <span class="sub-mgmt-chevron" aria-hidden="true">‹</span>
            </a>' . $err
                . '<div class="sub-mgmt-panel-actions">
                    <a class="btn btn-sm btn-ghost" href="/admin/panels/' . $pid . '/edit">ویرایش</a>
                    <form method="post" action="/admin/panels/' . $pid . '/test" class="inline-form">' . $csrfField
                . '<button type="submit" class="btn btn-sm btn-primary">تست</button></form>
                </div>';
        }
        if ($panelChips === '') {
            $panelChips = '<p class="muted sub-mgmt-empty">هنوز پنلی ثبت نشده — فرم پایین را پر کنید.</p>';
        }

        return '<div class="sub-mgmt-page">' . $flashHtml . '
            <header class="sub-mgmt-toolbar">
                <a class="sub-mgmt-back" href="/admin/dashboard" aria-label="بازگشت">←</a>
                <h2 class="sub-mgmt-title">پنل‌های 3X-UI</h2>
                <span class="sub-mgmt-menu sub-mgmt-menu-placeholder" aria-hidden="true"></span>
            </header>

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
                <div class="sub-mgmt-panels">' . $panelChips . '</div>
            </section>

            <section class="ui-card sub-mgmt-card">
                <h2 class="card-title sub-mgmt-card-title"><span class="sub-mgmt-ico">＋</span> اتصال پنل جدید</h2>
                <form class="stack sub-mgmt-connect-form" method="post" action="/admin/panels">' . $csrfField . '
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
                </form>
            </section>
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
            'settings' => '⚙',
            default => '•',
        };
    }
}
