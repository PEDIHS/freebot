# FreeBot

ربات PHP + Telegram Webhook با پنل مدیریت، نصبگر وب، Cron، MariaDB، SSL، آپدیت از GitHub و موتور حرفه‌ای دانلود/آپلود رسانه.

## نصب کامل روی Ubuntu/Debian

به‌عنوان `root` اجرا کن:

```bash
rm -rf /tmp/freebot-setup && \
git -c http.version=HTTP/1.1 clone --depth 1 --branch main https://github.com/PEDIHS/freebot.git /tmp/freebot-setup && \
bash /tmp/freebot-setup/bootstrap.sh
```

اگر دیتابیس `freebot` و کاربر آن را از قبل ساخته‌ای:

```bash
rm -rf /tmp/freebot-setup && \
git -c http.version=HTTP/1.1 clone --depth 1 --branch main https://github.com/PEDIHS/freebot.git /tmp/freebot-setup && \
SKIP_DB=1 bash /tmp/freebot-setup/bootstrap.sh
```

Bootstrap به‌صورت خودکار Nginx، PHP-FPM، MariaDB، Certbot، Cron و مسیرهای runtime را آماده می‌کند. release اصلی، media patch و hotfix از همان clone محلی بازسازی می‌شوند؛ سپس آرشیوهای tar تست، فایل‌های ضروری کنترل و تمام فایل‌های PHP lint می‌شوند. به این ترتیب نصب به Raw GitHub یا checksumهای تکراری و stale وابسته نیست.

بعد از آماده‌شدن زیرساخت، Installer وب در این آدرس در دسترس است:

```text
https://YOUR-DOMAIN/install/
```

## موتور حرفه‌ای دانلود و آپلود

نسخه `1.9.1-media-workers` شامل یک صف DB-backed و دو Pool مستقل Worker است:

- Download Workers و Upload Workers جدا از هم
- دانلود و آپلود چند فیلم به‌صورت هم‌زمان
- تعداد Workerهای دانلود و آپلود قابل تنظیم از پنل
- صف Priority دار و Retry/Cancel/Delete
- بازیابی Jobهای گیرکرده یا Workerهای stale
- نمایش درصد، حجم منتقل‌شده و سرعت لحظه‌ای Download/Upload در پنل بدون Refresh
- Supervisor دائمی تحت systemd با Restart خودکار
- ظرفیت Workerها بر اساس تعداد Worker فعال و Jobهای منتظر به‌صورت واقعی پر می‌شود
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

در آن می‌توان URL جدید به صف اضافه کرد، مقصد Telegram و Priority را تعیین کرد، تعداد Download/Upload Workerها و Fragment Concurrency را تغییر داد، Telegram API Base URL را تنظیم کرد، وضعیت ابزارهای سرور و Supervisor را دید و سرعت و Progress هر Job را به‌صورت زنده مشاهده کرد. Jobها نیز قابلیت Cancel، Retry و Delete دارند.

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

Updater آخرین repo را clone می‌کند، release را محلی بازسازی می‌کند، سلامت آرشیوها و فایل‌های ضروری را بررسی می‌کند، PHP lint انجام می‌دهد، backup می‌گیرد، migration دیتابیس را اجرا می‌کند و اطلاعات runtime را حفظ می‌کند، از جمله `config/app.php`، `storage/installed.lock`، Queueها، Jobها، Logها، Downloadهای جاری، Backupها و دیتابیس/تنظیمات نصب‌شده.

برای Auto Update هنگام نصب:

```bash
AUTO_UPDATE=1 SKIP_DB=1 bash /tmp/freebot-setup/bootstrap.sh
```

## تست سلامت نصب

```bash
freebot-health
```

برای تست HTTPS دامنه:

```bash
DOMAIN=bot.example.com freebot-health
```

Health check سرویس‌ها، PHP/pcntl، Nginx، Worker Supervisor و ابزارهای دانلود را بررسی می‌کند.

## مسیر پیش‌فرض

```text
/var/www/freebot
```

## ریپازیتوری

```text
https://github.com/PEDIHS/freebot
```
