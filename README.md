# avsbhq/avsb: PHP SDK

Server-side PHP SDK for the [A vs B](https://app.avsb.cloud) feature-flag and experimentation platform.

PHP 8.2+. PSR-3 logger, PSR-4 autoloading, PSR-7 compatible. Laravel ServiceProvider and Symfony Bundle included.

---

## 1. Install

```bash
composer require avsbhq/avsb
```

Composer package: `avsbhq/avsb`. **Runtime requirement:** PHP 8.2 or later, `psr/log ^3.0`.

Optional integrations (add to `require` when needed):

| Package | Purpose |
|---|---|
| `laravel/framework` | Laravel ServiceProvider, Facade, route middleware |
| `symfony/framework-bundle` | Symfony Bundle, DI extension, kernel event listener |
| `predis/predis` | Redis-backed sticky bucket store |

---

## 2. Quickstart

```php
<?php

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;

// Load your datafile. In production: fetch from CDN, cache, or local file.
$datafile = json_decode(
    file_get_contents('https://cdn.avsb.cloud/datafiles/' . $_ENV['AVSB_SDK_KEY'] . '.json'),
    associative: true
);

$server  = new AvsbServer($datafile);
$context = EvalContext::user('alice', ['plan' => 'pro']);

$flag = $server->getBoolFlag('checkout-v2', false, $context);

if ($flag->isEnabled()) {
    // serve new checkout experience
}

echo $flag->getVariationKey(); // "on" / "off" / ...
echo $flag->getSource();       // "rule" / "default" / "not_found" / ...
```

---

## 3. SDK keys

Get a **server SDK key** from your A vs B project's Environments page. Store it in your environment; never hard-code it.

```bash
export AVSB_SDK_KEY=sdk-...
```

```php
$sdkKey   = $_ENV['AVSB_SDK_KEY'];
$datafile = json_decode(
    file_get_contents("https://cdn.avsb.cloud/datafiles/{$sdkKey}.json"),
    associative: true
);
$server = new AvsbServer($datafile);
```

Never use the public (client-side) SDK key in server code. Server SDK keys carry elevated trust and must not be included in browser bundles or API responses.

---

## 4. Identity

`EvalContext` is a readonly value object. All construction goes through static factory methods.

### `EvalContext::user(key, attributes, kind)`: single-kind context

```php
use Avsbhq\Avsb\EvalContext;

$context = EvalContext::user('alice', [
    'plan'       => 'pro',
    'beta_tester' => true,
]);
```

The third parameter `$kind` defaults to `'user'`. Pass a different value to build a non-user single-kind context:

```php
$orgContext = EvalContext::user('acme-corp', ['tier' => 'enterprise'], 'organization');
```

### `EvalContext::fromArray(array)`: wrap a raw decoded JSON array

```php
$context = EvalContext::fromArray($request->get('context'));
```

### `identify(context)`: notify downstream integrations

```php
$server->identify($context);
```

---

## 5. Multi-context

```php
use Avsbhq\Avsb\EvalContext;

$context = EvalContext::multi([
    'user'         => ['key' => 'alice', 'plan' => 'pro'],
    'organization' => ['key' => 'acme', 'tier' => 'enterprise'],
]);

$flag = $server->getBoolFlag('enterprise-feature', false, $context);
```

`EvalContext::multi` accepts an array keyed by context kind. Each sub-array must contain a `key` entry; the `kind` key is set automatically from the array key. `isMulti()` returns `true` on the resulting object.

---

## 6. Reading flags

### Typed methods

```php
$showBanner = $server->getBoolFlag('promo-banner',  false,   $context);
$theme      = $server->getStringFlag('ui-theme',    'light', $context);
$maxRetries = $server->getNumberFlag('max-retries', 3.0,     $context);
$config     = $server->getJsonFlag('api-config',    [],      $context);
```

A `$defaultValue` is required on every call. It is returned when the flag is not found, the datafile is not loaded, or an evaluation error occurs.

### `Flag`: full evaluation envelope

Every `get*Flag` method returns an `Avsbhq\Avsb\Flag` instance:

```php
$flag = $server->getBoolFlag('checkout-v2', false, $context);

$flag->getValue();        // mixed: the evaluated value
$flag->getVariationKey(); // string|null: matched variation key
$flag->getSource();       // string: "rule"|"holdout"|"default"|"not_found"|...
$flag->getRuleId();       // string|null: matched rule id
$flag->getRuleType();     // string|null: "ab_test"|"targeted_delivery"|"holdout"|"bandit"
$flag->getReasons();      // array<string>
$flag->getEvaluatedAt();  // int: ms epoch
$flag->getDurationMicros(); // int: microseconds in evaluator
$flag->isEnabled();       // bool: source=="rule" && truthy value
$flag->exists();          // bool: source != "not_found"
```

### Decision log

```php
use Avsbhq\Avsb\DecisionLog;

$log    = new DecisionLog();
$server = new AvsbServer($datafile, logger: null, decisionLog: $log);

$server->getBoolFlag('checkout-v2', false, $context);

foreach ($log->getEntries() as $entry) {
    echo $entry->flagKey . ': ' . json_encode($entry->value) . PHP_EOL;
}
```

Each `DecisionLogEntry` contains the same fields as `Flag` plus `contextSummary` (kind + key only; attributes are never logged).

---

## 7. Tracking events

```php
use Avsbhq\Avsb\TrackPayload;

$server->track('purchase_completed', $context, new TrackPayload(
    value:      49.99,
    properties: ['plan' => 'pro', 'currency' => 'usd'],
));
```

`$value` maps to the numeric metric quantity. `$properties` is forwarded as-is to the collection endpoint. Both are optional.

---

## 8. Error handling

`AvsbServer` accepts any PSR-3 `LoggerInterface`. It defaults to `NullLogger` when none is supplied.

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Avsbhq\Avsb\AvsbServer;

$logger = new Logger('avsb');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

$server = new AvsbServer($datafile, logger: $logger);
```

The constructor signature:

```php
public function __construct(
    array              $datafile,
    ?LoggerInterface   $logger     = null,
    ?DecisionLog       $decisionLog = null
)
```

Evaluation methods do not throw on flag-not-found or type mismatch. They return the supplied `$defaultValue` with `source: "not_found"` or `source: "default"`. Exceptions are only thrown for programmer errors (e.g. passing `null` as `$defaultValue` where the type annotation requires a scalar).

---

## 9. Pre-evaluating flags for server-rendered responses

PHP runs per-request. Construct `AvsbServer` once (ideally in a DI container), load the datafile from a local cache or file, and evaluate flags at the top of each controller action:

```php
public function show(Request $request): Response
{
    $context = EvalContext::user(
        $request->user()->id,
        ['plan' => $request->user()->plan]
    );

    $flags = [
        'newCheckout' => $this->avsbServer->getBoolFlag('checkout-v2',   false, $context),
        'oneClick'    => $this->avsbServer->getBoolFlag('one-click-buy', false, $context),
    ];

    return view('checkout', $flags);
}
```

### Laravel integration

Register the service provider in `config/app.php`:

```php
'providers' => [
    Avsbhq\Avsb\Middleware\Laravel\ServiceProvider::class,
],
'aliases' => [
    'Avsb' => Avsbhq\Avsb\Middleware\Laravel\Facade::class,
],
```

Publish and edit the config stub:

```bash
php artisan vendor:publish --provider="Avsbhq\Avsb\Middleware\Laravel\ServiceProvider"
```

`config/avsb.php`:

```php
return [
    'datafile_path' => env('AVSB_DATAFILE_PATH', storage_path('avsb/datafile.json')),
    'sdk_key'       => env('AVSB_SDK_KEY', ''),
];
```

Use the facade anywhere:

```php
use Avsbhq\Avsb\Middleware\Laravel\Facade as Avsb;

$flag = Avsb::getBoolFlag('checkout-v2', false, $context);
```

### Symfony integration

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

Inject `AvsbServer` anywhere via autowiring:

```php
class CheckoutController extends AbstractController
{
    public function __construct(private readonly AvsbServer $avsb) {}

    public function index(): Response
    {
        $ctx  = EvalContext::user($this->getUser()->getId());
        $flag = $this->avsb->getBoolFlag('checkout-v2', false, $ctx);
        // ...
    }
}
```

---

## 10. Graceful shutdown

PHP processes are short-lived by default. The `AvsbServer` constructor is synchronous and there is no background thread to stop.

When running under a long-lived PHP runtime (ReactPHP, Swoole, FrankenPHP), call `__destruct` or implement your own cleanup:

```php
// Explicit cleanup before the event loop exits
$server = null; // triggers __destruct
```

If you collect a `DecisionLog`, flush it to your analytics pipeline before the process ends:

```php
register_shutdown_function(function () use ($server, $log) {
    foreach ($log->getEntries() as $entry) {
        sendToAnalytics($entry);
    }
});
```

---

## 11. Testing

Use `InMemoryDatafileLoader` to supply a fake datafile without filesystem or network access:

```php
use Avsbhq\Avsb\TestUtils\InMemoryDatafileLoader;
use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;
use PHPUnit\Framework\TestCase;

class CheckoutFlagTest extends TestCase
{
    private AvsbServer $server;

    protected function setUp(): void
    {
        $datafile = InMemoryDatafileLoader::loadJson(<<<'JSON'
        {
          "version": 2,
          "sdkKey": "sdk-test",
          "flags": [{
            "id": "flag_checkout-v2",
            "key": "checkout-v2",
            "type": "boolean",
            "enabled": true,
            "defaultVariationId": "var_off",
            "variations": [
              {"id": "var_off", "key": "off", "value": false},
              {"id": "var_on",  "key": "on",  "value": true}
            ],
            "rules": []
          }],
          "audiences": []
        }
        JSON);

        $this->server = new AvsbServer($datafile);
    }

    public function testFlagExists(): void
    {
        $ctx  = EvalContext::user('alice');
        $flag = $this->server->getBoolFlag('checkout-v2', false, $ctx);

        $this->assertTrue($flag->exists());
        $this->assertContains($flag->getSource(), ['rule', 'default']);
    }
}
```

Run the test suite:

```bash
composer install
./vendor/bin/phpunit
```

The conformance suite (`tests/ConformanceTest.php`) automatically loads all fixtures from `packages/avsb-conformance/fixtures/manifest.json` and asserts that the PHP evaluator produces identical output to the canonical TypeScript implementation.

---

## 12. Migration from LaunchDarkly / OpenFeature

### From LaunchDarkly PHP SDK

```php
// LaunchDarkly
use LaunchDarkly\LDClient;
use LaunchDarkly\LDContext;

$client  = new LDClient("sdk-key");
$context = LDContext::create("alice");
$value   = $client->variation("checkout-v2", $context, false);
```

```php
// A vs B
use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;

$server  = new AvsbServer($datafile);
$context = EvalContext::user('alice', ['plan' => 'pro']);
$value   = $server->getBoolFlag('checkout-v2', false, $context)->getValue();
```

```php
// LaunchDarkly (multi-context)
$multi = LDContext::createMulti(
    LDContext::create("alice"),
    LDContext::builder("acme")->kind("organization")->set("tier", "enterprise")->build()
);
```

```php
// A vs B (multi-context)
$context = EvalContext::multi([
    'user'         => ['key' => 'alice', 'plan' => 'pro'],
    'organization' => ['key' => 'acme', 'tier' => 'enterprise'],
]);
```

Key differences:
- `getBoolFlag` requires an explicit `$defaultValue`. There is no untyped `variation()`.
- Returns a `Flag` object with full metadata. No separate `variationDetail()` call needed.
- `EvalContext` is a readonly value object, not a builder.

### OpenFeature provider

```php
use Avsbhq\Avsb\OpenFeatureProvider;
use OpenFeature\OpenFeatureAPI;

$provider = new OpenFeatureProvider($server);
OpenFeatureAPI::getInstance()->setProvider($provider);

$client = OpenFeatureAPI::getInstance()->getClient();
$value  = $client->getBooleanValue('checkout-v2', false, new EvaluationContext('alice'));
```
