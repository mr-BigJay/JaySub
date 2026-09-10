# JaySub — پنل مدیریت مشتریان VPN (3X-UI)

پنل وب **فارسی، RTL و Mobile-First** برای مدیریت مشتریان VPN با اتصال به چند پنل [MHSanaei/3x-ui](https://github.com/MHSanaei/3x-ui).

## ویژگی‌ها

- مدیریت مشتری، حجم سرویس، هشدار ۸۰٪ / ۹۰٪ و قطع خودکار در ۱۰۰٪
- چند پنل 3X-UI برای هر مشتری + اختصاص چند Client
- جمع‌آوری مصرف: `SUM(upload) + SUM(download)` روی تمام Clientها
- مقاوم در برابر Reset شمارنده 3X-UI (`base_usage + current_xui_usage`)
- Worker هر ۶۰ ثانیه با **قفل فایل** (بدون اجرای همزمان)
- Telegram برای هشدار و شارژ مجدد
- توکن API 3X-UI فقط در Backend (رمزنگاری AES)

## API 3X-UI (استفاده‌شده)

| عملیات | Endpoint |
|--------|----------|
| وضعیت سرور | `GET /panel/api/server/status` |
| Inbound + clientStats | `GET /panel/api/inbounds/list` |
| غیرفعال‌سازی | `POST /panel/api/clients/bulkDisable` |
| فعال‌سازی | `POST /panel/api/clients/bulkEnable` |

احراز هویت: `Authorization: Bearer <API Token>`

### دریافت API Token از سرور 3x-ui

اسکریپت `x-ui` روی VPS معمولاً **فقط** سرویس را مدیریت می‌کند (`start` / `stop` / `settings` و …). دستور `x-ui setting -getApiToken` در نسخهٔ رایج [MHSanaei/3x-ui](https://github.com/MHSanaei/3x-ui) **وجود ندارد**؛ اگر همان منوی کمکی را می‌بینید، طبیعی است.

1. در مرورگر همان آدرسی را باز کنید که برای ورود به پنل استفاده می‌کنید (مثلاً `https://bell.jay-force.ir:2415/GRgxVKeEuAUnoMwRYy/`).
2. با حساب **ادمین پنل 3x-ui** وارد شوید.
3. **Panel settings** (تنظیمات پنل) → **API Tokens** → توکن جدید بسازید و بلافاصله کپی کنید (دوباره کامل نشان داده نمی‌شود).
4. در JaySub فیلد **آدرس پنل** = پایهٔ URL (مثلاً `https://bell.jay-force.ir:2415/GRgxVKeEuAUnoMwRYy` — بدون اسلش آخر، بدون `/panel/api/...`). توکن فقط خود رشته، **بدون** `Bearer`.

JaySub از API این آدرس را صدا می‌زند: `{base_url}/panel/api/server/status` (و بقیهٔ مسیرهای `/panel/api/*`).

تست از سرور:

```bash
curl -sk -H "Authorization: Bearer YOUR_TOKEN" "https://YOUR_PANEL/panel/api/server/status"
```

پاسخ JSON با `success: true` یعنی توکن و آدرس درست است.

اگر JaySub می‌گوید **Invalid JSON** یا **پاسخ خالی (404)**، در 3x-ui معمولاً **توکن API اشتباه، حذف‌شده، یا با پیشوند اضافی `Bearer`** است؛ پنل عمداً بدنهٔ خالی برمی‌گرداند. توکن را از **API Tokens** دوباره بسازید (ترجیحاً **Admin** یا **Node-sync**). دو رکورد با یک آدرس ولی دو توکن مختلف می‌توانند یکی «متصل» و دیگری «خطا» باشد.

## نیازمندی‌ها

- PHP 8.3+ (extensions: `pdo_mysql`, `openssl`, `curl`, `json`)
- MySQL 8
- Nginx + PHP-FPM

## نصب خودکار (یک خط — Ubuntu/Debian)

روی VPS با **root** یا `sudo` — در حین نصب از شما **دامنه، رمز ادمین، SSL و …** پرسیده می‌شود:

**Nasb kamel — yek khat (soal mide, DB + admin + nginx + SSL):**

```bash
curl -fsSL https://raw.githubusercontent.com/mr-BigJay/JaySub/cursor/vpn-customer-panel-6abb/scripts/install | bash
```

**Faghat repair database (agar «دیتابیس آماده نیست»):**

```bash
curl -fsSL https://raw.githubusercontent.com/mr-BigJay/JaySub/cursor/vpn-customer-panel-6abb/scripts/repair | bash
```

**Didan-e address panel + ramzha (har zaman, yek khat):**

```bash
curl -fsSL https://raw.githubusercontent.com/mr-BigJay/JaySub/cursor/vpn-customer-panel-6abb/scripts/info | bash
```

Baad az nasb movafagh ham mitavanid bezani: `jaysub-info`

اگر root نیستید: همان خط را با `| sudo bash` تمام کنید.

جایگزین (دو مرحله):

```bash
curl -fsSL https://raw.githubusercontent.com/mr-BigJay/JaySub/cursor/vpn-customer-panel-6abb/scripts/deploy.sh -o /root/jaysub-deploy.sh
bash /root/jaysub-deploy.sh
```

> از `bash -c "$(curl ...)"` و `bash <(curl ...)` استفاده نکنید — روی بسیاری از VPS خطا می‌دهد (`/dev/fd/...` یا `curl: (23)`).

اسکریپت نصب می‌کند: Nginx، PHP-FPM، MySQL، clone پروژه، دیتابیس، Cron worker، و در صورت انتخاب شما گواهی HTTPS.  
رمزها در `storage/install-credentials.txt` ذخیره می‌شوند.

نصب بدون سوال (پارامتر خط فرمان): `-d` دامنه، `-a` رمز ادمین، `-e` ایمیل SSL، `-p` رمز MySQL.

## نصب دستی

```bash
composer install
cp config/config.example.php config/config.php
# ویرایش تنظیمات دیتابیس و encryption_key در config.php
php scripts/install.php 'رمز-ادمین-قوی'
```

Nginx: از `nginx.conf.example` استفاده کنید. Document root باید `public/` باشد.

### Cron Worker (هر دقیقه)

```cron
* * * * * php /path/to/project/worker/traffic_worker.php >> /path/to/project/logs/worker.log 2>&1
```

## ورود

- **مدیریت:** `/admin/login` — کاربر پیش‌فرض `admin` (رمز در نصب تنظیم می‌شود)
- **مشتری:** `/login`

## ساختار

```
config/          تنظیمات
database/        schema.sql
public/          front controller + assets
src/             منطق اپلیکیشن
worker/          traffic_worker.php
scripts/         install.php
tests/           تست واحد TrafficCounter
```

## تست

```bash
php tests/TrafficCounterTest.php
```

## جریان مصرف

```
Customer → VPN Panels → vpn_clients → clientStats (up/down از 3X-UI)
→ TrafficCounter → subscriptions.used_* → traffic_snapshots
→ Quota / Telegram / bulkDisable
```
