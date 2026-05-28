<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Middleware\Laravel;

use Avsbhq\Avsb\AvsbServer;

/**
 * AvsbServiceProvider — Laravel service provider for the A vs B SDK.
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
            $path   = $config->get('avsb.datafile_path', '');

            if (!is_string($path) || !file_exists($path)) {
                // Return an empty-datafile server so the container always
                // resolves successfully — flags will return defaults.
                return new AvsbServer([
                    'version'     => 2,
                    'flags'       => [],
                    'audiences'   => [],
                    'projectType' => 'FEATURE_FLAG',
                    'sdkKey'      => '',
                    'environmentKey' => '',
                    'publishedAt' => '',
                    'collectEndpoint' => '',
                ]);
            }

            $raw      = file_get_contents($path);
            $datafile = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return new AvsbServer($datafile);
        });
    }

    public function boot(): void
    {
        // Config publishing — framework-level; no-op when invoked without Laravel.
    }
}
