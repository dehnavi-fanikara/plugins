# Fanikara Order Sync

این افزونه آیتم‌های CCT با slug برابر `customers_service_orders` را به ERP فنی‌کارا ارسال می‌کند.

## نصب و تنظیم

1. پوشهٔ افزونه را در `wp-content/plugins/fanikara-order-sync` قرار داده و افزونه را فعال کنید.
2. در ERP اصلی و میرور، migrationهای پروژه را اجرا کنید:

   ```bash
   php yii migrate
   ```

3. در هر محیط ERP، یک secret متفاوت و امن تنظیم کنید:

   ```bash
   export FANIKARA_WORDPRESS_ORDER_SYNC_SECRET='a-long-random-secret'
   ```

   منبع سفارش WordPress به‌طور پیش‌فرض همان `idSourceRequest=1` در سینک قدیمی ERP است. فقط در صورت نیاز می‌توان آن را با `FANIKARA_WORDPRESS_ORDER_SOURCE_ID` تغییر داد.

4. در وردپرس به Settings → Fanikara Order Sync بروید؛ endpoint و secret هر مقصد را وارد و مقصد فعال را انتخاب کنید.

endpoint هر ERP:

```
https://YOUR-ERP/integrations/wordpress/orders/v1/orders
```

## رفتار سینک

- فقط CCTهایی که `status=q` دارند ارسال می‌شوند.
- پس از پاسخ موفق ERP، وضعیت آیتم به `status=s` تغییر می‌کند.
- زمان Order از لحظهٔ ایجاد آیتم CCT در وردپرس ثبت می‌شود. مقدار اصلی فیلد `date_time` نیز بدون تغییر در payload audit باقی می‌ماند.
- برای خطای شبکه و خطاهای 5xx/429، افزونه با تأخیر تصاعدی تلاش را تکرار می‌کند.
- برای خطای اعتبارسنجی ERP، آیتم در لیست خطاها می‌ماند تا مدیر آن را بررسی و دوباره صف‌بندی کند.
- مقصد فعال در لحظهٔ ارسال خوانده می‌شود؛ بنابراین می‌توان ERP اصلی یا میرور تست را از پنل انتخاب کرد.
- افزونه بین دو دیتابیس مستقل failover خودکار انجام نمی‌دهد؛ مقصد فعال را مدیر انتخاب می‌کند تا داده بین ERP اصلی و میرور تست پراکنده نشود.
- در Settings → Fanikara Order Sync می‌توان فاصلهٔ ارسال خودکار را به دقیقه یا ساعت تنظیم کرد. ارسال دستی صف نیز همیشه در دسترس است.

## امنیت

هر درخواست با headerهای `X-Fanikara-Timestamp` و `X-Fanikara-Signature` امضا می‌شود. امضا از HMAC-SHA256 روی `timestamp.body` ساخته می‌شود. برای نگهداری امن‌تر secret در وردپرس می‌توان به‌جای ذخیره در option، یکی از ثابت‌های زیر را در `wp-config.php` تعریف کرد:

```php
define('FNK_ORDER_SYNC_PRIMARY_SECRET', '...');
define('FNK_ORDER_SYNC_MIRROR_SECRET', '...');
```
