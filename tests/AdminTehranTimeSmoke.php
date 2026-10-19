<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Format;
use App\View\Layout;

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$oldTimezone = date_default_timezone_get();
date_default_timezone_set('UTC');

try {
    $epoch = strtotime('2026-10-19T00:00:00+00:00');
    check($epoch !== false, 'fixture timestamp');
    check(Format::TZ === 'Asia/Tehran', 'central timezone is Asia/Tehran');
    check(Format::fromTimestamp($epoch) === '2026/10/19 03:30', 'epoch uses Tehran timezone');
    check(Format::dateTehran('H:i', $epoch) === '03:30', 'clock uses Tehran timezone');
    check(
        Format::jalaliOrGregorian('2026-10-19T00:00:00+00:00') === '2026/10/19 03:30',
        'offset-aware ISO timestamps converted to Tehran',
    );
    check(
        Format::jalaliOrGregorian('2026-10-19 03:30:00') === '2026/10/19 03:30',
        'local database DATETIME is not shifted again',
    );

    $pages = ['dashboard', 'users', 'panels', 'services', 'reports', 'notifications', 'telegram', 'backup', 'ssl_backup', 'migration', 'settings'];
    foreach ($pages as $active) {
        $html = Layout::admin('آزمایش ساعت تهران', $active, '<p>test</p>');
        check(str_contains($html, 'data-tehran-clock'), "clock visible on admin {$active}");
        check(str_contains($html, 'ساعت تهران'), "Tehran label on admin {$active}");
        check((bool) preg_match('/<time[^>]*datetime="[^"]+\\+03:30"/', $html), "Tehran offset in rendered {$active}");
    }

    $js = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
    check(is_string($js) && str_contains($js, "timeZone: 'Asia/Tehran'"), 'live JS clock is pinned to Tehran timezone');
    $layout = file_get_contents(dirname(__DIR__) . '/src/View/Layout.php');
    check(is_string($layout) && !str_contains($layout, 'هر ۴ ساعت'), 'backup frequency labels reflect hourly schedule');

    echo "PASS: admin pages show a Tehran clock; timestamps ignore host timezone and labels are hourly.\n";
} finally {
    date_default_timezone_set($oldTimezone);
}
