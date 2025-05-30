<p align="center">
<a href="https://packagist.org/packages/pipaypw/payroll"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/pipaypw/payroll"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
</p>


# SpenderBot - Pi Network PHP server-side Service worker package

This is a Pi Network PHP package you can use to integrate the Pi Network sevice worker apps platform with a PHP backend application.

## Install

Install this package as a dependency of your app:

```composer
# With composer:
composer require pipaypw/payroll:dev-main
```

## Example

1. Initialize the SDK
```php
require __DIR__ . '/vendor/autoload.php';
use Pipaypw\Payroll\SpenderBot;

// DO NOT expose these values to public
$apiKey = "YOUR_PI_API_KEY";
$walletPrivateSeed = "S_YOUR_WALLET_PRIVATE_SEED"; // starts with S
$network = "Pi Network";
$sw = new SpenderBot($apiKey, $walletPrivateSeed);
```
