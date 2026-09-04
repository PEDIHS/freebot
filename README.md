# FreeBot

ربات PHP + Telegram Webhook با پنل مدیریت، نصبگر وب، Cron، MariaDB و قابلیت به‌روزرسانی از GitHub.

## نصب کامل روی Ubuntu/Debian با یک دستور

به‌عنوان root اجرا کنید:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

اسکریپت این موارد را آماده می‌کند:

- Nginx
- PHP-FPM و افزونه‌های لازم
- MariaDB
- Certbot / Let's Encrypt
- Cron هر دقیقه
- مسیرهای `config` و `storage`
- Permissionهای امن
- `yt-dlp` در Python venv مجزا
- `ffmpeg`
- `aria2`
- دستور `freebot-update` برای آپدیت نسخه نصب‌شده

در پایان آدرس زیر نمایش داده می‌شود:

```text
https://YOUR-DOMAIN/install/
```

## دیتابیس از قبل ساخته‌شده

اگر دیتابیس و کاربر `freebot` را قبلاً ساخته‌اید:

```bash
DOMAIN=bot.example.com SKIP_DB=1 AUTO_UPDATE=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

سپس در Installer وب وارد کنید:

```text
Host: localhost
Port: 3306
Database: freebot
User: freebot
Password: رمز همان کاربر MariaDB
```

## ساخت خودکار دیتابیس

```bash
DOMAIN=bot.example.com \
DB_NAME=freebot \
DB_USER=freebot \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

اگر دیتابیس توسط اسکریپت ساخته شود، مشخصات آن فقط روی سرور در این فایل ذخیره می‌شود:

```text
/root/.freebot/install-credentials.txt
```

## اگر DNS هنوز آماده نیست

```bash
DOMAIN=bot.example.com SKIP_DB=1 SKIP_SSL=1 AUTO_UPDATE=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

بعد از تنظیم DNS:

```bash
certbot --nginx -d bot.example.com
```

## آپدیت

آپدیت دستی:

```bash
freebot-update
```

Updater نسخه انتشار GitHub را دریافت و با SHA-256 اعتبارسنجی می‌کند. قبل از جایگزینی فایل‌ها backup و PHP lint انجام می‌شود و در صورت نصب بودن برنامه migration دیتابیس نیز اجرا می‌شود.

این داده‌ها در آپدیت حفظ می‌شوند:

- `config/app.php`
- `storage/installed.lock`
- Queueها
- Logها
- Backupها
- تنظیمات و دیتابیس نصب‌شده

برای فعال‌سازی بررسی خودکار هر 5 دقیقه هنگام نصب:

```bash
DOMAIN=bot.example.com AUTO_UPDATE=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

## دانلود رسانه

زیرساخت سرور `yt-dlp + ffmpeg + aria2` را نصب می‌کند و کلاس `MediaDownloader` برای دریافت رسانه‌های عمومی در برنامه موجود است. URLهای private/reserved مسدود شده‌اند و این لایه برای دورزدن DRM، کوکی خصوصی یا محدودیت دسترسی طراحی نشده است.

## مسیر پیش‌فرض

```text
/var/www/freebot
```

## نیازمندی DNS

قبل از گرفتن SSL، رکورد A دامنه باید به IP همین سرور اشاره کند. Telegram Webhook برای نصب نهایی به HTTPS معتبر نیاز دارد.
