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
        'exhausted' => ['badge-danger', 'اتمام حجم'],
        'inactive' => ['badge-danger', 'غیرفعال'],
    ];
    [$cls, $label] = $map[$status] ?? ['badge-warning', $status];
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
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
        customerPage('داشبورد', 'home', Layout::card('<p class="muted">اطلاعات سرویس یافت نشد. با پشتیبانی تماس بگیرید.</p>'));
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
    $connLabel = $vpnReady ? 'آماده اتصال' : 'قطع / غیرفعال';

    $endsAt = $sub['ends_at'] ?? null;
    $expiryText = $endsAt ? Format::jalaliOrGregorian((string) $endsAt) : 'نامحدود (حجمی)';
    $planLabel = Format::bytesToGb($quota) . ' — اشتراک حجمی';

    $statusBadge = serviceStatusBadge((string) $customer['service_status']);
    if ($sub['status'] === 'exhausted' || $pct >= 100) {
        $statusBadge = serviceStatusBadge('exhausted');
    }

    $panels = CustomerService::panelUsageBreakdown($cid);
    $panelHtml = '';
    foreach ($panels as $p) {
        $pt = (int) $p['upload_bytes'] + (int) $p['download_bytes'];
        $panelHtml .= '<div class="data-card-row"><span>' . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '</span><span>' . Format::bytesToGb($pt) . '</span></div>';
    }

    $lastSync = Database::pdo()->prepare('SELECT MAX(last_sync_at) AS t FROM vpn_panels WHERE customer_id = :c');
    $lastSync->execute(['c' => $cid]);
    $ls = $lastSync->fetch()['t'] ?? null;
    $lastSyncText = $ls ? Format::jalaliOrGregorian($ls) : '—';

    $progClass = $pct >= 90 ? 'danger' : ($pct >= 80 ? 'warn' : '');
    $userName = htmlspecialchars((string) $customer['name'], ENT_QUOTES, 'UTF-8');

    $body = '
    <p class="customer-greeting">سلام، <strong>' . $userName . '</strong></p>
    <section class="vpn-status-card">
        <span class="status-dot ' . $dotClass . '"></span>
        <h2>اتصال VPN</h2>
        <p>' . htmlspecialchars($connLabel, ENT_QUOTES, 'UTF-8') . '</p>
        <div class="quota-ring"><div>' . (100 - min(100, (int) round($pct))) . '٪<small>باقی‌مانده</small></div></div>
        <p class="muted">' . Format::bytesToGb($remaining) . ' از ' . Format::bytesToGb($quota) . '</p>
    </section>
    ' . Layout::card('
        <div class="data-card-row"><span>نوع اشتراک</span><span>' . htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8') . '</span></div>
        <div class="data-card-row"><span>تاریخ انقضا</span><span>' . htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') . '</span></div>
        <div class="data-card-row"><span>وضعیت</span><span>' . $statusBadge . '</span></div>
        <div class="progress ' . $progClass . '"><span style="width:' . $pct . '%"></span></div>
        <p class="muted" style="text-align:center;font-size:0.85rem">' . $pct . '٪ مصرف · بروزرسانی ' . htmlspecialchars($lastSyncText, ENT_QUOTES, 'UTF-8') . '</p>
    ', 'خلاصه اشتراک') . '
    <div class="action-grid">
        <a class="action-tile" href="/app/buy"><span class="at-ico">🛒</span><span>خرید اشتراک</span></a>
        <a class="action-tile" href="/app/renew"><span class="at-ico">↻</span><span>تمدید اشتراک</span></a>
        <a class="action-tile" href="/app/link"><span class="at-ico">🔗</span><span>لینک اتصال</span></a>
        <a class="action-tile" href="/app/messages"><span class="at-ico">✉</span><span>پیام‌ها</span></a>
    </div>
    ' . ($panelHtml ? Layout::card($panelHtml, 'مصرف به تفکیک سرور') : '') . '
    ';
    customerPage('داشبورد', 'home', $body);
}

// --- Customer app pages ---
if ($uri === '/app/subscription' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $sub = CustomerService::activeSubscription($cid);
    if (!$sub) {
        customerPage('اشتراک من', 'subscription', Layout::card('<p class="muted">اشتراک فعالی ثبت نشده است.</p><a class="btn btn-primary block" href="/app/buy">خرید اشتراک</a>'));
        return;
    }
    $total = (int) $sub['used_upload_bytes'] + (int) $sub['used_download_bytes'];
    $quota = (int) $sub['quota_bytes'];
    $pct = Format::percent($total, $quota);
    $endsAt = $sub['ends_at'] ?? null;
    $expiryText = $endsAt ? Format::jalaliOrGregorian((string) $endsAt) : 'نامحدود';
    $body = Layout::card('
        <div class="data-card-row"><span>حجم کل</span><span>' . Format::bytesToGb($quota) . '</span></div>
        <div class="data-card-row"><span>مصرف شده</span><span>' . Format::bytesToGb($total) . ' (' . $pct . '٪)</span></div>
        <div class="data-card-row"><span>انقضا</span><span>' . htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') . '</span></div>
        <div class="data-card-row"><span>وضعیت</span><span>' . serviceStatusBadge((string) $sub['status']) . '</span></div>
        <p style="margin-top:1rem"><a class="btn btn-primary block" href="/app/renew">تمدید / افزایش حجم</a></p>
    ', 'اشتراک فعال');
    customerPage('اشتراک من', 'subscription', $body);
}

if ($uri === '/app/buy' && $method === 'GET') {
    customerPlaceholder('خرید اشتراک', 'subscription', 'لیست پلن‌ها و درگاه پرداخت به‌زودی فعال می‌شود.');
}

if ($uri === '/app/renew' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $sub = CustomerService::activeSubscription($cid);
    $info = $sub
        ? '<p class="muted">اشتراک فعلی: ' . Format::bytesToGb((int) $sub['quota_bytes']) . '</p>'
        : '<p class="muted">اشتراک فعالی ندارید.</p>';
    customerPage('تمدید اشتراک', 'subscription', Layout::card($info . '<div class="placeholder-page"><div class="big-ico">↻</div><p>تمدید آنلاین به‌زودی.</p><p class="muted">فعلاً از پشتیبانی درخواست تمدید کنید.</p><a class="btn btn-secondary block" href="/app/support">پشتیبانی</a></div>'));
}

if ($uri === '/app/messages' && $method === 'GET') {
    customerPlaceholder('پیام‌ها', 'messages', 'پیام‌های سیستم و اعلان‌ها اینجا نمایش داده می‌شوند.');
}

if ($uri === '/app/profile' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $customer = CustomerService::findById($cid);
    if (!$customer) {
        Response::redirect('/login');
    }
    $body = Layout::card('
        <div class="data-card-row"><span>نام</span><span>' . htmlspecialchars((string) $customer['name'], ENT_QUOTES, 'UTF-8') . '</span></div>
        <div class="data-card-row"><span>نام کاربری</span><span>' . htmlspecialchars((string) $customer['username'], ENT_QUOTES, 'UTF-8') . '</span></div>
        <p style="margin-top:1.25rem"><a class="btn btn-secondary block" href="/logout">خروج از حساب</a></p>
    ', 'پروفایل');
    customerPage('پروفایل', 'more', $body);
}

if ($uri === '/app/support' && $method === 'GET') {
    customerPage('پشتیبانی', 'more', Layout::card('<p>برای تمدید، مشکل اتصال یا سوالات فنی با پشتیبانی تماس بگیرید.</p><p class="muted">اطلاعات تماس از طرف مدیر سرویس به شما اعلام می‌شود.</p>'));
}

if ($uri === '/app/link' && $method === 'GET') {
    requireCustomer();
    $cid = AuthService::customerId();
    $stmt = Database::pdo()->prepare(
        'SELECT vc.xui_email, vp.name AS panel_name FROM vpn_clients vc
         JOIN vpn_panels vp ON vp.id = vc.panel_id WHERE vc.customer_id = :c LIMIT 20'
    );
    $stmt->execute(['c' => $cid]);
    $clients = $stmt->fetchAll();
    if ($clients === []) {
        customerPage('لینک اتصال', 'home', Layout::card('<p class="muted">هنوز کلاینت VPN به حساب شما متصل نشده. با پشتیبانی تماس بگیرید.</p>'));
        return;
    }
    $list = '';
    foreach ($clients as $c) {
        $list .= '<div class="data-card-row"><span>' . htmlspecialchars((string) $c['panel_name'], ENT_QUOTES, 'UTF-8') . '</span><span class="muted">' . htmlspecialchars((string) $c['xui_email'], ENT_QUOTES, 'UTF-8') . '</span></div>';
    }
    customerPage('لینک اتصال', 'home', Layout::card('<p class="muted">لینک اشتراک از پنل ۳X-UI (همان ایمیل کلاینت) در اپ VPN خود import کنید.</p>' . $list, 'کلاینت‌های شما'));
}

if ($uri === '/app/more' && $method === 'GET') {
    requireCustomer();
    $menu = '
    <nav class="more-menu">
        <a href="/app/profile">پروفایل</a>
        <a href="/app/subscription">اشتراک‌های من</a>
        <a href="/app/support">پشتیبانی</a>
        <a href="/">درباره سرویس</a>
        <a class="danger" href="/logout">خروج</a>
    </nav>';
    customerPage('بیشتر', 'more', Layout::card($menu));
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
    $pdo = Database::pdo();
    $stats = [
        'customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
        'active' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE is_active = 1 AND service_status = 'active'")->fetchColumn(),
        'exhausted' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE service_status = 'exhausted'")->fetchColumn(),
        'panels' => (int) $pdo->query('SELECT COUNT(*) FROM vpn_panels')->fetchColumn(),
        'clients' => (int) $pdo->query('SELECT COUNT(*) FROM vpn_clients')->fetchColumn(),
    ];
    $traffic = (int) $pdo->query('SELECT COALESCE(SUM(used_upload_bytes + used_download_bytes),0) FROM subscriptions WHERE status IN (\'active\',\'exhausted\')')->fetchColumn();

    $body = Layout::statGrid(
        Layout::statCard('مشتریان', (string) $stats['customers'])
        . Layout::statCard('فعال', (string) $stats['active'], 'tone-success')
        . Layout::statCard('قطع‌شده', (string) $stats['exhausted'], 'tone-danger')
        . Layout::statCard('پنل‌ها', (string) $stats['panels'])
        . Layout::statCard('کلاینت‌ها', (string) $stats['clients'])
        . Layout::statCard('مصرف کل', Format::bytesToGb($traffic))
    );
    adminPage('داشبورد', 'dashboard', $body);
}

if ($uri === '/admin/subscriptions' && $method === 'GET') {
    adminPlaceholder('اشتراک‌ها', 'subscriptions', 'مدیریت پلن‌ها و اشتراک‌های فعال مشتریان.');
}

if ($uri === '/admin/sales' && $method === 'GET') {
    adminPlaceholder('فروش', 'sales', 'خلاصه فروش و تراکنش‌های موفق.');
}

if ($uri === '/admin/payments' && $method === 'GET') {
    adminPlaceholder('پرداخت‌ها', 'payments', 'وضعیت پرداخت‌ها و درگاه.');
}

if ($uri === '/admin/messages' && $method === 'GET') {
    adminPlaceholder('پیام‌ها', 'messages', 'ارسال پیام و اعلان به مشتریان.');
}

if ($uri === '/admin/reports' && $method === 'GET') {
    adminPlaceholder('گزارش‌ها', 'reports', 'نمودار مصرف، فروش و گزارش‌های دوره‌ای.');
}

if ($uri === '/admin/customers' && $method === 'GET') {
    requireAdmin();
    $rows = CustomerService::listAll();
    $tableRows = [];
    foreach ($rows as $r) {
        $used = (int) ($r['used_upload_bytes'] ?? 0) + (int) ($r['used_download_bytes'] ?? 0);
        $quota = (int) ($r['quota_bytes'] ?? 0);
        $tableRows[] = [
            htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8'),
            Format::bytesToGb($used),
            Format::bytesToGb($quota),
            serviceStatusBadge((string) $r['service_status']),
            '<a class="btn btn-sm btn-secondary" href="/admin/customers/' . (int) $r['id'] . '">مدیریت</a>',
        ];
    }
    $html = '<p class="toolbar"><a class="btn btn-primary" href="/admin/customers/new">مشتری جدید</a></p>'
        . Layout::responsiveTable(['نام', 'کاربری', 'مصرف', 'حجم', 'وضعیت', ''], $tableRows);
    adminPage('کاربران', 'users', Layout::card($html));
}

if ($uri === '/admin/customers/new' && $method === 'GET') {
    requireAdmin();
    $form = Csrf::field() . '
    <form class="stack" method="post" action="/admin/customers/new">
        <label>نام</label><input name="name" required>
        <label>نام کاربری</label><input name="username" required>
        <label>رمز عبور</label><input name="password" type="password" required>
        <label>Telegram Chat ID</label><input name="telegram_chat_id">
        <label>حجم (GB)</label><input name="quota_gb" type="number" step="0.1" required value="100">
        <label>هشدار ۱ (٪)</label><input name="warning1_percent" type="number" value="80">
        <label>هشدار ۲ (٪)</label><input name="warning2_percent" type="number" value="90">
        <button class="btn primary" type="submit">ذخیره</button>
    </form>';
    adminPage('مشتری جدید', 'users', Layout::card($form));
}

if ($uri === '/admin/customers/new' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    try {
        CustomerService::create([
            'name' => trim($_POST['name'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'telegram_chat_id' => trim($_POST['telegram_chat_id'] ?? '') ?: null,
            'warning1_percent' => (int) ($_POST['warning1_percent'] ?? 80),
            'warning2_percent' => (int) ($_POST['warning2_percent'] ?? 90),
        ], (float) ($_POST['quota_gb'] ?? 100), AuthService::adminId());
        Response::redirect('/admin/customers');
    } catch (\Throwable $e) {
        adminPage('خطا', 'users', Layout::card('<div class="alert alert-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'));
    }
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
        ' . Layout::card(Csrf::field() . '<form class="stack" method="post" action="/admin/customers/' . $id . '/add-quota">
            <label>افزایش حجم (GB)</label><input name="gb" type="number" step="0.1" required>
            <button class="btn primary" type="submit">شارژ مجدد</button></form>', 'شارژ حجم') . '
        <p><a class="btn secondary" href="/admin/customers/' . $id . '/sync">هم‌اکنون Sync</a></p>';
    adminPage('مدیریت مشتری', 'users', $body);
}

if (preg_match('#^/admin/customers/(\d+)/panels/new$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $id = (int) $m[1];
    $form = Csrf::field() . '<form class="stack" method="post" action="/admin/customers/' . $id . '/panels/new">
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

if (preg_match('#^/admin/panels/(\d+)/clients$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $pid = (int) $m[1];
    try {
        $clients = PanelService::discoverClients($pid, app_encryption($config));
    } catch (\Throwable $e) {
        adminPage('خطا', 'users', Layout::card('<div class="alert alert-error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'));
    }
    $stmt = Database::pdo()->prepare('SELECT customer_id FROM vpn_panels WHERE id = :id');
    $stmt->execute(['id' => $pid]);
    $panel = $stmt->fetch();
    $cid = (int) ($panel['customer_id'] ?? 0);

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
    $html = Layout::responsiveTable(['ایمیل', 'مصرف', 'وضعیت', ''], $tableRows)
        . '<p class="muted"><a href="/admin/customers/' . $cid . '">بازگشت به مشتری</a></p>';
    adminPage('کلاینت‌های پنل', 'users', Layout::card($html));
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
    $form = Csrf::field() . '<form class="stack" method="post" action="/admin/settings">
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
