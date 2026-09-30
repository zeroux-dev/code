# راه‌اندازی ریپل روی هاست cPanel

این راهنما سایت (پنل کاربر و مدیر) و بک‌اند (دایرکت هوشمند، ایمیل، پرداخت) را روی هاست cPanel خودت بالا می‌آورد. وردپرس لازم نیست.

## ۰. پیش‌نیازها
- **PHP 8.1 یا بالاتر** (cPanel → MultiPHP Manager). افزونه‌ها: `pdo_mysql`, `curl`, `sodium`, `fileinfo`, `mbstring` (در Select PHP Version تیک بزن).
- **SSL فعال** (cPanel → SSL/TLS Status → AutoSSL). اینستاگرام و زرین‌پال فقط با HTTPS کار می‌کنند.
- **مهم درباره‌ی محل هاست:** اگر سرور داخل ایران است، احتمالاً به `graph.instagram.com` دسترسی ندارد. بعد از نصب، از «پنل مدیر ← تنظیمات فروش ← بررسی اتصال‌های سرور» همین را چک کن. اگر اینستاگرام «بسته است» نشان داد، باید سایت را روی هاست خارج از ایران ببری.

## ۱. ساخت فایل نصب
روی کامپیوتر خودت در پوشه‌ی پروژه: `sh tools/package.sh` → فایل `repol-deploy.zip` ساخته می‌شود.

## ۲. آپلود
cPanel → File Manager → `public_html` → Upload → `repol-deploy.zip` → Extract.
(گزینه‌ی Show Hidden Files را روشن کن تا فایل‌های `.htaccess` را ببینی.)

## ۳. دیتابیس
cPanel → MySQL Database Wizard:
1. دیتابیس بساز، مثلاً `CPANELUSER_repol`.
2. کاربر بساز با رمز قوی.
3. **ALL PRIVILEGES** بده.
جدول‌ها در اولین بازدید سایت خودکار ساخته می‌شوند.

## ۴. ایمیل info@repol.ir
cPanel → Email Accounts → Create → `info@repol.ir`.
بعد روی **Connect Devices** بزن: آدرس سرور خروجی (SMTP) و پورت ۴۶۵ را آنجا می‌بینی.
برای اینکه ایمیل‌ها به Spam نروند: cPanel → Email Deliverability → برای repol.ir گزینه‌های SPF و DKIM را **Repair/Install** کن.

## ۵. فایل تنظیمات (مهم‌ترین مرحله)
فایل `public_html/api/config.sample.php` را کپی کن به **`/home/CPANELUSER/repol-config.php`** (یعنی یک پوشه بالاتر از public_html، جایی که از وب قابل دسترسی نیست) و مقادیر را پر کن:

| کلید | چه بنویسم |
|---|---|
| `db` | نام دیتابیس، کاربر و رمز مرحله‌ی ۳ |
| `secret` | ۶۴ کاراکتر تصادفی. در Terminal: `php -r "echo bin2hex(random_bytes(32));"` |
| `setup_token` | یک رمز یک‌بارمصرف برای ساخت حساب مدیر |
| `storage_dir` | `/home/CPANELUSER/repol-storage` (پوشه خودکار ساخته می‌شود) |
| `mail` | اطلاعات مرحله‌ی ۴ |
| `zarinpal.merchant_id` | مرچنت‌کد ۳۶ کاراکتری از پنل زرین‌پال |
| `instagram` | مرحله‌ی ۸ |
| `telegram` | اختیاری: توکن ربات از @BotFather و شناسه‌ی چت خودت |

## ۶. ساخت حساب مدیر
`https://repol.ir` را باز کن. صفحه‌ی راه‌اندازی نمایش داده می‌شود: نام، ایمیل، رمز (حداقل ۱۲ کاراکتر) و همان `setup_token` را وارد کن. بعد از این، راه‌اندازی قفل می‌شود.

## ۷. Cron (برای پرداخت TRX خودکار، تمدید توکن اینستاگرام و یادآور اشتراک)
cPanel → Cron Jobs → Once Per Minute:
```
/usr/local/bin/php /home/CPANELUSER/public_html/api/cron.php >/dev/null 2>&1
```

## ۸. اتصال اینستاگرام (Meta Developer)
1. در [developers.facebook.com](https://developers.facebook.com) یک App از نوع **Business** بساز و محصول **Instagram** را اضافه کن ← **API setup with Instagram login**.
2. `Instagram app ID` و `Instagram app secret` را در `instagram.app_id` و `instagram.app_secret` بگذار.
3. **Webhooks**: Callback URL = `https://repol.ir/api/webhook/instagram` و Verify token = همان `instagram.verify_token`. فیلدهای `comments`, `messages`, `messaging_postbacks` را Subscribe کن.
4. **Business login settings → OAuth redirect URI**: `https://repol.ir/api/instagram/callback`
5. **App settings → Basic**: Privacy Policy URL = `https://repol.ir/privacy.html` و Data deletion URL = `https://repol.ir/data-deletion.html`.
6. تا قبل از تأیید Meta: پیج خودت و پیج‌های آزمایشی را در **App roles → Instagram testers** اضافه کن. همین حالا روی همین پیج‌ها کار می‌کند.
7. برای اینکه همه‌ی مشتری‌ها بتوانند پیجشان را وصل کنند: **App Review** برای `instagram_business_basic`، `instagram_business_manage_messages`، `instagram_business_manage_comments` (Advanced Access) + **Business Verification**.
8. در پنل: اتصال‌ها ← «اتصال با اینستاگرام».

## ۹. پرداخت
- **زرین‌پال:** بعد از وارد کردن merchant_id، در پنل مدیر ← تنظیمات فروش، «فعال‌سازی درگاه زرین‌پال» را تیک بزن. دامنه‌ی repol.ir باید در پنل زرین‌پال ثبت شده باشد. برای تست، `sandbox => true`.
- **TRX:** آدرس کیف پول TRON را در تنظیمات فروش وارد و فعال کن. هر فاکتور مبلغ یکتای خودش را دارد؛ Cron واریزها را هر دقیقه بررسی و اشتراک را خودکار فعال می‌کند (کاربر می‌تواند TxID را هم خودش وارد کند).
- **کیف پول و ریالی دستی** مثل قبل کار می‌کنند.

## ۱۰. بررسی نهایی
پنل مدیر ← تنظیمات فروش ← **بررسی اتصال‌های سرور**. همه باید «در دسترس» باشند.
آدرس `https://repol.ir/api/health` باید `{"ok":true,"db":true,...}` برگرداند.

## تست روی کامپیوتر خودت (برای توسعه‌دهنده)
```
cp api/config.sample.php api/config.php   # env=development, mail.log_only=true
php -S localhost:8100 api/tests/dev-router.php
php -S localhost:8101 api/tests/mock-graph.php   # اینستاگرام ساختگی
php api/tests/smoke.php                           # ۶۴ تست سرتاسری
```
