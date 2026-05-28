# avsbhq/avsb — PHP SDK

PHP server SDK for the [A vs B](https://avsb.cloud) feature-flag and experimentation platform.

## Requirements

- PHP 8.2+
- `psr/log` ^3.0

Optional integrations (install separately):

| Package | Purpose |
|---|---|
| `laravel/framework` | Laravel ServiceProvider + Facade + route middleware |
| `symfony/framework-bundle` | Symfony Bundle + DI extension + kernel event listener |
| `predis/predis` | Redis-backed sticky bucket store |

## Installation

```bash
composer require avsbhq/avsb
```

## Quick start

```php
use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;

// Load your datafile (from cache, file, or HTTP)
$datafile = json_decode(file_get_contents('/path/to/datafile.json'), true);

$server = new AvsbServer($datafile);

$context = EvalContext::user('alice', ['plan' => 'pro']);

$flag = $server->getBoolFlag('new-checkout', false, $context);

if ($flag->isEnabled()) {
    // serve new checkout
}
```

## Multi-context evaluation

```php
$context = EvalContext::multi([
    'user'         => ['key' => 'alice', 'plan' => 'pro'],
    'organization' => ['key' => 'acme', 'tier' => 'enterprise'],
]);

$flag = $server->getBoolFlag('enterprise-feature', false, $context);
```

## Laravel integration

Register the service provider in `config/app.php`:

```php
'providers' => [
    Avsbhq\Avsb\Middleware\Laravel\ServiceProvider::class,
],
'aliases' => [
    'Avsb' => Avsbhq\Avsb\Middleware\Laravel\Facade::class,
],
```

Use the facade anywhere:

```php
use Avsbhq\Avsb\Middleware\Laravel\Facade as Avsb;

$flag = Avsb::getBoolFlag('my-flag', false, $context);
```

## Symfony integration

Register the bundle in `config/bundles.php`:

```php
return [
    Avsbhq\Avsb\Middleware\Symfony\Bundle::class => ['all' => true],
];
```

Configure in `config/packages/avsb.yaml`:

```yaml
avsb:
  datafile_path: '%kernel.project_dir%/var/avsb/datafile.json'
  sdk_key: '%env(AVSB_SDK_KEY)%'
```

## Decision logging

```php
use Avsbhq\Avsb\DecisionLog;

$log    = new DecisionLog();
$server = new AvsbServer($datafile, logger: null, decisionLog: $log);

$server->getBoolFlag('my-flag', false, $context);

foreach ($log->getEntries() as $entry) {
    echo $entry->flagKey . ': ' . json_encode($entry->value) . PHP_EOL;
}
```

## OpenFeature

```php
use Avsbhq\Avsb\OpenFeatureProvider;

$provider = new OpenFeatureProvider($server);
// Pass to the OpenFeature SDK:
// OpenFeatureAPI::getInstance()->setProvider($provider);
```

## Running tests

```bash
composer install
./vendor/bin/phpunit
```

The conformance suite (`tests/ConformanceTest.php`) automatically runs all
cases from `packages/avsb-conformance/fixtures/manifest.json` and asserts
that the PHP evaluator produces the same output as the canonical TypeScript
implementation.
