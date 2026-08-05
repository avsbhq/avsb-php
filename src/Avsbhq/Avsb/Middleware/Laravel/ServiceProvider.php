<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Middleware\Laravel;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\AvsbServerOptions;

/**
 * AvsbServiceProvider: Laravel service provider for the A vs B SDK.
 *
 * Registers AvsbServer as a singleton in the application container and
 * publishes the 'avsb' config stub.
 *
 * Requires laravel/framework (listed as a suggested dependency).
 *
 * Usage in config/app.php:
 *   'providers' => [
 *       Avsbhq\Avsb\Middleware\Laravel\ServiceProvider::class,
 *   ],
 *
 * Config stub (config/avsb.php):
 *   return [
 *       'datafile_path' => env('AVSB_DATAFILE_PATH', storage_path('avsb/datafile.json')),
 *       'sdk_key'       => env('AVSB_SDK_KEY', ''),
 *   ];
 *
 * This class uses duck-typing so the file compiles without laravel/framework
 * in the dependency graph. Framework classes are only resolved at runtime
 * when the framework is actually present.
 */
final class ServiceProvider
{
    /**
     * The underlying Illuminate ServiceProvider instance is injected by
     * Laravel's container. We extend dynamically at runtime when the
     * framework is loaded.
     *
     * @param object $app  The Illuminate Application instance.
     */
    public function __construct(private readonly object $app) {}

    public function register(): void
    {
        $this->app->singleton(AvsbServer::class, function (object $app): AvsbServer {
            /** @phpstan-ignore-next-line */
            $config = $app->make('config');
            $sdkKey = $config->get('avsb.sdk_key', '');
            $path   = $config->get('avsb.datafile_path', '');

            // A datafile path wins when the file exists: that is the agent or
            // daemon shape, where something else keeps it fresh. Otherwise the
            // SDK fetches from the CDN through its own TTL cache, so an
            // application that sets only AVSB_SDK_KEY works.
            if (is_string($path) && $path !== '' && is_file($path)) {
                $raw = file_get_contents($path);
                if ($raw !== false) {
                    /** @var array<string, mixed> $datafile */
                    $datafile = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                    return new AvsbServer(
                        is_string($sdkKey) ? $sdkKey : '',
                        new AvsbServerOptions(datafile: $datafile)
                    );
                }
            }

            // Constructing with an unusable key still resolves: the SDK logs
            // why and every flag returns its default, rather than the
            // container throwing during boot.
            return new AvsbServer(is_string($sdkKey) ? $sdkKey : '');
        });
    }

    public function boot(): void
    {
        // Config publishing — framework-level; no-op when invoked without Laravel.
    }
}
