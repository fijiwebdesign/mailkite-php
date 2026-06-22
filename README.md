# MailKite for PHP

Official [MailKite](https://mailkite.dev) SDK. One low-level `request()` plus one
method per endpoint. Requires PHP 7.4+ with `ext-curl`.

## Install

```bash
composer require mailkite/mailkite
```

## Usage

```php
<?php
require 'vendor/autoload.php';

$mk = new \MailKite\Client(getenv('MAILKITE_API_KEY'));

$res = $mk->send([
    'from' => 'hello@myapp.ai',
    'to' => 'ada@example.com',
    'subject' => 'Your invoice #1042',
    'html' => '<p>Thanks! Receipt attached.</p>',
]);
```

Point at a different base URL with `new \MailKite\Client($key, 'https://api.mailkite.dev')`.

## Methods

`send($message)`, `listDomains()`, `createDomain($body)`, `getDomain($id)`,
`deleteDomain($id)`, `verifyDomain($id)`, `setWebhook($id, $body)`,
`deleteWebhook($id)`, `testWebhook($id)`, `checkDomainAvailability($domain)`,
`registerDomain($body)`, `listRoutes()`, `createRoute($body)`,
`listMessages()`, `getMessage($id)`, `retryDelivery($id)`.

## Errors

```php
use MailKite\MailKiteException;

try {
    $mk->send($msg);
} catch (MailKiteException $e) {
    error_log($e->status . ' ' . $e->getMessage());
}
```

See the [full docs](https://mailkite.dev/docs/libraries).
