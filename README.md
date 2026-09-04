# FreeBot

ربات PHP + Telegram Webhook با پنل مدیریت، نصبگر وب، Cron، MariaDB، SSL و قابلیت آپدیت از GitHub.

## نصب کامل روی Ubuntu/Debian با یک دستور

به‌عنوان `root` اجرا کن:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/bootstrap.sh)
```

Bootstrap این موارد را آماده می‌کند:

- Nginx
- PHP-FPM و افزونه‌های لازم
- MariaDB
- Certbot / Let's Encrypt
- Cron هر دقیقه
- مسیرهای runtime و Permissionهای مناسب
- `yt-dlp` داخل Python venv مجزا
- `ffmpeg`
- `aria2`
- `mediainfo`
- `jq` و ابزارهای پایه سرور
- `freebot-update` برای دریافت نسخه‌های جدید
- `freebot-health` برای تست کامل نصب

در حین نصب دامنه را وارد می‌کنی. اگر DNS به IP همین سرور اشاره کند، Certbot برای همان دامنه SSL می‌گیرد.

در پایان Installer وب از مسیر زیر در دسترس است:

```text
https://YOUR-DOMAIN/install/
```

Installer وب جداول دیتابیس، `config/app.php`، حساب مدیر، Webhook Secret، Cron Secret و `storage/installed.lock` را می‌سازد.

اگر دیتابیس توسط bootstrap ساخته شود، مشخصات آن فقط روی خود سرور در این فایل ذخیره می‌شود:

```text
/root/.freebot/install-credentials.txt
```

## اگر دیتابیس را از قبل ساخته‌ای

مثلاً اگر دیتابیس و کاربر را قبلاً ساخته‌ای:

```bash
DOMAIN=bot.example.com SKIP_DB=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/bootstrap.sh)
```

بعد همان اطلاعات دیتابیس را در `/install/` وارد کن.

## اگر DNS هنوز آماده نیست

```bash
DOMAIN=bot.example.com SKIP_SSL=1 \
bash <(curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/bootstrap.sh)
```

و بعد از تنظیم DNS:

```bash
certbot --nginx -d bot.example.com
```

## آپدیت پروژه

```bash
freebot-update
```

Updater نسخه انتشار GitHub را دریافت و اعتبارسنجی می‌کند و داده‌های runtime را حفظ می‌کند، از جمله:

- `config/app.php`
- `storage/installed.lock`
- Queueها
- Logها
- Backupها
- تنظیمات نصب‌شده و دیتابیس

## تست سلامت نصب

```bash
freebot-health
```

یا برای تست HTTPS دامنه:

```bash
DOMAIN=bot.example.com freebot-health
```

## دانلود و پردازش رسانه

زیرساخت سرور برای دریافت و پردازش رسانه‌های عمومی و بدون DRM این ابزارها را نصب می‌کند:

- `yt-dlp` برای استخراج و دانلود از منابع پشتیبانی‌شده
- `ffmpeg` برای remux/transcode و پردازش صوت/ویدئو
- `aria2c` برای دانلود سریع و چنداتصاله فایل‌های مستقیم
- `mediainfo` برای تشخیص codec، container، bitrate و metadata

کلاس `MediaDownloader` داخل پروژه برای استفاده برنامه از این ابزارها در نظر گرفته شده است. این لایه برای دورزدن DRM، احراز هویت خصوصی یا کنترل دسترسی طراحی نشده است.

## مسیر پیش‌فرض پروژه

```text
/var/www/freebot
```

## ریپازیتوری

```text
https://github.com/PEDIHS/freebot
```
