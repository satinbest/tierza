# Tierza (تیرزا)

[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PHP: >=8.0](https://img.shields.io/badge/PHP-%3E%3D8.0-777BB4.svg)](https://www.php.net/)
[![WooCommerce: >=8.0](https://img.shields.io/badge/WooCommerce-%3E%3D8.0-96588a.svg)](https://woocommerce.com/)

**Tierza** is a free and open-source, modular B2B pricing plugin for WooCommerce, specifically engineered for Iranian wholesale, corporate, and multi-tier merchant workflows.

---

## 🇮🇷 درباره افزونه تیرزا

تیرزا یک افزونه قیمت‌گذاری پیشرفته B2B، عمده‌فروشی و همکار برای ووکامرس است که با رعایت استانداردهای روز نرم‌افزاری و معماری تمیز (Clean Architecture) توسعه یافته است.

### ویژگی‌های کلیدی (نسخه ۱.۰):
- تعریف نامحدود سطوح و گروه‌های قیمتی همکار و B2B.
- تخصیص یک گروه اصلی به هر کاربر (بدون پیچیدگی‌های ناسازگار).
- تخفیف درصدی و قیمت ثابت در سطح گروه، دسته‌بندی، محصول و متغیر.
- قیمت‌گذاری اختصاصی برای مشتریان خاص.
- الگوریتم تقدم قطعی و پیش‌بینی‌پذیر قوانین (بدون تداخل یا همپوشانی ناخواسته).
- حفظ ۱۰۰٪ یکپارچگی قیمت‌های اصلی و حراج ووکامرس (عدم دستکاری مخرب در دیتابیس).
- سازگاری کامل با کش‌ها، پرداخت استاندارد ووکامرس، و آماده‌سازی برای HPOS و Block Checkout.
- رابط مدیریت مدرن، واکنش‌گرا و بومی‌شده به زبان فارسی با چینش راست‌به‌چپ (RTL).
- متن‌باز، بدون وابستگی‌های غیررایگان، بدون نیاز به لایسنس یا ionCube.

---

## 🏗️ Architecture

Tierza adheres strictly to layered architecture principles:

1. **Domain Layer**: Pure business models, pricing rules, resolution policies, and mathematical evaluations with zero external coupling.
2. **Application Layer**: Use cases, command and query handlers, and pricing orchestration.
3. **Infrastructure Layer**: Persistence, options, system environment, and logging.
4. **Integration Layer (WooCommerce)**: Adapts pricing calculations to WooCommerce hooks, filters, Cart/Checkout pipelines, and REST/Store APIs.
5. **REST API Layer**: Secure, capability-checked endpoints under `/wp-json/tierza/v1/`.
6. **Admin UI Layer**: Persian-native, RTL-first administration interface.

---

## ⚙️ Minimum Requirements

- **PHP**: 8.0 or newer
- **WordPress**: 6.2 or newer
- **WooCommerce**: 8.0 or newer

---

## 🚀 Installation

1. Download the latest `tierza.zip` release from the repository or build it locally.
2. Log in to your WordPress Admin.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Select `tierza.zip` and click **Install Now**.
5. Click **Activate Plugin**.

---

## 🛠️ Development & Testing

```bash
# Install PHP dependencies
composer install

# Run PHPUnit tests
composer test

# Run PHPStan static analysis
composer phpstan

# Compile translation MO files
php bin/compile-mo.php

# Package installable release ZIP
powershell -ExecutionPolicy Bypass -File bin/build-zip.ps1
```

---

## 📄 License

Tierza is licensed under the **GNU General Public License v2.0 or later** ([GPL-2.0-or-later](LICENSE)).
