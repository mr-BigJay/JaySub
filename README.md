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

## نیازمندی‌ها

- PHP 8.3+ (extensions: `pdo_mysql`, `openssl`, `curl`, `json`)
- MySQL 8
- Nginx + PHP-FPM

## نصب خودکار (یک خط — Ubuntu/Debian)

روی VPS با **root** یا `sudo` — در حین نصب از شما **دامنه، رمز ادمین، SSL و …** پرسیده می‌شود:

```bash
sudo bash -c "$(curl -fsSL https://raw.githubusercontent.com/mr-BigJay/JaySub/cursor/vpn-customer-panel-6abb/scripts/deploy.sh)"
```

> از `curl ... | bash` استفاده نکنید؛ با pipe ورودی تعاملی کار نمی‌کند. فرمت بالا درست است.

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
