<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

require dirname(__DIR__) . '/vendor/autoload.php';
\App\View\Assets::serveIfRequested($uri);

try {
    $config = require dirname(__DIR__) . '/src/bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "JaySub bootstrap error.\n";
    echo "Run on server: bash /var/www/vpn-panel/scripts/doctor\n";
    exit;
}

use App\Auth\AuthService;
use App\Core\Csrf;
use App\Core\Encryption;
use App\Core\Format;
use App\Core\Response;
use App\Core\Database;
use App\Core\Session;
use App\Services\CustomerService;
use App\Services\PanelService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Services\TrafficSyncService;
use App\Services\DashboardService;
use App\View\Layout;

/** @param array<string, mixed> $config */
function app_encryption(array $config): Encryption
{
    static $instance = null;
    if ($instance === null) {
        $instance = new Encryption((string) $config['security']['encryption_key']);
    }
    return $instance;
}

/** @param array<string, mixed> $config */
function app_telegram(array $config): TelegramService
{
    static $instance = null;
    if ($instance === null) {
        $instance = new TelegramService(SettingsService::get('telegram_bot_token'));
    }
    return $instance;
}

$maxAttempts = (int) ($config['security']['login_max_attempts'] ?? 5);
$lockout = (int) ($config['security']['login_lockout_minutes'] ?? 15);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requireCsrf(): void
{
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $body = Layout::card('<div class="alert alert-error">توکن امنیتی نامعتبر است.</div>');
        Response::html(Layout::admin('خطا', 'dashboard', $body), 403);
    }
}

function adminPage(string $title, string $activeNav, string $content): void
{
    Response::html(Layout::admin($title, $activeNav, $content));
}

function customerPage(string $title, string $activeNav, string $content): void
{
    Response::html(Layout::customer($title, $activeNav, $content));
}

function adminPlaceholder(string $title, string $activeNav, string $description): void
{
    requireAdmin();
    $body = Layout::card(
        '<div class="placeholder-page"><div class="big-ico">◈</div><p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p><p class="muted">این بخش در نسخه‌های بعدی تکمیل می‌شود.</p></div>'
    );
    adminPage($title, $activeNav, $body);
}

function customerPlaceholder(string $title, string $activeNav, string $description): void
{
    requireCustomer();
    $body = Layout::card(
        '<div class="placeholder-page"><div class="big-ico">◈</div><p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p></div>'
    );
    customerPage($title, $activeNav, $body);
}

function serviceStatusBadge(string $status): string
{
    $map = [
        'active' => ['badge-success', 'فعال'],
        'exhausted' => ['badge-danger', 'قطع‌شده / اتمام حجم'],
        'disabled' => ['badge-danger', 'غیرفعال'],
        'expired' => ['badge-danger', 'منقضی'],
        'warning' => ['badge-warning', 'هشدار مصرف'],
        'inactive' => ['badge-danger', 'غیرفعال'],
    ];
    [$cls, $label] = $map[$status] ?? ['badge-warning', $status];
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

function alertTypeLabel(string $type): string
{
    return match ($type) {
        'warning_1' => 'رسیدن به آستانه هشدار ۱',
        'warning_2' => 'رسیدن به آستانه هشدار ۲',
        'limit_reached' => 'اتمام حجم سرویس',
        'quota_recharged' => 'شارژ مجدد حجم',
        'service_restored' => 'بازگردانی سرویس',
        default => $type,
    };
}

function requireAdmin(): void
{
    if (AuthService::adminId() === null) {
        Response::redirect('/admin/login');
    }
}

function requireCustomer(): void
{
    if (AuthService::customerId() === null) {
        Response::redirect('/login');
    }
}

// --- Customer auth ---
if ($uri === '/login' && $method === 'GET') {
    if (isset($_GET['e'])) {
        Response::redirect('/login');
    }
    if (AuthService::customerId()) {
        Response::redirect('/dashboard');
    }
    $loginErr = Session::get('login_error_customer');
    Session::remove('login_error_customer');
    $msg = is_string($loginErr) ? $loginErr : null;
    Response::html(Layout::loginPage('ورود مشتری', '/login', 'customer', $msg));
}

if ($uri === '/login' && $method === 'POST') {
    requireCsrf();
    $failMsg = 'نام کاربری یا رمز اشتباه است.';
    try {
        $ok = AuthService::loginCustomer(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', $maxAttempts, $lockout);
        if ($ok) {
            Response::redirect('/dashboard');
        }
        Session::set('login_error_customer', $failMsg);
        Response::redirect('/login');
    } catch (Throwable $e) {
        error_log('JaySub customer login: ' . $e->getMessage());
        Session::set('login_error_customer', 'خطای سرور. روی VPS: bash scripts/reset-admin');
        Response::redirect('/login');
    }
}

if ($uri === '/logout') {
    AuthService::logout();
    Response::redirect('/login');
}

if ($uri === '/dashboard' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $customer = CustomerService::findById($cid);
    $sub = CustomerService::activeSubscription($cid);
    if (!$customer || !$sub) {
        customerPage('داشبورد', 'home', Layout::card('<p class="muted">سرویس فعالی برای حساب شما ثبت نشده است.</p>'));
        return;
    }

    $upload = (int) $sub['used_upload_bytes'];
    $download = (int) $sub['used_download_bytes'];
    $total = $upload + $download;
    $quota = (int) $sub['quota_bytes'];
    $pct = Format::percent($total, $quota);
    $remaining = max(0, $quota - $total);
    $vpnReady = (int) $customer['vpn_enabled'] === 1 && $sub['status'] === 'active' && $pct < 100;
    $dotClass = $vpnReady ? 'on' : 'off';
    $connLabel = $vpnReady ? 'فعال' : 'قطع‌شده';

    $endsAt = $sub['ends_at'] ?? null;
    $expiryText = $endsAt ? Format::jalaliOrGregorian((string) $endsAt) : '—';
    $statusKey = (string) $customer['service_status'];
    if ($sub['status'] === 'exhausted' || $pct >= 100) {
        $statusKey = 'exhausted';
    }
    $statusBadge = serviceStatusBadge($statusKey);
    $subLink = DashboardService::subscriptionLinkForCustomer($cid);
    $userName = htmlspecialchars((string) $customer['name'], ENT_QUOTES, 'UTF-8');

    $body = '
    <p class="customer-greeting">سلام، <strong>' . $userName . '</strong></p>
    <section class="vpn-status-card">
        <span class="status-dot ' . $dotClass . '"></span>
        <h2>وضعیت سرویس</h2>
        <p>' . $statusBadge . ' · ' . htmlspecialchars($connLabel, ENT_QUOTES, 'UTF-8') . '</p>
    </section>
    ' . Layout::usageProgress((float) $total, (float) $quota, $pct) . '
    ' . Layout::card('
        <div class="data-card-row"><span>حجم کل</span><span>' . Format::bytesToGb($quota) . '</span></div>
        <div class="data-card-row"><span>مصرف‌شده</span><span>' . Format::bytesToGb($total) . '</span></div>
        <div class="data-card-row"><span>باقی‌مانده</span><span>' . Format::bytesToGb($remaining) . '</span></div>
        <div class="data-card-row"><span>تاریخ انقضا</span><span>' . htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') . '</span></div>
    ', 'اشتراک من') . '
    ' . Layout::subscriptionLinkCard($subLink) . '
    <div class="action-grid">
        <a class="action-tile" href="/app/subscription"><span class="at-ico">◈</span><span>جزئیات اشتراک</span></a>
        <a class="action-tile" href="/app/profile"><span class="at-ico">👤</span><span>پروفایل</span></a>
    </div>';
    customerPage('داشبورد', 'home', $body);
}

// --- Customer app pages ---
if ($uri === '/app/subscription' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $customer = CustomerService::findById($cid);
    $sub = CustomerService::activeSubscription($cid);
    if (!$sub || !$customer) {
        customerPage('اشتراک من', 'subscription', Layout::card('<p class="muted">اشتراک فعالی ثبت نشده است.</p>'));
        return;
    }
    $total = (int) $sub['used_upload_bytes'] + (int) $sub['used_download_bytes'];
    $quota = (int) $sub['quota_bytes'];
    $pct = Format::percent($total, $quota);
    $endsAt = $sub['ends_at'] ?? null;
    $expiryText = $endsAt ? Format::jalaliOrGregorian((string) $endsAt) : '—';
    $body = Layout::usageProgress((float) $total, (float) $quota, $pct)
        . Layout::card('
        <div class="data-card-row"><span>حجم کل</span><span>' . Format::bytesToGb($quota) . '</span></div>
        <div class="data-card-row"><span>مصرف‌شده</span><span>' . Format::bytesToGb($total) . '</span></div>
        <div class="data-card-row"><span>باقی‌مانده</span><span>' . Format::bytesToGb(max(0, $quota - $total)) . '</span></div>
        <div class="data-card-row"><span>انقضا</span><span>' . htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') . '</span></div>
        <div class="data-card-row"><span>وضعیت</span><span>' . serviceStatusBadge((string) $customer['service_status']) . '</span></div>
    ', 'اشتراک فعال')
        . Layout::subscriptionLinkCard(DashboardService::subscriptionLinkForCustomer($cid));
    customerPage('اشتراک من', 'subscription', $body);
}

if ($uri === '/app/profile' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $customer = CustomerService::findById($cid);
    if (!$customer) {
        Response::redirect('/login');
    }
    $mobile = htmlspecialchars((string) ($customer['mobile'] ?? '—'), ENT_QUOTES, 'UTF-8');
    $body = Layout::card('
        <div class="data-card-row"><span>نام</span><span>' . htmlspecialchars((string) $customer['name'], ENT_QUOTES, 'UTF-8') . '</span></div>
        <div class="data-card-row"><span>نام کاربری</span><span>' . htmlspecialchars((string) $customer['username'], ENT_QUOTES, 'UTF-8') . '</span></div>
        <div class="data-card-row"><span>تماس</span><span>' . $mobile . '</span></div>
        <p style="margin-top:1.25rem"><a class="btn btn-secondary block" href="/logout">خروج از حساب</a></p>
    ', 'پروفایل');
    customerPage('پروفایل', 'profile', $body);
}

if (in_array($uri, ['/app/buy', '/app/renew', '/app/messages', '/app/more', '/app/support', '/app/link'], true) && $method === 'GET') {
    Response::redirect('/dashboard');
}

// --- Admin ---
if ($uri === '/admin/login' && $method === 'GET') {
    if (isset($_GET['e'])) {
        Response::redirect('/admin/login');
    }
    if (AuthService::adminId()) {
        Response::redirect('/admin/dashboard');
    }
    $loginErr = Session::get('login_error_admin');
    Session::remove('login_error_admin');
    $msg = is_string($loginErr) ? $loginErr : null;
    Response::html(Layout::loginPage('ورود مدیریت', '/admin/login', 'admin', $msg));
}

if ($uri === '/admin/login' && $method === 'POST') {
    requireCsrf();
    $failMsg = 'نام کاربری یا رمز اشتباه است. اگر مطمئنید: bash /var/www/vpn-panel/scripts/reset-admin \'RamzShoma\'';
    try {
        $ok = AuthService::loginAdmin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', $maxAttempts, $lockout);
        if ($ok) {
            Response::redirect('/admin/dashboard');
        }
        Session::set('login_error_admin', $failMsg);
        Response::redirect('/admin/login');
    } catch (Throwable $e) {
        error_log('JaySub admin login: ' . $e->getMessage());
        Session::set('login_error_admin', 'خطای دیتابیس. روی سرور: cd /var/www/vpn-panel && bash scripts/reset-admin');
        Response::redirect('/admin/login');
    }
}

if ($uri === '/admin/logout') {
    AuthService::logout();
    Response::redirect('/admin/login');
}

if ($uri === '/admin/dashboard' && $method === 'GET') {
    requireAdmin();
    $summary = DashboardService::adminSummary();
    $periods = DashboardService::trafficPeriods();
    $chart = DashboardService::trafficChartLastDays(7);
    foreach ($chart as &$pt) {
        $pt['label'] = substr($pt['label'], 5);
    }
    unset($pt);

    $body = '
    <section class="traffic-hero ui-card">
        <div class="traffic-hero-label">مصرف کل اینترنت</div>
        <div class="traffic-hero-value">' . Format::bytesAuto((float) $summary['total_traffic']) . '</div>
        <div class="period-pills">
            <span><b>امروز</b> ' . Format::bytesAuto((float) $periods['today']) . '</span>
            <span><b>هفته</b> ' . Format::bytesAuto((float) $periods['week']) . '</span>
            <span><b>ماه</b> ' . Format::bytesAuto((float) $periods['month']) . '</span>
        </div>
        ' . Layout::barChart($chart) . '
    </section>
    ' . Layout::statGrid(
        Layout::statCard('کاربران کل', (string) $summary['customers'])
        . Layout::statCard('کاربران فعال', (string) $summary['active'], 'tone-success')
        . Layout::statCard('سرویس‌های فعال', (string) $summary['services'])
        . Layout::statCard('پنل‌های متصل', (string) $summary['panels_connected'])
    )
    . Layout::hubGrid(
        Layout::hubTile('/admin/customers/new', '＋', 'ایجاد کاربر', true)
        . Layout::hubTile('/admin/panels', '⬡', 'پنل‌های XUI')
        . Layout::hubTile('/admin/services', '◈', 'راه‌اندازی سرویس')
        . Layout::hubTile('/admin/customers', '👤', 'کاربران')
        . Layout::hubTile('/admin/reports', '📊', 'گزارش مصرف')
        . Layout::hubTile('/admin/notifications', '🔔', 'اعلان‌ها')
        . Layout::hubTile('/admin/settings', '⚙', 'تنظیمات')
        . Layout::hubTile('/admin/customers', '🎧', 'پشتیبانی')
    );
    adminPage('داشبورد', 'dashboard', $body);
}

if ($uri === '/admin/panels' && $method === 'GET') {
    requireAdmin();
    $panels = DashboardService::allPanels();
    $customers = CustomerService::listAll();
    $customerOptions = '';
    foreach ($customers as $c) {
        $customerOptions .= '<option value="' . (int) $c['id'] . '">' . htmlspecialchars($c['username'] . ' — ' . $c['name'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    if ($customerOptions === '') {
        $customerOptions = '<option value="">ابتدا یک کاربر ایجاد کنید</option>';
    }

    $flash = Session::get('flash_admin');
    Session::remove('flash_admin');
    $flashHtml = is_string($flash) && $flash !== ''
        ? '<div class="alert alert-error">' . htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';
    $flashOk = Session::get('flash_admin_ok');
    Session::remove('flash_admin_ok');
    if (is_string($flashOk) && $flashOk !== '') {
        $flashHtml = '<div class="alert" style="background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.25)">' . htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    $addForm = '<form class="stack panel-connect-form" method="post" action="/admin/panels">' . Csrf::field() . '
        <label>مشتری (سرویس)</label>
        <select name="customer_id" required>' . $customerOptions . '</select>
        <label>نام پنل</label>
        <input name="name" required placeholder="مثلاً پنل اصلی / آلمان">
        <label>آدرس پنل (Domain یا IP + پورت)</label>
        <input name="base_url" required placeholder="https://185.x.x.x:443 یا https://panel.example.com">
        <p class="muted form-hint">در 3x-ui از منوی تنظیمات، API Token (Bearer) را کپی کنید.</p>
        <label>API Token</label>
        <input name="api_token" required autocomplete="off" placeholder="Bearer token">
        <div class="form-actions-row">
            <button class="btn btn-primary" type="submit" name="action" value="save">ذخیره پنل</button>
            <button class="btn btn-secondary" type="submit" name="action" value="save_test">ذخیره و تست اتصال</button>
        </div>
    </form>';

    $listHtml = '';
    if ($panels === []) {
        $listHtml = '<p class="muted">هنوز پنلی ثبت نشده. فرم بالا را پر کنید.</p>';
    } else {
        $cards = '';
        foreach ($panels as $p) {
            $dot = match ($p['connection_status']) {
                'connected' => '<span class="conn-dot on">●</span> متصل',
                'sync_error' => '<span class="conn-dot warn">●</span> خطا',
                default => '<span class="conn-dot off">●</span> قطع',
            };
            $err = $p['last_error'] ? '<p class="muted panel-err">' . htmlspecialchars((string) $p['last_error'], ENT_QUOTES, 'UTF-8') . '</p>' : '';
            $cards .= '<article class="panel-list-card ui-card">
                <div class="data-card-row"><span>نام</span><strong>' . htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8') . '</strong></div>
                <div class="data-card-row"><span>آدرس</span><span class="mono">' . htmlspecialchars((string) $p['base_url'], ENT_QUOTES, 'UTF-8') . '</span></div>
                <div class="data-card-row"><span>مشتری</span><span>' . htmlspecialchars((string) $p['customer_username'], ENT_QUOTES, 'UTF-8') . '</span></div>
                <div class="data-card-row"><span>وضعیت</span><span>' . $dot . '</span></div>
                ' . $err . '
                <div class="panel-card-actions">
                    <a class="btn btn-sm btn-secondary" href="/admin/panels/' . (int) $p['id'] . '/edit">ویرایش</a>
                    <form method="post" action="/admin/panels/' . (int) $p['id'] . '/test" class="inline-form">' . Csrf::field()
                . '<button type="submit" class="btn btn-sm btn-primary">تست اتصال</button></form>
                    <a class="btn btn-sm btn-ghost" href="/admin/panels/' . (int) $p['id'] . '/clients">کلاینت‌ها</a>
                </div>
            </article>';
        }
        $listHtml = '<div class="panel-list">' . $cards . '</div>';
    }

    $body = $flashHtml
        . Layout::card($addForm, 'اتصال پنل 3X-UI (MHSanaei)')
        . Layout::card($listHtml, 'پنل‌های ثبت‌شده');
    adminPage('پنل‌های XUI', 'panels', $body);
}

if ($uri === '/admin/panels' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $cid = (int) ($_POST['customer_id'] ?? 0);
    if ($cid <= 0) {
        Session::set('flash_admin', 'کاربر را انتخاب کنید.');
        Response::redirect('/admin/panels');
    }
    $panelId = PanelService::create(
        $cid,
        trim($_POST['name'] ?? ''),
        trim($_POST['base_url'] ?? ''),
        $_POST['api_token'] ?? '',
        app_encryption($config),
        AuthService::adminId()
    );
    if (($_POST['action'] ?? '') === 'save_test') {
        $result = PanelService::testConnection($panelId, app_encryption($config));
        if ($result['ok']) {
            Session::set('flash_admin_ok', 'پنل ذخیره شد. وضعیت: ' . $result['message']);
        } else {
            Session::set('flash_admin', 'پنل ذخیره شد اما تست ناموفق: ' . $result['message']);
        }
    } else {
        Session::set('flash_admin_ok', 'پنل با موفقیت ذخیره شد.');
    }
    Response::redirect('/admin/panels');
}

if (preg_match('#^/admin/panels/(\d+)/edit$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $pid = (int) $m[1];
    $panel = PanelService::findById($pid);
    if (!$panel) {
        Response::redirect('/admin/panels');
    }
    $form = '<form class="stack" method="post" action="/admin/panels/' . $pid . '/edit">' . Csrf::field() . '
        <label>نام پنل</label><input name="name" required value="' . htmlspecialchars((string) $panel['name'], ENT_QUOTES, 'UTF-8') . '">
        <label>آدرس پنل</label><input name="base_url" required value="' . htmlspecialchars((string) $panel['base_url'], ENT_QUOTES, 'UTF-8') . '">
        <label>API Token (خالی = بدون تغییر)</label><input name="api_token" autocomplete="off" placeholder="توکن جدید">
        <button class="btn btn-primary" type="submit">ذخیره</button>
        <a class="btn btn-secondary" href="/admin/panels">بازگشت</a>
    </form>';
    adminPage('ویرایش پنل', 'panels', Layout::card($form));
}

if (preg_match('#^/admin/panels/(\d+)/edit$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $pid = (int) $m[1];
    $token = trim($_POST['api_token'] ?? '');
    PanelService::update(
        $pid,
        trim($_POST['name'] ?? ''),
        trim($_POST['base_url'] ?? ''),
        $token !== '' ? $token : null,
        app_encryption($config),
        AuthService::adminId()
    );
    Session::set('flash_admin_ok', 'تغییرات پنل ذخیره شد.');
    Response::redirect('/admin/panels');
}

if (preg_match('#^/admin/panels/(\d+)/test$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $result = PanelService::testConnection((int) $m[1], app_encryption($config));
    if ($result['ok']) {
        Session::set('flash_admin_ok', 'تست اتصال: ' . $result['message']);
    } else {
        Session::set('flash_admin', 'تست اتصال: ' . $result['message']);
    }
    Response::redirect('/admin/panels');
}

if (preg_match('#^/admin/panels/(\d+)/clients$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $pid = (int) $m[1];
    $panel = PanelService::findById($pid);
    if (!$panel) {
        Response::redirect('/admin/panels');
    }
    try {
        $clients = PanelService::discoverClients($pid, app_encryption($config));
    } catch (\Throwable $e) {
        adminPage('خطا', 'panels', Layout::card('<div class="alert alert-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'));
        return;
    }
    $cid = (int) $panel['customer_id'];
    $tableRows = [];
    foreach ($clients as $c) {
        $total = $c['up'] + $c['down'];
        $action = '';
        if (!$c['mapped']) {
            $action = '<form method="post" action="/admin/panels/' . $pid . '/assign" class="inline-form">' . Csrf::field() .
                '<input type="hidden" name="email" value="' . htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8') . '">
                <input type="hidden" name="inbound_id" value="' . $c['inbound_id'] . '">
                <input type="hidden" name="uuid" value="' . htmlspecialchars($c['uuid'] ?? '', ENT_QUOTES, 'UTF-8') . '">
                <input type="hidden" name="protocol" value="' . htmlspecialchars($c['protocol'] ?? '', ENT_QUOTES, 'UTF-8') . '">
                <button class="btn btn-sm btn-primary" type="submit">اختصاص</button></form>';
        }
        $tableRows[] = [
            htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8'),
            Format::bytesToGb($total),
            ($c['enable'] ? 'فعال' : 'غیرفعال') . ($c['mapped'] ? ' · متصل' : ''),
            $action,
        ];
    }
    $html = '<p class="muted">' . htmlspecialchars((string) $panel['name'], ENT_QUOTES, 'UTF-8') . '</p>'
        . Layout::responsiveTable(['ایمیل', 'مصرف', 'وضعیت', ''], $tableRows)
        . '<p><a href="/admin/panels">بازگشت به پنل‌ها</a></p>';
    adminPage('کلاینت‌های پنل', 'panels', Layout::card($html));
}

if ($uri === '/admin/services' && $method === 'GET') {
    requireAdmin();
    $rows = CustomerService::listAll();
    $tableRows = [];
    foreach ($rows as $r) {
        $used = (int) ($r['used_upload_bytes'] ?? 0) + (int) ($r['used_download_bytes'] ?? 0);
        $quota = (int) ($r['quota_bytes'] ?? 0);
        $pct = $quota > 0 ? Format::percent($used, $quota) : 0;
        $tableRows[] = [
            htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8'),
            Format::bytesToGb($used) . ' / ' . Format::bytesToGb($quota),
            $pct . '٪',
            '<a class="btn btn-sm btn-primary" href="/admin/customers/' . (int) $r['id'] . '/service">راه‌اندازی</a>',
        ];
    }
    adminPage('سرویس‌ها', 'services', Layout::card(Layout::responsiveTable(['کاربر', 'مصرف', '٪', ''], $tableRows)));
}

if ($uri === '/admin/reports' && $method === 'GET') {
    requireAdmin();
    $periods = DashboardService::trafficPeriods();
    $byPanel = DashboardService::trafficByPanel();
    $panelRows = '';
    $totalPanels = 0;
    foreach ($byPanel as $p) {
        $totalPanels += $p['bytes'];
        $panelRows .= '<div class="data-card-row"><span>' . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '</span><span>' . Format::bytesAuto((float) $p['bytes']) . '</span></div>';
    }
    $users = CustomerService::listAll();
    $userRows = '';
    foreach ($users as $u) {
        $used = (int) ($u['used_upload_bytes'] ?? 0) + (int) ($u['used_download_bytes'] ?? 0);
        $userRows .= '<div class="data-card-row"><span>' . htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') . '</span><span>' . Format::bytesAuto((float) $used) . '</span></div>';
    }
    $body = Layout::card('
        <div class="data-card-row"><span>امروز</span><span>' . Format::bytesAuto((float) $periods['today']) . '</span></div>
        <div class="data-card-row"><span>این هفته</span><span>' . Format::bytesAuto((float) $periods['week']) . '</span></div>
        <div class="data-card-row"><span>این ماه</span><span>' . Format::bytesAuto((float) $periods['month']) . '</span></div>
        <div class="data-card-row"><span>کل</span><span>' . Format::bytesAuto((float) $periods['total']) . '</span></div>
    ', 'Total Traffic')
        . Layout::card($panelRows . '<div class="data-card-row"><strong>جمع پنل‌ها</strong><strong>' . Format::bytesAuto((float) $totalPanels) . '</strong></div>', 'مصرف هر XUI')
        . Layout::card($userRows, 'مصرف کاربران');
    adminPage('گزارش مصرف', 'reports', $body);
}

if ($uri === '/admin/notifications' && $method === 'GET') {
    requireAdmin();
    $items = DashboardService::notifications(80);
    $rows = [];
    foreach ($items as $n) {
        $rows[] = [
            htmlspecialchars((string) $n['customer_username'], ENT_QUOTES, 'UTF-8'),
            alertTypeLabel((string) $n['alert_type']),
            Format::jalaliOrGregorian((string) $n['sent_at']),
        ];
    }
    adminPage('اعلان‌ها', 'notifications', Layout::card(
        $rows === [] ? '<p class="muted">هنوز اعلانی ثبت نشده. هشدارها پس از sync و رسیدن به آستانه در Telegram و اینجا ثبت می‌شوند.</p>'
            : Layout::responsiveTable(['کاربر', 'رویداد', 'زمان'], $rows)
    ));
}

if (in_array($uri, ['/admin/subscriptions', '/admin/sales', '/admin/payments', '/admin/messages'], true) && $method === 'GET') {
    Response::redirect('/admin/services');
}

if ($uri === '/admin/customers' && $method === 'GET') {
    requireAdmin();
    $q = trim($_GET['q'] ?? '');
    $statusFilter = trim($_GET['status'] ?? '');
    $rows = CustomerService::listAll();
    $tableRows = [];
    foreach ($rows as $r) {
        if ($q !== '' && stripos($r['username'] . $r['name'], $q) === false) {
            continue;
        }
        if ($statusFilter !== '' && (string) $r['service_status'] !== $statusFilter) {
            continue;
        }
        $used = (int) ($r['used_upload_bytes'] ?? 0) + (int) ($r['used_download_bytes'] ?? 0);
        $quota = (int) ($r['quota_bytes'] ?? 0);
        $pct = $quota > 0 ? Format::percent($used, $quota) . '٪' : '—';
        $ends = $r['ends_at'] ?? null;
        $expiry = $ends ? Format::jalaliOrGregorian((string) $ends) : '—';
        $tableRows[] = [
            htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8'),
            Format::bytesToGb($used) . ' / ' . Format::bytesToGb($quota),
            $pct,
            $expiry,
            serviceStatusBadge((string) $r['service_status']),
            '<a class="btn btn-sm btn-secondary" href="/admin/customers/' . (int) $r['id'] . '">مدیریت</a>',
        ];
    }
    $html = '<form class="toolbar filters" method="get" action="/admin/customers">
        <input name="q" placeholder="جستجو..." value="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '">
        <select name="status"><option value="">همه وضعیت‌ها</option>
        <option value="active"' . ($statusFilter === 'active' ? ' selected' : '') . '>فعال</option>
        <option value="exhausted"' . ($statusFilter === 'exhausted' ? ' selected' : '') . '>قطع‌شده</option></select>
        <button class="btn btn-secondary" type="submit">فیلتر</button></form>
        <p class="toolbar"><a class="btn btn-primary" href="/admin/customers/new">ایجاد کاربر</a></p>'
        . Layout::responsiveTable(['کاربر', 'مصرف', '٪', 'انقضا', 'وضعیت', ''], $tableRows);
    adminPage('کاربران', 'users', Layout::card($html));
}

if ($uri === '/admin/customers/new' && $method === 'GET') {
    requireAdmin();
    $form = '<form class="stack" method="post" action="/admin/customers/new">' . Csrf::field() . '
        <label>نام کاربر</label><input name="name" required>
        <label>Username</label><input name="username" required autocomplete="off">
        <label>رمز عبور</label><input name="password" type="password" required>
        <label>شماره تماس</label><input name="mobile" type="tel">
        <label>توضیحات</label><textarea name="notes" rows="3"></textarea>
        <label>Telegram Chat ID</label><input name="telegram_chat_id">
        <label><input type="checkbox" name="is_active" value="1" checked> حساب فعال</label>
        <p class="muted">سرویس (حجم و پنل‌ها) در مرحلهٔ بعد از «راه‌اندازی سرویس» تنظیم می‌شود.</p>
        <button class="btn btn-primary" type="submit">ایجاد کاربر</button>
    </form>';
    adminPage('مشتری جدید', 'users', Layout::card($form));
}

if ($uri === '/admin/customers/new' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    try {
        $id = CustomerService::create([
            'name' => trim($_POST['name'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'mobile' => trim($_POST['mobile'] ?? '') ?: null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'telegram_chat_id' => trim($_POST['telegram_chat_id'] ?? '') ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'warning1_percent' => 80,
            'warning2_percent' => 90,
        ], 0, AuthService::adminId());
        Response::redirect('/admin/customers/' . $id . '/service');
    } catch (\Throwable $e) {
        adminPage('خطا', 'users', Layout::card('<div class="alert alert-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'));
    }
}

if (preg_match('#^/admin/customers/(\d+)/service$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $id = (int) $m[1];
    $customer = CustomerService::findById($id);
    if (!$customer) {
        Response::redirect('/admin/customers');
    }
    $sub = CustomerService::activeSubscription($id);
    $panels = PanelService::forCustomer($id);
    $breakdown = CustomerService::panelUsageBreakdown($id);
    $used = $sub ? (int) $sub['used_upload_bytes'] + (int) $sub['used_download_bytes'] : 0;
    $quota = $sub ? (int) $sub['quota_bytes'] : 0;
    $panelChecks = '';
    foreach ($panels as $p) {
        $checked = (int) $p['is_active'] === 1 ? 'checked' : '';
        $panelChecks .= '<label class="check-row"><input type="checkbox" name="panel_ids[]" value="' . (int) $p['id'] . '" ' . $checked . '> '
            . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '</label>';
    }
    $breakRows = '';
    foreach ($breakdown as $b) {
        $t = (int) $b['upload_bytes'] + (int) $b['download_bytes'];
        if ($t <= 0) {
            continue;
        }
        $breakRows .= '<div class="data-card-row"><span>' . htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') . '</span><span>' . Format::bytesToGb($t) . '</span></div>';
    }
    $endsVal = $sub && $sub['ends_at'] ? date('Y-m-d', strtotime((string) $sub['ends_at'])) : '';
    $subLink = htmlspecialchars((string) ($customer['subscription_link'] ?? ''), ENT_QUOTES, 'UTF-8');
    $body = '<h3>' . htmlspecialchars($customer['username'], ENT_QUOTES, 'UTF-8') . '</h3>
        <p class="muted">مصرف تجمیعی (پنل‌های فعال): <strong>' . Format::bytesToGb($used) . '</strong> / ' . Format::bytesToGb($quota) . '</p>
        ' . ($breakRows ? Layout::card($breakRows, 'مصرف به تفکیک پنل (ادمین)') : '') . '
        . '<form class="stack" method="post" action="/admin/customers/' . $id . '/service">' . Csrf::field() . '
        <label>حجم کل (GB)</label><input name="quota_gb" type="number" step="0.1" required value="' . ($quota > 0 ? Format::bytesToGbNumber($quota) : '20') . '">
        <label>هشدار در (٪)</label><input name="warning1_percent" type="number" value="' . (int) $customer['warning1_percent'] . '">
        <label>هشدار دوم (٪)</label><input name="warning2_percent" type="number" value="' . (int) $customer['warning2_percent'] . '">
        <label>تاریخ انقضا</label><input name="ends_at" type="date" value="' . $endsVal . '">
        <label>Subscription Link</label><input name="subscription_link" value="' . $subLink . '" placeholder="https://...">
        <fieldset class="panel-pick"><legend>پنل‌های فعال برای این سرویس</legend>' . ($panelChecks ?: '<p class="muted">ابتدا از صفحه مشتری پنل XUI اضافه کنید.</p>') . '</fieldset>
        <button class="btn btn-primary" type="submit">ذخیره سرویس</button></form>
        <p style="margin-top:1rem"><a href="/admin/customers/' . $id . '">مدیریت پنل و کلاینت</a></p>';
    adminPage('راه‌اندازی سرویس', 'services', Layout::card($body));
}

if (preg_match('#^/admin/customers/(\d+)/service$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $id = (int) $m[1];
    $ends = trim($_POST['ends_at'] ?? '');
    CustomerService::createOrUpdateService($id, [
        'quota_gb' => (float) ($_POST['quota_gb'] ?? 0),
        'warning1_percent' => (int) ($_POST['warning1_percent'] ?? 80),
        'warning2_percent' => (int) ($_POST['warning2_percent'] ?? 90),
        'ends_at' => $ends !== '' ? $ends . ' 23:59:59' : null,
        'subscription_link' => trim($_POST['subscription_link'] ?? ''),
    ], AuthService::adminId());
    $panelIds = array_map('intval', $_POST['panel_ids'] ?? []);
    CustomerService::setPanelActivation($id, $panelIds);
    $sync = new TrafficSyncService(app_encryption($config), app_telegram($config));
    $sync->aggregateCustomer($id);
    Response::redirect('/admin/customers/' . $id . '/service');
}

if (preg_match('#^/admin/customers/(\d+)$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $id = (int) $m[1];
    $customer = CustomerService::findById($id);
    if (!$customer) {
        Response::redirect('/admin/customers');
    }
    $sub = CustomerService::activeSubscription($id);
    $used = $sub ? (int) $sub['used_upload_bytes'] + (int) $sub['used_download_bytes'] : 0;
    $quota = $sub ? (int) $sub['quota_bytes'] : 0;
    $panels = PanelService::forCustomer($id);

    $panelRows = '';
    foreach ($panels as $p) {
        $dot = match ($p['connection_status']) {
            'connected' => 'green',
            'sync_error' => 'yellow',
            default => 'red',
        };
        $panelRows .= '<div class="row"><span><span class="dot ' . $dot . '"></span> ' . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '</span>
            <a class="btn small secondary" href="/admin/panels/' . (int) $p['id'] . '/clients">کلاینت‌ها</a></div>';
    }

    $body = '<h3>' . htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') . '</h3>
        <p class="muted">مصرف: ' . Format::bytesToGb($used) . ' / ' . Format::bytesToGb($quota) . '</p>
        ' . Layout::card($panelRows ?: '<p class="muted">پنلی ثبت نشده</p>', 'پنل‌های 3X-UI') . '
        <p><a class="btn secondary" href="/admin/customers/' . $id . '/panels/new">افزودن پنل</a></p>
        ' . Layout::card('<form class="stack" method="post" action="/admin/customers/' . $id . '/add-quota">' . Csrf::field() . '
            <label>افزایش حجم (GB)</label><input name="gb" type="number" step="0.1" required>
            <button class="btn primary" type="submit">شارژ مجدد</button></form>', 'شارژ حجم') . '
        <p><a class="btn secondary" href="/admin/customers/' . $id . '/sync">هم‌اکنون Sync</a></p>';
    adminPage('مدیریت مشتری', 'users', $body);
}

if (preg_match('#^/admin/customers/(\d+)/panels/new$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $id = (int) $m[1];
    $form = '<form class="stack" method="post" action="/admin/customers/' . $id . '/panels/new">' . Csrf::field() . '
        <label>نام پنل</label><input name="name" required>
        <label>آدرس پنل (مثلاً https://panel.example.com)</label><input name="base_url" required>
        <label>API Token (Bearer)</label><input name="api_token" required autocomplete="off">
        <button class="btn primary" type="submit">ذخیره</button></form>';
    adminPage('پنل جدید', 'users', Layout::card($form));
}

if (preg_match('#^/admin/customers/(\d+)/panels/new$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $cid = (int) $m[1];
    PanelService::create($cid, trim($_POST['name'] ?? ''), trim($_POST['base_url'] ?? ''), $_POST['api_token'] ?? '', app_encryption($config), AuthService::adminId());
    Response::redirect('/admin/customers/' . $cid);
}

if (preg_match('#^/admin/customers/(\d+)/add-quota$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $id = (int) $m[1];
    CustomerService::addQuota($id, (float) ($_POST['gb'] ?? 0), app_encryption($config), app_telegram($config), AuthService::adminId());
    Response::redirect('/admin/customers/' . $id);
}

if (preg_match('#^/admin/customers/(\d+)/sync$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $id = (int) $m[1];
    $sync = new TrafficSyncService(app_encryption($config), app_telegram($config));
    $panels = PanelService::forCustomer($id);
    foreach ($panels as $p) {
        try {
            $sync->syncPanel((int) $p['id']);
        } catch (\Throwable) {
            // logged inside service
        }
    }
    $sync->aggregateCustomer($id);
    Response::redirect('/admin/customers/' . $id);
}

if (preg_match('#^/admin/panels/(\d+)/assign$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $pid = (int) $m[1];
    $stmt = Database::pdo()->prepare('SELECT customer_id FROM vpn_panels WHERE id = :id');
    $stmt->execute(['id' => $pid]);
    $panel = $stmt->fetch();
    if ($panel) {
        PanelService::assignClient(
            $pid,
            (int) $panel['customer_id'],
            trim($_POST['email'] ?? ''),
            (int) ($_POST['inbound_id'] ?? 0),
            ($_POST['uuid'] ?? '') !== '' ? $_POST['uuid'] : null,
            ($_POST['protocol'] ?? '') !== '' ? $_POST['protocol'] : null,
        );
    }
    Response::redirect('/admin/panels/' . $pid . '/clients');
}

if ($uri === '/admin/settings' && $method === 'GET') {
    requireAdmin();
    $token = SettingsService::get('telegram_bot_token', '');
    $form = '<form class="stack" method="post" action="/admin/settings">' . Csrf::field() . '
        <label>Telegram Bot Token</label>
        <input name="telegram_bot_token" value="' . htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') . '" autocomplete="off">
        <button class="btn primary" type="submit">ذخیره</button></form>';
    adminPage('تنظیمات', 'settings', Layout::card($form));
}

if ($uri === '/admin/settings' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    SettingsService::set('telegram_bot_token', trim($_POST['telegram_bot_token'] ?? ''));
    Response::redirect('/admin/settings');
}

// API JSON
if ($uri === '/api/customer/dashboard' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $sub = CustomerService::activeSubscription($cid);
    if (!$sub) {
        Response::json(['error' => 'no subscription'], 404);
    }
    $total = (int) $sub['used_upload_bytes'] + (int) $sub['used_download_bytes'];
    Response::json([
        'upload_bytes' => (int) $sub['used_upload_bytes'],
        'download_bytes' => (int) $sub['used_download_bytes'],
        'total_bytes' => $total,
        'quota_bytes' => (int) $sub['quota_bytes'],
        'percent' => Format::percent($total, (int) $sub['quota_bytes']),
    ]);
}

if ($uri === '/' && $method === 'GET') {
    if (AuthService::customerId()) {
        Response::redirect('/dashboard');
    }
    Response::html(Layout::publicHomePage());
}

http_response_code(404);
echo 'صفحه یافت نشد';
