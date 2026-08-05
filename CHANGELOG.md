# Changelog

All notable changes to `avsbhq/avsb` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release with a working data plane. Everything below is pre-launch, so
these are corrections rather than deprecations: there is no earlier published
version to migrate from.

### Fixed

- **PSR-4 autoloading.** Five types lived in files whose names did not match
  them (`Core/Types.php` held `RuleType` and `EvaluationSource`,
  `Sticky/MemoryService.php` held the sticky interface and its in-memory
  implementation, `Sticky/RedisService.php` held `RedisStickyBucketService`,
  `DecisionLog.php` held `DecisionLogEntry`), so composer could not autoload
  any of them. `Flag` and `DecisionLog` both import `EvaluationSource`, which
  means the package fatally errored on its first use.
- **Traffic-allocation and cumulative math.** Both were truncated to integers,
  which moved one bucket in ten thousand relative to the reference engine and
  the other SDKs. Both now compare the raw floating-point product, and reason
  strings print numbers the way the reference does rather than the way PHP's
  `precision` ini setting decides.
- **Holdout thresholds** were floored, putting holdouts one bucket out of step
  with the allocation gate in the same datafile.
- **The `override` source** reached callers. The public spelling is
  `datafileOverride`, and the internal name is now mapped at the boundary.
- **`isEnabled()`** returned true for any flag with a variation key and a
  PHP-truthy value. It now means "a real decision produced this value, and the
  value is truthy by JavaScript's rules", identical in every SDK. The string
  `"0"` and an empty array are truthy; `0`, `""`, and `null` are not.
- **`exists()`** answered true for an uninitialised client.
- **Kinded audience conditions** only enforced the context kind for
  multi-contexts, so a single user context silently satisfied an
  organization-scoped `does_not_exist` condition.
- **Numeric audience operators** used PHP's float cast, so `greater_than -1`
  matched a text attribute (`(float) "abc"` is `0.0`). They now follow
  JavaScript's `Number()`, where a non-numeric value never compares true.
- **Regex conditions** hardcoded `/` as the pattern delimiter, so any pattern
  containing a slash, which for URL matching is most of them, silently failed
  to match.

### Added

- **A datafile fetcher.** `new AvsbServer($sdkKey)` now works: the SDK reads
  the canonical CDN URL through a TTL cache (the system temp directory by
  default), with timeouts, a stale-cache fallback, and errors that print the
  URL tried and a `curl` that reproduces them. PHP was the only SDK where the
  customer had to build datafile delivery before evaluating anything.
- **`Psr18Transport`**, so a host application's existing PSR-18 client,
  middleware, and proxy configuration cover A vs B requests too. It uses duck
  typing, so no PSR HTTP package becomes a dependency of this one.
  `CurlTransport` is the default and needs nothing.
- **Event tracking.** `track`, `trackPurchase`, and automatic exposure
  recording on `ab_test`, `bandit`, and holdout decisions. `EventTracker`
  batches with a bounded queue, stamps a stable `eventId` per event, retries
  with jittered backoff, honours `Retry-After`, and flushes from a shutdown
  function so a per-request runtime does not lose the batch. Before this,
  `track` logged the payload and dropped it.
- **`not_ready` evaluation source.** Evaluating with no datafile returns your
  default with `source: not_ready` instead of masquerading as `not_found`.
- **Bandit dispatch.** `bandit`-typed rules run through the bandit engine, with
  a cold-start policy when no model store is supplied, and every path that
  cannot dispatch appends a reason naming why.
- **Commerce and alias audience operators**: `viewed_any`, `not_viewed_any`,
  `has_purchased`, `has_not_purchased`, `lifetime_spend_gt`, `order_count_gt`,
  `last_purchase_older_than`, `between`, plus `is`, `is_not`,
  `does_not_contain`, and `does_not_exist`.
- **Sticky bucketing, wired.** `StickyPipeline` reads before the evaluator and
  writes only on an `ab_test` match, storing the variation id. The sticky
  classes existed with no pipeline to use them.
- **Heartbeat**, throttled to once per interval per machine through an on-disk
  marker, so a per-request runtime reports liveness without a request per
  request.
- **`AvsbServerOptions`**, one typed object with a working default for every
  field, and `AvsbServer::fromDatafile()` for the agent or daemon shape.
- **`PurchaseOrder`**, a typed order rather than an associative array.
- **`SdkKeyShape`**, which names a pasted URL, a truncated key, or an API token
  in the SDK key field at construction.
- **Once-per-process empty-context warning**, naming the call to add.
- **`StderrLogger`**, the development default, so a wrong key or a dropped
  batch is visible instead of silent. Production stays silent unless you pass a
  logger.
- **`Version`**, one place for the published version and package identifier.
- **`CHANGELOG.md`.**

### Changed

- `AvsbServer::__construct` takes an SDK key and an optional
  `AvsbServerOptions`, not a decoded datafile array. Pass a datafile through
  `AvsbServerOptions(datafile: ...)` or `AvsbServer::fromDatafile()`.
- `track()` is `track(string $eventName, EvalContext $context, ?float $revenue,
  ?float $value)`. The context is required, because an event with no visitor
  cannot be attributed, and `revenue` and `value` map one to one onto the
  ingestion contract rather than one guessing at the other.
- Laravel and Symfony glue reads `avsb.sdk_key` and falls back to the CDN
  fetcher when no datafile path is configured.

### Removed

- `identify()`, which logged its argument and dropped it. There is no identify
  endpoint in the data plane.
- `Core/BigSegmentStore.php`, an orphan with no call site in any SDK. Big
  segments need platform design before any port implements them.
- The legacy `EvaluationSource::Override` case, a spelling that must never
  reach a caller.
