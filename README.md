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

در پایان آدرس زیر باز می‌شود/نمایش داده می‌شود:

```text
https://YOUR-DOMAIN/install/
```

اگر دیتابیس توسط اسکریپت ساخته شود، مشخصات آن فقط روی سرور در این فایل ذخیره می‌شود:

```text
/root/.freebot/install-credentials.txt
```

## نصب با متغیرها

```bash
DOMAIN=bot.example.com \
DB_NAME=freebot \
DB_USER=freebot \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

برای استفاده از دیتابیس از قبل ساخته‌شده:

```bash
DOMAIN=bot.example.com SKIP_DB=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

اگر DNS هنوز آماده نیست:

```bash
DOMAIN=bot.example.com SKIP_SSL=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

و بعداً:

```bash
certbot --nginx -d bot.example.com
```

## آپدیت

```bash
freebot-update
```

Updater فقط کدهای Git را آپدیت می‌کند. این داده‌ها حفظ می‌شوند:

- `config/app.php`
- `storage/installed.lock`
- Queueها
- Logها
- Backupها
- تنظیمات و دیتابیس نصب‌شده

قبل از آپدیت نیز یک backup در `storage/backups` ایجاد می‌شود و PHP lint اجرا می‌شود.

برای فعال‌سازی بررسی خودکار هر 5 دقیقه هنگام نصب:

```bash
DOMAIN=bot.example.com AUTO_UPDATE=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh)
```

برای محیط production بهتر است آپدیت دستی یا CI/CD کنترل‌شده استفاده شود.

## دانلود رسانه

زیرساخت سرور `yt-dlp + ffmpeg + aria2` را نصب می‌کند و کلاس `MediaDownloader` برای دریافت رسانه‌های عمومی در برنامه موجود است. این لایه برای دورزدن DRM، کوکی خصوصی یا محدودیت دسترسی طراحی نشده است.

## مسیر پیش‌فرض

```text
/var/www/freebot
```

## نیازمندی DNS

قبل از گرفتن SSL، رکورد A دامنه باید به IP همین سرور اشاره کند. Telegram Webhook برای نصب نهایی به HTTPS معتبر نیاز دارد.
