<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth\AuthService;
use App\Core\Csrf;
use App\Core\Encryption;
use App\Core\Format;
use App\Core\Response;
use App\Core\Database;
use App\Services\CustomerService;
use App\Services\PanelService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Services\TrafficSyncService;
use App\View\Layout;

$encryption = new Encryption($config['security']['encryption_key']);
$telegram = new TelegramService(SettingsService::get('telegram_bot_token'));
$maxAttempts = (int) ($config['security']['login_max_attempts'] ?? 5);
$lockout = (int) ($config['security']['login_lockout_minutes'] ?? 15);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requireCsrf(): void
{
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        Response::html(Layout::render('خطا', Layout::card('<div class="alert error">توکن امنیتی نامعتبر است.</div>')), 403);
    }
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
    if (AuthService::customerId()) {
        Response::redirect('/dashboard');
    }
    Response::html(Layout::loginPage('ورود مشتری', '/login', 'customer'));
}

if ($uri === '/login' && $method === 'POST') {
    requireCsrf();
    $ok = AuthService::loginCustomer(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', $maxAttempts, $lockout);
    Response::redirect($ok ? '/dashboard' : '/login?e=1');
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
        Response::html(Layout::render('داشبورد', Layout::card('اطلاعات سرویس یافت نشد.'), 'customer'));
    }

    $upload = (int) $sub['used_upload_bytes'];
    $download = (int) $sub['used_download_bytes'];
    $total = $upload + $download;
    $quota = (int) $sub['quota_bytes'];
    $pct = Format::percent($total, $quota);
    $remaining = max(0, $quota - $total);

    $statusClass = 'active';
    $statusText = '🟢 سرویس فعال';
    if ((int) $customer['vpn_enabled'] === 0 || $sub['status'] === 'exhausted' || $pct >= 100) {
        $statusClass = 'exhausted';
        $statusText = '🔴 حجم سرویس شما به پایان رسیده است';
    } elseif ($pct >= (int) $customer['warning2_percent']) {
        $statusClass = 'warning';
        $statusText = '🟡 نزدیک به اتمام حجم';
    }

    $panels = CustomerService::panelUsageBreakdown($cid);
    $panelHtml = '';
    foreach ($panels as $p) {
        $pt = (int) $p['upload_bytes'] + (int) $p['download_bytes'];
        $panelHtml .= '<div class="row"><span>' . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '</span><span>' . Format::bytesToGb($pt) . '</span></div>';
    }

    $lastSync = Database::pdo()->prepare('SELECT MAX(last_sync_at) AS t FROM vpn_panels WHERE customer_id = :c');
    $lastSync->execute(['c' => $cid]);
    $ls = $lastSync->fetch()['t'] ?? null;
    $lastSyncText = $ls ? Format::jalaliOrGregorian($ls) : '—';

    $progClass = $pct >= 90 ? 'danger' : ($pct >= 80 ? 'warn' : '');
    $body = '
    <div class="stat-hero">
        <div class="big">' . Format::bytesToGb($total) . ' / ' . Format::bytesToGb($quota) . '</div>
        <div class="sub">مصرف کل</div>
    </div>
    <div class="progress ' . $progClass . '" data-progress="' . $pct . '"><span style="width:' . $pct . '%"></span></div>
    <p style="text-align:center" class="muted">' . $pct . '٪ مصرف شده</p>
    <div class="grid-2">
        <div class="kpi"><div class="label">آپلود</div><div class="value">' . Format::bytesToGb($upload) . '</div></div>
        <div class="kpi"><div class="label">دانلود</div><div class="value">' . Format::bytesToGb($download) . '</div></div>
        <div class="kpi"><div class="label">باقی‌مانده</div><div class="value">' . Format::bytesToGb($remaining) . '</div></div>
        <div class="kpi"><div class="label">آخرین بروزرسانی</div><div class="value" style="font-size:0.85rem">' . htmlspecialchars($lastSyncText, ENT_QUOTES, 'UTF-8') . '</div></div>
    </div>
    <p style="text-align:center;margin-top:1rem"><span class="status-pill ' . $statusClass . '">' . $statusText . '</span></p>
    ' . ($panelHtml ? Layout::card('<div class="panel-list">' . $panelHtml . '</div>', 'مصرف به تفکیک سرور') : '') . '
    ';
    Response::html(Layout::render('مصرف سرویس', $body, 'customer'));
}

// --- Admin ---
if ($uri === '/admin/login' && $method === 'GET') {
    if (AuthService::adminId()) {
        Response::redirect('/admin/dashboard');
    }
    Response::html(Layout::loginPage('ورود مدیریت', '/admin/login', 'admin'));
}

if ($uri === '/admin/login' && $method === 'POST') {
    requireCsrf();
    $ok = AuthService::loginAdmin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '', $maxAttempts, $lockout);
    Response::redirect($ok ? '/admin/dashboard' : '/admin/login?e=1');
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

    $body = '<div class="grid-2">
        <div class="kpi"><div class="label">مشتریان</div><div class="value">' . $stats['customers'] . '</div></div>
        <div class="kpi"><div class="label">فعال</div><div class="value">' . $stats['active'] . '</div></div>
        <div class="kpi"><div class="label">قطع‌شده</div><div class="value">' . $stats['exhausted'] . '</div></div>
        <div class="kpi"><div class="label">پنل‌ها</div><div class="value">' . $stats['panels'] . '</div></div>
        <div class="kpi"><div class="label">کلاینت‌ها</div><div class="value">' . $stats['clients'] . '</div></div>
        <div class="kpi"><div class="label">مصرف کل</div><div class="value">' . Format::bytesToGb($traffic) . '</div></div>
    </div>';
    Response::html(Layout::render('داشبورد مدیریت', Layout::card($body), 'admin'));
}

if ($uri === '/admin/customers' && $method === 'GET') {
    requireAdmin();
    $rows = CustomerService::listAll();
    $html = '<p><a class="btn primary" href="/admin/customers/new">مشتری جدید</a></p><table class="data"><thead><tr>
        <th>نام</th><th>کاربری</th><th>مصرف</th><th>حجم</th><th>وضعیت</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $used = (int) ($r['used_upload_bytes'] ?? 0) + (int) ($r['used_download_bytes'] ?? 0);
        $quota = (int) ($r['quota_bytes'] ?? 0);
        $html .= '<tr><td>' . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') . '</td>
            <td>' . htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') . '</td>
            <td>' . Format::bytesToGb($used) . '</td>
            <td>' . Format::bytesToGb($quota) . '</td>
            <td>' . htmlspecialchars($r['service_status'], ENT_QUOTES, 'UTF-8') . '</td>
            <td><a class="btn small secondary" href="/admin/customers/' . (int) $r['id'] . '">مدیریت</a></td></tr>';
    }
    $html .= '</tbody></table>';
    Response::html(Layout::render('مشتریان', Layout::card($html), 'admin'));
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
    Response::html(Layout::render('مشتری جدید', Layout::card($form), 'admin'));
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
        Response::html(Layout::render('خطا', Layout::card('<div class="alert error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'), 'admin'));
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
    Response::html(Layout::render('مدیریت مشتری', $body, 'admin'));
}

if (preg_match('#^/admin/customers/(\d+)/panels/new$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $id = (int) $m[1];
    $form = Csrf::field() . '<form class="stack" method="post" action="/admin/customers/' . $id . '/panels/new">
        <label>نام پنل</label><input name="name" required>
        <label>آدرس پنل (مثلاً https://panel.example.com)</label><input name="base_url" required>
        <label>API Token (Bearer)</label><input name="api_token" required autocomplete="off">
        <button class="btn primary" type="submit">ذخیره</button></form>';
    Response::html(Layout::render('پنل جدید', Layout::card($form), 'admin'));
}

if (preg_match('#^/admin/customers/(\d+)/panels/new$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $cid = (int) $m[1];
    PanelService::create($cid, trim($_POST['name'] ?? ''), trim($_POST['base_url'] ?? ''), $_POST['api_token'] ?? '', $encryption, AuthService::adminId());
    Response::redirect('/admin/customers/' . $cid);
}

if (preg_match('#^/admin/customers/(\d+)/add-quota$#', $uri, $m) && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $id = (int) $m[1];
    CustomerService::addQuota($id, (float) ($_POST['gb'] ?? 0), $encryption, $telegram, AuthService::adminId());
    Response::redirect('/admin/customers/' . $id);
}

if (preg_match('#^/admin/customers/(\d+)/sync$#', $uri, $m) && $method === 'GET') {
    requireAdmin();
    $id = (int) $m[1];
    $sync = new TrafficSyncService($encryption, $telegram);
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
        $clients = PanelService::discoverClients($pid, $encryption);
    } catch (\Throwable $e) {
        Response::html(Layout::render('خطا', Layout::card('<div class="alert error">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'), 'admin'));
    }
    $stmt = Database::pdo()->prepare('SELECT customer_id FROM vpn_panels WHERE id = :id');
    $stmt->execute(['id' => $pid]);
    $panel = $stmt->fetch();
    $cid = (int) ($panel['customer_id'] ?? 0);

    $html = '<table class="data"><thead><tr><th>ایمیل</th><th>مصرف</th><th>وضعیت</th><th></th></tr></thead><tbody>';
    foreach ($clients as $c) {
        $total = $c['up'] + $c['down'];
        $html .= '<tr><td>' . htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8') . '</td>
            <td>' . Format::bytesToGb($total) . '</td>
            <td>' . ($c['enable'] ? 'فعال' : 'غیرفعال') . ($c['mapped'] ? ' · متصل' : '') . '</td>
            <td>';
        if (!$c['mapped']) {
            $html .= '<form method="post" action="/admin/panels/' . $pid . '/assign" style="display:inline">' . Csrf::field() .
                '<input type="hidden" name="email" value="' . htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8') . '">
                <input type="hidden" name="inbound_id" value="' . $c['inbound_id'] . '">
                <input type="hidden" name="uuid" value="' . htmlspecialchars($c['uuid'] ?? '', ENT_QUOTES, 'UTF-8') . '">
                <input type="hidden" name="protocol" value="' . htmlspecialchars($c['protocol'] ?? '', ENT_QUOTES, 'UTF-8') . '">
                <button class="btn small primary" type="submit">اختصاص به مشتری</button></form>';
        }
        $html .= '</td></tr>';
    }
    $html .= '</tbody></table><p><a href="/admin/customers/' . $cid . '">بازگشت</a></p>';
    Response::html(Layout::render('کلاینت‌های پنل', Layout::card($html), 'admin'));
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
    Response::html(Layout::render('تنظیمات', Layout::card($form), 'admin'));
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
    Response::redirect(AuthService::customerId() ? '/dashboard' : '/login');
}

http_response_code(404);
echo 'صفحه یافت نشد';
