# FreeBot

ربات PHP + Telegram Webhook با پنل مدیریت، نصبگر وب، Cron، MariaDB، SSL، آپدیت از GitHub و موتور حرفه‌ای دانلود/آپلود رسانه.

## نصب کامل روی Ubuntu/Debian با یک دستور

به‌عنوان `root` اجرا کن:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/bootstrap.sh)
```

اگر دیتابیس `freebot` و کاربر آن را از قبل ساخته‌ای:

```bash
DOMAIN=bot.example.com SKIP_DB=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/bootstrap.sh)
```

Bootstrap به‌صورت خودکار Nginx، PHP-FPM، MariaDB، Certbot، Cron و مسیرهای runtime را آماده می‌کند و سپس Installer وب در این آدرس در دسترس است:

```text
https://YOUR-DOMAIN/install/
```

## موتور حرفه‌ای دانلود و آپلود

نسخه `1.9.0-media-workers` شامل یک صف DB-backed و دو Pool مستقل Worker است:

- Download Workers و Upload Workers جدا از هم
- دانلود و آپلود چند فیلم به‌صورت هم‌زمان
- تعداد Workerهای دانلود و آپلود قابل تنظیم از پنل
- صف Priority دار و Retry/Cancel/Delete
- بازیابی Jobهای گیرکرده یا Workerهای stale
- نمایش درصد، حجم منتقل‌شده و سرعت لحظه‌ای Download/Upload در پنل بدون Refresh
- Supervisor دائمی تحت systemd با Restart خودکار
- فایل‌های ارسالی از این موتور بدون Caption ارسال می‌شوند
- حذف خودکار فایل محلی پس از Upload به‌صورت پیش‌فرض، با گزینه Keep Files در پنل

تنظیمات پیش‌فرض:

```text
Download workers: 3
Upload workers: 2
yt-dlp fragment concurrency: 8
Official Telegram API max file setting: 48 MB safe limit
```

## دانلود هوشمند

ابزارهای زیر نصب و توسط موتور استفاده می‌شوند:

- `yt-dlp` برای تشخیص و دانلود از سایت‌های پشتیبانی‌شده و عمومی
- `aria2c` برای دانلود multi-connection لینک‌های مستقیم
- `ffmpeg` برای merge/remux ویدئو و صدا
- `mediainfo` و `ffprobe` برای تشخیص اطلاعات رسانه

برای منابع پشتیبانی‌شده، yt-dlp می‌تواند fragmentها را هم‌زمان دانلود کند. برای HTTP/HTTPS مستقیم، aria2 با چند اتصال موازی استفاده می‌شود.

این موتور برای URLهای عمومی و بدون DRM است و برای دورزدن DRM یا کنترل دسترسی خصوصی طراحی نشده است.

## پنل رسانه

پس از نصب، در پنل مدیریت بخش زیر وجود دارد:

```text
دانلود و آپلود فیلم
```

در آن می‌توان:

- URL جدید به صف اضافه کرد
- مقصد Telegram را تعیین کرد
- Priority تعیین کرد
- Download/Upload Worker Count را تغییر داد
- Fragment Concurrency را تغییر داد
- Telegram API Base URL را تنظیم کرد
- وضعیت ابزارهای سرور و Supervisor را دید
- سرعت و Progress هر Job را به‌صورت زنده دید
- Job را Cancel، Retry یا Delete کرد

## Telegram Bot API

در حالت API رسمی، موتور برای Uploadهای معمول Bot API محدودیت امن 48MB اعمال می‌کند. برای فایل‌های بزرگ می‌توان Telegram Local Bot API را نصب کرد و Base URL آن را از پنل تنظیم کرد؛ معماری Workerها نیازی به تغییر ندارد.

## سرویس Worker

```bash
systemctl status freebot-media --no-pager
```

Restart:

```bash
systemctl restart freebot-media
```

Log زنده:

```bash
journalctl -u freebot-media -f
```

## آپدیت پروژه

```bash
freebot-update
```

Updater release و overlay را با SHA-256 اعتبارسنجی می‌کند، PHP lint انجام می‌دهد، backup می‌گیرد، migration دیتابیس را اجرا می‌کند و اطلاعات runtime را حفظ می‌کند، از جمله:

- `config/app.php`
- `storage/installed.lock`
- Queueها و Jobها
- Logها
- Downloadهای جاری
- Backupها
- دیتابیس و تنظیمات نصب‌شده

برای Auto Update هنگام نصب:

```bash
DOMAIN=bot.example.com AUTO_UPDATE=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/bootstrap.sh)
```

## تست سلامت نصب

```bash
freebot-health
```

برای تست HTTPS دامنه:

```bash
DOMAIN=bot.example.com freebot-health
```

Health check سرویس‌ها، PHP/pcntl و ابزارهای دانلود را نیز بررسی می‌کند.

## مسیر پیش‌فرض

```text
/var/www/freebot
```

## ریپازیتوری

```text
https://github.com/PEDIHS/freebot
```
