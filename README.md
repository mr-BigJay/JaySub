# JaySub — پنل مدیریت مشتریان VPN (3X-UI)

پنل وب **فارسی، RTL و Mobile-First** برای مدیریت مشتریان VPN با اتصال به چند پنل [MHSanaei/3x-ui](https://github.com/MHSanaei/3x-ui).

## ویژگی‌ها

- مدیریت مشتری، حجم سرویس، هشدار ۸۰٪ / ۹۰٪ و قطع خودکار در ۱۰۰٪
- چند پنل 3X-UI برای هر مشتری؛ **همهٔ کلاینت‌های پنل** در هر sync خودکار در مصرف لحاظ می‌شوند
- جمع‌آوری مصرف: `SUM(upload) + SUM(download)` روی تمام Clientهای پنل؛ **از لحظهٔ اتصال/sync** (نه تاریخچهٔ قبلی XUI)
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

**HTTP 401** یعنی JaySub به آدرس پنل رسیده اما 3x-ui توکن را قبول نکرده: توکن در JaySub **ذخیره نشده** (فقط در 3x-ui ساخته‌اید و «تست» زده‌اید)، توکن **منقضی/غیرفعال** شده، یا **نام توکن** به‌جای **مقدار یک‌باره** کپی شده است. در **ویرایش پنل** توکن را بچسبانید و **ذخیره و تست اتصال** بزنید.

روی VPS پنل 3x-ui (نسخهٔ جدیدتر):

```bash
x-ui setting -getApiToken true
```

این یک توکن CLI می‌سازد/تازه می‌کند؛ همان را در JaySub ذخیره کنید. اگر منوی `x-ui` زیردستور `setting` ندارد، فقط از رابط وب **API Tokens** استفاده کنید.

## نصب آفلاین 3x-ui v3.4.2 هنگام انتقال VPS

در **مدیریت → انتقال سرور** دو روش نصب وجود دارد:

- **آنلاین (پیش‌فرض):** رفتار قبلی؛ اسکریپت رسمی نصب روی VPS مقصد از GitHub دانلود می‌شود.
- **آفلاین:** بسته رسمی **v3.4.2** از سرور JaySub با SCP به VPS مقصد ارسال، روی مقصد SHA-256 دوباره تأیید و بدون دانلود از GitHub نصب می‌شود؛ سپس انتقال SSL و بازیابی بک‌آپ مثل قبل ادامه پیدا می‌کند.

برای اولین بار بسته مناسب معماری سرور مقصد را از [انتشار رسمی v3.4.2](https://github.com/MHSanaei/3x-ui/releases/tag/v3.4.2) روی دستگاهی با دسترسی خوب به GitHub دریافت کنید:

- **x-ui-linux-amd64.tar.gz** برای سرورهای x86_64/amd64 (حدود ۸۳ مگابایت)
- **x-ui-linux-arm64.tar.gz** برای سرورهای aarch64/arm64 (حدود ۷۹ مگابایت)

در **انتقال سرور → نصب آفلاین** می‌توانید فایل را در قسمت «بارگذاری یک‌باره بسته رسمی» آپلود کنید؛ پنل پیش از ذخیره SHA-256 را با هش رسمی همین انتشار تطبیق می‌دهد. برای آپلود از مرورگر، PHP-FPM به **upload_max_filesize=128M** و **post_max_size=130M** و Nginx به client_max_body_size مناسب (مثلاً 130m) نیاز دارد؛ بعد از تغییر تنظیمات PHP-FPM و Nginx را reload کنید.

روش جایگزین: بسته را روی سرور JaySub داخل پوشه **storage/xui-offline/v3.4.2/** در مسیر storage پیکربندی‌شده در config.php کپی کنید. نام فایل باید دقیقاً x-ui-linux-amd64.tar.gz یا x-ui-linux-arm64.tar.gz باشد. فایل را برای کاربر PHP/worker خواندنی کنید.

JaySub در هر انتقال معماری سرور مقصد را با uname -m تشخیص می‌دهد، فایل را چک می‌کند و با SCP می‌فرستد. نصب مقصد هم checksum را کنترل می‌کند. اگر فایل موجود یا درست نباشد، عملیات بدون نصب متوقف می‌شود و خودکار به روش آنلاین بازنمی‌گردد.

**محدودیت‌ها:** نصب آفلاین فعلاً فقط برای Ubuntu/Debian با systemd و معماری amd64 یا arm64 در نظر گرفته شده است. VPS مقصد باید فاقد نصب قبلی 3x-ui باشد تا اطلاعات پنل موجود حذف نشوند. ابزارهای پایه tar و sha256sum و systemctl باید روی مقصد موجود باشند. JaySub باید از طریق SSH/SCP به VPS مقصد دسترسی داشته باشد؛ «آفلاین» یعنی مقصد در زمان نصب نیازی به GitHub ندارد. امکانات جانبی نیازمند دانلود جداگانه، به‌صورت خودکار نصب نمی‌شوند.

نسخه 3.4.2 عمداً ثابت نگه داشته شده است. برای نسخه‌های دیگر باید بسته‌ها و هش‌های جدید در کد ثبت شوند. بسته‌های binary را داخل Git commit نکنید.

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
