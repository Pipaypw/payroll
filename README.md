<p align="center">
<a href="https://packagist.org/packages/pipaypw/payroll"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/pipaypw/payroll"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
</p>


# Payroll -  Pi Network PHP server-side Service worker package

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



### Stream for payments and events

In this example we will listen for received payments for an account.

```php
$sdk = $sw->getHorizonClient($network);

 // Create two accounts, so that we can send a payment.
$keypair1 = KeyPair::random();
$keypair2 = KeyPair::random();
$acc1Id = $keypair1->getAccountId();
$acc2Id = $keypair2->getAccountId();
FriendBot::fundTestAccount($acc1Id);
FriendBot::fundTestAccount($acc2Id);

// create a child process that listens to payment steam
$pid = pcntl_fork();

if ($pid == 0) {
    // Subscribe to listen for payments for account 2.
    // If we set the cursor to "now" it will not receive old events such as the create account operation.
    $sdk->payments()->forAccount($acc2Id)->cursor("now")->stream(function(OperationResponse $payment) {
        printf('Payment operation %s id %s' . PHP_EOL, get_class($payment), $payment->getOperationId());
        // exit as soon as our specific payment has been received
        if ($payment instanceof PaymentOperationResponse && floatval($payment->getAmount()) == 100.00) {
            exit(1);
        }
    });
}

// send the payment from account 1 to account 2
$acc1 = $sdk->requestAccount($acc1Id);
$paymentOperation = (new PaymentOperationBuilder($acc2Id, Asset::native(), "100"))->build();
$transaction = (new TransactionBuilder($acc1))->addOperation($paymentOperation)->build();
$transaction->sign($keypair1, Network::testnet());
$response = $sdk->submitTransaction($transaction);

// wait for child process to finish.
while (pcntl_waitpid(0, $status) != -1) {
    $status = pcntl_wexitstatus($status);
    echo "Completed with status: $status \n";
}
```

## Overall flow for Payroll

To create a SpenderBot payment using the Pi PHP SDK, here's an overall flow you need to follow:
> Intentionaly left blank

## Apps

- PIPAY Dapp [SmartContracts for Pi Network](https://pipay.pw).
- PIPAY WALLET [Wallet on Pi, Fast and instant payments](https://wallet.pipay.pw).
