# FreeBot

ربات PHP/Telegram با پنل مدیریت، Web Installer، Nginx/PHP-FPM/MariaDB، SSL، Cron، آپدیت GitHub و موتور حرفه‌ای دانلود و آپلود رسانه.

نسخه فعلی: `1.9.2-media-engine`

## نصب روی Ubuntu/Debian

روش پیشنهادی و مقاوم در برابر خطاهای Raw GitHub:

```bash
rm -rf /tmp/freebot-setup && \
git -c http.version=HTTP/1.1 clone --depth 1 --branch main \
https://github.com/PEDIHS/freebot.git /tmp/freebot-setup && \
SKIP_DB=1 bash /tmp/freebot-setup/bootstrap.sh
```

اگر دیتابیس را از قبل نساخته‌ای، `SKIP_DB=1` را حذف کن.

Installer به‌صورت خودکار پیش‌نیازها را نصب می‌کند، نسخه پایه سالم را با SHA-256 اعتبارسنجی می‌کند، `media-overlay` جدید را با SHA-256 بررسی و اعمال می‌کند، تمام PHPها را lint می‌کند و تست داخلی پروژه را قبل از کپی روی `/var/www/freebot` اجرا می‌کند.

پس از نصب:

```text
https://YOUR-DOMAIN/install/
```

## موتور Media

- Download Worker و Upload Worker مستقل
- چند دانلود و چند آپلود همزمان
- پیش‌فرض: 3 Download Worker و 2 Upload Worker
- تنظیم تعداد Workerها از پنل
- صف DB-backed با Priority، Retry، Cancel و Delete
- نمایش لحظه‌ای درصد، حجم و سرعت دانلود/آپلود در پنل
- `yt-dlp` برای سایت‌های عمومی پشتیبانی‌شده
- `aria2c` برای لینک‌های مستقیم و multi-connection
- `ffmpeg` برای merge/remux
- `ffprobe`/`mediainfo` برای اطلاعات رسانه
- Fragment concurrency قابل تنظیم
- Supervisor دائمی با systemd و Restart خودکار
- ارسال ویدیو از موتور Media بدون Caption
- محافظت SSRF برای localhost و شبکه‌های private/reserved

این بخش برای URLهای عمومی و محتوای بدون DRM طراحی شده است.

## سرویس‌ها

```bash
freebot-health
systemctl status freebot-media --no-pager
journalctl -u freebot-media -f
```

## آپدیت

```bash
freebot-update
```

Updater قبل از جایگزینی فایل‌ها backup می‌گیرد، release جدید را بازسازی و تست می‌کند، `config/app.php` و کل `storage/` را حفظ می‌کند و در نصب فعال migration دیتابیس را اجرا می‌کند.

برای فعال‌سازی بررسی خودکار آپدیت هنگام نصب:

```bash
AUTO_UPDATE=1 SKIP_DB=1 bash /tmp/freebot-setup/bootstrap.sh
```

## مسیر پیش‌فرض

```text
/var/www/freebot
```
