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

## نصب آفلاین کاملاً خودکار 3x-ui v3.4.2 — بدون GitHub روی VPS ایران

در **مدیریت → انتقال سرور** روش «آفلاین» را انتخاب کنید. **آپلود دستی، دستور روی VPS مقصد یا دانلود جداگانه لازم نیست.** روند:

1. JaySub با SSH به VPS مقصد متصل و معماری آن (amd64 یا arm64) را تشخیص می‌دهد.
2. بسته رسمی **v3.4.2** را در cache خصوصی **سرور مدیریت JaySub** بررسی می‌کند. اگر هنوز آنجا نیست، آن را **فقط روی سرور مدیریت** از GitHub دانلود، SHA-256 ثابت نسخه رسمی را کنترل و برای دفعات بعد ذخیره می‌کند.
3. فایل آماده را از سرور JaySub با SCP به VPS مقصد منتقل می‌کند.
4. روی VPS مقصد دوباره SHA-256 را چک می‌کند، 3x-ui را **بدون هیچ اتصال به GitHub** نصب و سرویس را راه‌اندازی می‌کند.
5. سپس انتقال گواهی SSL و بازیابی آخرین بک‌آپ پنل را با منطق فعلی انجام می‌دهد.

**آماده‌سازی cache در زمان نصب/Deploy JaySub:** اسکریپت deploy.sh تلاش می‌کند هر دو بسته amd64 و arm64 نسخه 3.4.2 را روی **سرور مدیریت JaySub** از قبل آماده کند؛ هر فایل صحیح در نصب‌های بعدی استفاده می‌شود و دوباره دانلود نمی‌شود. اگر آن لحظه ارتباط سرور مدیریت با GitHub برقرار نباشد، نصب خود JaySub متوقف نمی‌شود و در اولین درخواست نصب آفلاین روی JaySub مجدداً برای تکمیل cache تلاش می‌شود. هیچ دانلودی روی VPS مقصد انجام نخواهد شد. اگر **خود سرور مدیریت** هم به GitHub دسترسی ندارد، باید بسته را یک‌بار به روش دستی روی JaySub قرار دهید.

مسیرهای فایل روی JaySub (زیر مسیر storage پیکربندی‌شده): **storage/xui-offline/v3.4.2/x-ui-linux-amd64.tar.gz** و **x-ui-linux-arm64.tar.gz**. این فایل‌ها در Git commit نمی‌شوند.

برای مدیریت مستقل cache روی سرور مدیریت (با کاربری که PHP-FPM/worker تحت آن اجرا می‌شود):

```bash
cd /var/www/vpn-panel
sudo -u www-data php scripts/cache-xui-offline.php amd64 arm64
```

اگر مسیر نصب یا نام کاربر وب متفاوت است، آنها را تغییر دهید. این دستور **فقط روی سرور مدیریت JaySub** اجرا می‌شود، نه روی VPS مقصد.

**روش جایگزین برای سرور مدیریتِ بدون GitHub:** بسته‌های رسمی را از [انتشار v3.4.2](https://github.com/MHSanaei/3x-ui/releases/tag/v3.4.2) روی کامپیوتری دیگر دانلود کنید و از فرم آپلود اختیاری یا با SCP در مسیر cache JaySub قرار دهید. هنگام استفاده، SHA-256 رسمی کنترل می‌شود. آپلود از مرورگر نیاز به upload_max_filesize=128M، post_max_size=130M و client_max_body_size مناسب در Nginx دارد؛ این تنظیمات برای نصب عادی آفلاین لازم نیستند.

**محدودیت‌ها:** نصب آفلاین روی VPS تمیز Ubuntu/Debian با systemd، معماری amd64 یا arm64 و ابزارهای پایه (tar و sha256sum و SSH) پشتیبانی می‌شود. روی مقصد دارای نصب قبلی 3x-ui بازنویسی انجام نمی‌شود. سرور مدیریت باید بتواند با SSH/SCP به مقصد دسترسی داشته باشد. امکانات جانبی که به دانلود مجزا نیاز دارند جزو نصب پایه نیستند. اگر دریافت اولیه روی مدیریت شکست بخورد، عملیات خطا می‌دهد و به نصب آنلاین روی VPS مقصد سوئیچ نمی‌کند.

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
