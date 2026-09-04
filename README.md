# FreeBot — فروشگاه فیلم تلگرام

نسخه `2.0.1-media-migration` بر پایه مستقیم پروژه PHP فروش فیلم `1.3.1-product-grid` ساخته شده است. این ریپو هیچ وابستگی یا فایلی از پروژه‌های VPN، Proxy، Overlay یا Hotfixهای قدیمی ندارد.

## امکانات اصلی

- فروش فیلم و پکیج با کانال خصوصی، کیف پول، رسید و پرداخت هوشمند
- صف پایدار Media با وضعیت‌های `queued`، `downloading`، `downloaded`، `uploading`، `completed`، `failed` و `cancelled`
- Download Worker و Upload Worker چندگانه با systemd
- Lease اختصاصی هر Job، Lock Token، Heartbeat، بازیابی پس از Restart و Retry نمایی
- دانلود با `yt-dlp`، `aria2c`، `ffmpeg` و بررسی فایل با `mediainfo`
- دانلود چنداتصاله و Fragment هم‌زمان
- ثبت درصد، سرعت دانلود/آپلود، ETA، تعداد تلاش و Eventهای مرحله‌ای
- ارسال تلگرام بدون Caption و Retry ویژه خطای 429
- کنترل Retry، Cancel و Delete از پنل مدیریت

## نصب Ubuntu خام

روی Ubuntu 22.04 یا 24.04، دستور زیر را اجرا کنید و دامنه و ایمیل را وارد کنید:

```bash
curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh -o /tmp/freebot-install.sh && sudo bash /tmp/freebot-install.sh
```

اسکریپت، PHP 8.3، Nginx، MariaDB، SSL، Cron، ابزارهای Media و Worker Serviceها را نصب می‌کند. سپس این آدرس را باز کنید:

```text
https://YOUR-DOMAIN/install.php
```

در نصب بدون پرسش می‌توان متغیرها را مشخص کرد:

```bash
sudo FREEBOT_DOMAIN=bot.example.com FREEBOT_EMAIL=admin@example.com FREEBOT_DOWNLOAD_WORKERS=2 FREEBOT_UPLOAD_WORKERS=2 bash /tmp/freebot-install.sh
```

## دیتابیس

پیش از بازکردن `install.php` یک دیتابیس و کاربر بسازید:

```sql
CREATE DATABASE freebot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'freebot'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON freebot.* TO 'freebot'@'localhost';
FLUSH PRIVILEGES;
```

اطلاعات واقعی در `config.php` ساخته می‌شوند؛ این فایل در Git ثبت نمی‌شود.

## Workerها

به‌طور پیش‌فرض دو Worker دانلود و دو Worker آپلود ساخته می‌شوند:

```bash
systemctl status freebot-download@1 freebot-download@2
systemctl status freebot-upload@1 freebot-upload@2
```

تعداد نمونه‌ها هنگام نصب با `FREEBOT_DOWNLOAD_WORKERS` و `FREEBOT_UPLOAD_WORKERS` قابل تنظیم است. هر Job فقط با Lease و Lock Token معتبر قابل اجراست؛ Jobهای قفل‌مانده پس از قطع Heartbeat خودکار بازیابی می‌شوند.

## آپدیت

```bash
sudo /var/www/freebot/update.sh
```

آپدیت فقط Fast-forward است، از `config.php` نسخه پشتیبان می‌گیرد، فایل‌های tracked را جایگزین می‌کند و نسخه تکراری در مسیر نصب نمی‌سازد.

## Healthcheck

```bash
sudo /var/www/freebot/healthcheck.sh
```

این تست، PHP 8.3، ابزارهای Media، PHP lint، Nginx، دسترسی Storage، اتصال دیتابیس، Schema و Workerهای فعال را بررسی می‌کند.

## Reset نصب قبلی

`reset-install.sh` دیتابیس را حذف نمی‌کند. مسیر قبلی را به `/var/backups/freebot` منتقل می‌کند و فقط سرویس‌ها و تنظیمات شناخته‌شده FreeBot را پاک می‌کند:

```bash
sudo /var/www/freebot/reset-install.sh
```

## تست خودکار

GitHub Actions در هر Push این موارد را اجرا می‌کند:

- PHP 8.3 lint
- ShellCheck و تست Installer
- تست یکپارچه MariaDB برای Queue، Lock، Heartbeat، Retry و وضعیت‌های Job

فقط محتوایی را منتشر یا بفروشید که حق قانونی انتشار آن را دارید.
