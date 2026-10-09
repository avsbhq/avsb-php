# avsbhq/avsb: PHP SDK

Server-side PHP SDK for [A vs B](https://app.avsb.cloud), the feature-flag and
experimentation platform.

PHP 8.2 or later. One runtime dependency (`psr/log`). PSR-4 autoloading, a
PSR-3 logger, an optional PSR-18 transport, plus Laravel and Symfony glue.

How it works: your application downloads one JSON datafile from a global CDN,
caches it on disk, evaluates flags locally in microseconds, and posts exposures
and conversions after the response. There is no proxy to run and no request to
A vs B on the hot path.

---

## 1. Install

```bash
composer require avsbhq/avsb
```

Optional integrations:

| Package | Purpose |
|---|---|
| `laravel/framework` | Laravel ServiceProvider, Facade, route middleware |
| `symfony/framework-bundle` | Symfony Bundle, DI extension, kernel event listener |
| `predis/predis` | Redis-backed sticky bucket store |
| any PSR-18 client | reuse your HTTP client through `Psr18Transport` |

`ext-curl` is used when present; the SDK falls back to PHP streams when it is
not, so nothing is required.

---

## 2. Quickstart

```php
<?php

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;

$server = new AvsbServer($_ENV['AVSB_SDK_KEY']);

$flag = $server->getBoolFlag('checkout_v2', false, EvalContext::user('alice', [
    'plan' => 'pro',
]));

if ($flag->value) {
    // serve the new checkout
}

$server->track('checkout_completed', EvalContext::user('alice'), revenue: 49.99);
```

That is the whole setup. The constructor loads the datafile through a TTL cache
in your system temp directory, so a warm process pays a file read and a cold one
pays a single CDN round trip with a timeout.

Build the server once per request, in your container rather than per call: each
instance holds the datafile and batches that request's events.

### Recommended production shape: the agent

PHP processes are short-lived, which means every worker eventually refetches the
datafile and every request flushes its own small batch. That is fine at modest
traffic and it is what the quickstart above does.

At higher traffic, run the A vs B agent as a sidecar: one process holds the
datafile, keeps it fresh over SSE, and buffers events for the whole host. Point
PHP at the file it writes and construct from that:

```php
$datafile = json_decode(file_get_contents('/var/run/avsb/datafile.json'), true);
$server = AvsbServer::fromDatafile($datafile);
```

`fromDatafile` never fetches: the SDK key comes from the datafile, and something
else owns freshness.

---

## 3. SDK keys

An SDK key looks like `sdk_production_ttqm0eaj4vth1krcb2xn`: the prefix `sdk_`,
your environment name, then a generated id. Copy the key for the environment you
want from the A vs B dashboard, under Environments in the project sidebar.

```bash
export AVSB_SDK_KEY=sdk_production_ttqm0eaj4vth1krcb2xn
```

The SDK checks the shape at construction. A pasted dashboard URL, a truncated
copy, or an API token in this field logs an error naming the mistake, and the
SDK carries on, so the problem is visible before the datafile request 404s.

SDK keys are public identifiers: they name an environment and grant read access
to that environment's datafile, which is served from a CDN with no
authentication. That is by design and safe in a browser. It is not an API
credential: personal access tokens (`avsb_pat_...`) and service tokens
(`avsb_svc_...`) are secrets and must never appear in client code.

---

## 4. Configuration

Everything optional lives in one typed object, and every field has a working
default:

```php
use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\AvsbServerOptions;
use Avsbhq\Avsb\Datafile\FileDatafileCache;

$server = new AvsbServer($_ENV['AVSB_SDK_KEY'], new AvsbServerOptions(
    logger: $psr3Logger,
    cache: new FileDatafileCache('/dev/shm/avsb'),
    datafileTtlSeconds: 30,
    fetchTimeoutSeconds: 2.0,
    stickyBucketService: $redisStickyStore,
));
```

```php
// AvsbServerOptions::__construct(
//     ?array $datafile = null,
//     ?LoggerInterface $logger = null,
//     ?DecisionLog $decisionLog = null,
//     ?HttpTransport $transport = null,
//     ?DatafileCache $cache = null,
//     string $cdnHost = Endpoints::DEFAULT_CDN_HOST,
//     int $datafileTtlSeconds = 60,
//     float $fetchTimeoutSeconds = 5.0,
//     bool $events = true,
//     bool $flushEventsOnShutdown = true,
//     ?StickyBucketService $stickyBucketService = null,
//     ?BanditModelStore $banditModelStore = null,
//     mixed $onStickyOrphan = null,
//     bool $heartbeat = true,
// )
```

`datafileTtlSeconds` is the floor on how quickly a flag change reaches this
process: a shorter TTL means fresher flags and more CDN reads.

### Reusing your HTTP client

```php
use Avsbhq\Avsb\Http\Psr18Transport;

$options = new AvsbServerOptions(
    transport: new Psr18Transport($psr18Client, $psr17RequestFactory, $psr17StreamFactory),
);
```

Your middleware, proxy settings, and instrumentation then apply to A vs B
requests too. `Psr18Transport` accepts those objects structurally, so no PSR HTTP
package becomes a dependency of this one. Timeouts come from the client you
pass, because PSR-18 has no per-request timeout.

---

## 5. Context and identity

```php
use Avsbhq\Avsb\EvalContext;

$context = EvalContext::user('alice', [
    'plan' => 'pro',            // targeting attributes, any JSON value
    'betaTester' => true,
]);
```

The first argument is the bucketing identity. Evaluating with an empty key
buckets every caller identically and makes experiment data meaningless, so the
SDK warns once per process and names the call to add.

```php
// Any single kind
$org = EvalContext::user('acme-corp', ['tier' => 'enterprise'], 'organization');

// Several kinds at once
$context = EvalContext::multi([
    'user'         => ['key' => 'alice', 'plan' => 'pro'],
    'organization' => ['key' => 'acme', 'tier' => 'enterprise'],
]);

// Or wrap a decoded array already in the wire shape
$context = EvalContext::fromArray($decoded);
```

When a rule sets `hashAttribute` to `organization.key`, everyone in the same
organization gets the same variation regardless of their own user key. Audience
conditions scoped to a kind read only that sub-context, and a condition naming a
kind the context does not carry never matches.

---

## 6. Reading flags

```php
// AvsbServer::getBoolFlag(string $key, bool $defaultValue, ?EvalContext $context = null): Flag
$showBanner = $server->getBoolFlag('promo_banner', false, $context);

// AvsbServer::getStringFlag(string $key, string $defaultValue, ?EvalContext $context = null): Flag
$theme = $server->getStringFlag('ui_theme', 'light', $context);

// AvsbServer::getNumberFlag(string $key, float $defaultValue, ?EvalContext $context = null): Flag
$maxRetries = $server->getNumberFlag('max_retries', 3.0, $context);

// AvsbServer::getJsonFlag(string $key, mixed $defaultValue, ?EvalContext $context = null): Flag
$config = $server->getJsonFlag('api_config', [], $context);
```

`$defaultValue` is required on every call. It is what you get when the flag is
missing, the SDK never loaded a datafile, or the flag is off, and it comes back
unchanged with its type intact.

The getters do not coerce: `$flag->value` is whatever `json_decode` produced for
that variation. If a flag's variations hold strings and you call `getBoolFlag`,
the value is that string, not `true`. Read `$flag->value` for the value and
`$flag->isEnabled()` for a gate.

### `Flag`

All properties are readonly and public, so there are no getters to remember:

```php
$flag = $server->getBoolFlag('checkout_v2', false, $context);

$flag->value;           // mixed             the evaluated value
$flag->variationKey;    // string|null       null for default, not_found, not_ready
$flag->source;          // EvaluationSource  why this value (enum, see below)
$flag->ruleId;          // string|null       the rule or holdout that decided
$flag->ruleType;        // RuleType|null     AbTest, TargetedDelivery, Holdout, Bandit
$flag->reasons;         // list<string>      the decision trail, in order
$flag->evaluatedAt;     // int               milliseconds since the Unix epoch
$flag->durationMicros;  // int               microseconds spent evaluating
$flag->isEnabled();     // bool
$flag->exists();        // bool
```

`EvaluationSource` is a backed enum, so `$flag->source->value` is the wire
string and `match ($flag->source) { ... }` is exhaustive:

| Case | Value | Meaning |
|---|---|---|
| `Rule` | `rule` | A targeting rule or A/B test decided this. |
| `Holdout` | `holdout` | The context is held out of this flag's experiments. |
| `Bandit` | `bandit` | A bandit rule chose this arm. |
| `Sticky` | `sticky` | A stored assignment was reused. |
| `DatafileOverride` | `datafileOverride` | An override saved in the dashboard. |
| `RuntimeOverride` | `runtimeOverride` | An override set in code at runtime. |
| `Default` | `default` | No rule matched; the flag's default variation was served. |
| `Disabled` | `disabled` | The flag is switched off. |
| `NotFound` | `not_found` | The datafile was read and this key is not in it. |
| `NotReady` | `not_ready` | No datafile loaded, so your default came back. |

`isEnabled()` is true when the source is one of `Rule`, `Holdout`, `Bandit`,
`Sticky`, `DatafileOverride`, or `RuntimeOverride` **and** the value is truthy.
Truthy means what it means in JavaScript, because the reference engine is
TypeScript and every SDK must answer identically: `false`, `0`, `""`, and `null`
are falsy, and everything else is truthy, including the string `"0"` and an
empty array. A flag serving its default variation is never enabled, however
truthy that default is.

`exists()` is false for `NotFound` and `NotReady`, and true for everything else.
A disabled flag exists: the customer turned it off.

### Was the datafile loaded?

```php
$server->isReady();      // bool
$server->initResult();   // ['success' => bool, 'source' => string, 'error' => ?string]
                         // source: bootstrap, cache, network, or error
```

Every failure is logged with the URL tried and a `curl` that reproduces it.

### Decision log

```php
use Avsbhq\Avsb\DecisionLog;

$log = new DecisionLog();
$server = new AvsbServer($sdkKey, new AvsbServerOptions(decisionLog: $log));

$server->getBoolFlag('checkout_v2', false, $context);

foreach ($log->getEntries() as $entry) {
    echo $entry->flagKey . ': ' . json_encode($entry->value) . PHP_EOL;
}
```

Each entry carries the same fields as `Flag` plus `contextSummary` (kind and key
only; attributes are never logged) and honours `_meta.privateAttributes`
redaction.

---

## 7. Tracking events

```php
// AvsbServer::track(string $eventName, EvalContext $context, ?float $revenue = null, ?float $value = null): void
$server->track('signup_completed', $context);
$server->track('checkout_completed', $context, revenue: 49.99);
$server->track('items_per_order', $context, value: 3.0);
```

`$eventName` is the metric key from your dashboard. An unknown key is stored, so
a typo shows up as an empty metric rather than an error. `$revenue` is money in
decimal major units, for revenue metrics; `$value` is the number for
average-value metrics. Both are optional.

```php
use Avsbhq\Avsb\PurchaseOrder;

// AvsbServer::trackPurchase(EvalContext $context, PurchaseOrder $order): bool
$server->trackPurchase($context, new PurchaseOrder(
    orderId: 'ORD-1001',
    total: 99.95,
    currency: 'USD',
    items: [['sku' => 'SKU-1', 'price' => 99.95, 'quantity' => 1]],
));
```

Exposures are automatic. Every `ab_test`, bandit, and holdout decision records
one, so you do not call anything to make experiment results appear.

Queued events are sent once, after the response, from a shutdown function.
Orders send immediately, because a purchase is the event you least want buffered
in a process that might be killed. Call `flushEvents()` to send early, or pass
`events: false` to switch all sending off.

---

## 8. Sticky bucketing

A sticky store keeps a user on the variation they first saw, even if traffic
allocations change later.

```php
use Avsbhq\Avsb\Sticky\RedisStickyBucketService;

$server = new AvsbServer($sdkKey, new AvsbServerOptions(
    stickyBucketService: new RedisStickyBucketService($redis),
));
```

Shipped stores: `InMemoryStickyBucketService` (one request, for tests and CLI
scripts) and `RedisStickyBucketService` (predis or ext-redis). Any class
implementing `StickyBucketService` works.

The store is read before the evaluator and written only after an `ab_test` rule
match, storing the variation **id**. A targeted delivery is a rollout, not an
assignment, so it is not stored. If a stored variation has since been deleted the
lookup is a miss; if its rule is gone the stored value is still served, with a
warning and no exposure, because the experiment it would be attributed to no
longer exists. Pass `onStickyOrphan` to observe that case.

---

## 9. Bandits

`bandit`-typed rules dispatch through the bandit engine. With no model store
supplied the SDK runs the algorithm's documented cold-start policy, which is
deterministic for a given datafile and context, so a configured bandit never
silently behaves as "no rules matched". Every bandit rule that cannot dispatch
appends a reason naming why, visible in `$flag->reasons`.

---

## 10. Logging and failure

`AvsbServer` accepts any PSR-3 logger:

```php
$server = new AvsbServer($sdkKey, new AvsbServerOptions(logger: $monolog));
```

With no logger it writes warnings and errors to stderr in development and stays
silent in production, detected from `APP_ENV` or `SYMFONY_ENV`. Pass a logger
explicitly in production to keep the messages.

Evaluation never throws. A missing flag, an unexpected type, or an SDK with no
datafile returns your `$defaultValue` with a `source` that says which of those it
was.

---

## 11. Laravel

Register the service provider in `config/app.php`:

```php
'providers' => [
    Avsbhq\Avsb\Middleware\Laravel\ServiceProvider::class,
],
```

`config/avsb.php`:

```php
return [
    'sdk_key'       => env('AVSB_SDK_KEY', ''),
    // Optional: point at a datafile another process keeps fresh (the agent
    // shape). When the file exists it wins and the SDK never fetches.
    'datafile_path' => env('AVSB_DATAFILE_PATH', ''),
];
```

`AvsbServer` is then resolvable from the container:

```php
public function __construct(private readonly AvsbServer $avsb) {}
```

The route middleware binds an `EvalContext` built from the authenticated user
(or the session id) onto the request:

```php
// app/Http/Kernel.php
protected $routeMiddleware = [
    'avsb' => \Avsbhq\Avsb\Middleware\Laravel\Middleware::class,
];
```

```php
$context = $request->attributes->get('avsb_context');
```

---

## 12. Symfony

```php
// config/bundles.php
return [
    Avsbhq\Avsb\Middleware\Symfony\Bundle::class => ['all' => true],
];
```

```yaml
# config/packages/avsb.yaml
avsb:
  sdk_key: '%env(AVSB_SDK_KEY)%'
  # Optional, the agent shape:
  datafile_path: '%kernel.project_dir%/var/avsb/datafile.json'
```

```php
class CheckoutController extends AbstractController
{
    public function __construct(private readonly AvsbServer $avsb) {}

    public function index(Request $request): Response
    {
        $context = $request->attributes->get('avsb_context')
            ?? EvalContext::user((string) $this->getUser()?->getId());
        $flag = $this->avsb->getBoolFlag('checkout_v2', false, $context);
        // ...
    }
}
```

---

## 13. Testing

Build a datafile and construct from it: no network, no cache, no events.

```php
use Avsbhq\Avsb\EvalContext;
use Avsbhq\Avsb\TestUtils\InMemoryDatafileLoader;
use PHPUnit\Framework\TestCase;

final class CheckoutFlagTest extends TestCase
{
    public function testTheFlagIsOnForEveryone(): void
    {
        $server = InMemoryDatafileLoader::fromJson(<<<'JSON'
        {
          "version": 2,
          "sdkKey": "sdk_test_conformancefixture01",
          "flags": [{
            "id": "flag_checkout",
            "key": "checkout_v2",
            "type": "boolean",
            "enabled": true,
            "defaultVariationId": "var_off",
            "variations": [
              {"id": "var_off", "key": "off", "value": false},
              {"id": "var_on",  "key": "on",  "value": true}
            ],
            "overrides": [],
            "rules": [{
              "id": "rule_all",
              "type": "targeted_delivery",
              "enabled": true,
              "audienceIds": [],
              "hashAttribute": "user.key",
              "trafficAllocation": null,
              "variations": [{"variationId": "var_on", "percentage": 1}]
            }]
          }],
          "audiences": []
        }
        JSON);

        $flag = $server->getBoolFlag('checkout_v2', false, EvalContext::user('alice'));

        self::assertTrue($flag->value);
        self::assertTrue($flag->isEnabled());
        self::assertSame('rule', $flag->source->value);
    }
}
```

`InMemoryDatafileLoader::fromArray`, `fromJson`, and `fromFile` all return a
server with events and heartbeats switched off, so a suite never touches the
network by accident.

Run the suite:

```bash
composer install
./vendor/bin/phpunit
```

`tests/ConformanceTest.php` runs the shared cross-language corpus from
`packages/avsb-conformance` (or the copy vendored into `tests/vendor`),
asserting that this evaluator produces identical values, sources, and reason
strings to the reference TypeScript engine. Missing fixtures fail the suite
rather than being skipped.

---

## 14. OpenFeature

```php
use Avsbhq\Avsb\OpenFeatureProvider;

$provider = new OpenFeatureProvider($server);
```

The provider maps `targetingKey` onto the context key; `rule`, `holdout`, and
`bandit` onto `TARGETING_MATCH`; `sticky` onto `CACHED`; the two override
sources onto `STATIC`; and a server with no datafile onto
`PROVIDER_NOT_READY`.

---

## 15. Migrating from the LaunchDarkly PHP SDK

```php
// LaunchDarkly
$client = new LDClient('sdk-key');
$value  = $client->variation('checkout_v2', LDContext::create('alice'), false);
```

```php
// A vs B
$server = new AvsbServer($_ENV['AVSB_SDK_KEY']);
$value  = $server->getBoolFlag('checkout_v2', false, EvalContext::user('alice'))->value;
```

Differences that matter: getters are typed and require an explicit default, the
return value is a `Flag` carrying the full decision trail rather than a bare
value, and multi-context is a static factory instead of a builder.

LaunchDarkly pushes PHP to daemon mode because its SDK cannot hold a streaming
connection. A vs B has the same constraint and the same answer, but the default
path still works: the datafile is a cached CDN read, and the agent is the
optimisation rather than the prerequisite.
