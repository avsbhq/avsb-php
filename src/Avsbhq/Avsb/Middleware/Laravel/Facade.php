<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Middleware\Laravel;

use Avsbhq\Avsb\AvsbServer;

/**
 * Avsb Facade — provides static access to AvsbServer in Laravel applications.
 *
 * Requires laravel/framework (listed as a suggested dependency).
 *
 * Usage:
 *   use Avsbhq\Avsb\Middleware\Laravel\Facade as Avsb;
 *
 *   $flag = Avsb::getBoolFlag('my-flag', false, $context);
 *
 * Register as an alias in config/app.php:
 *   'aliases' => [
 *       'Avsb' => Avsbhq\Avsb\Middleware\Laravel\Facade::class,
 *   ],
 *
 * This class is self-contained and does NOT extend Illuminate\Support\Facade
 * so the file compiles without laravel/framework present.
 * At runtime in a Laravel app, you can replace this with a proper Facade
 * that extends Illuminate\Support\Facade and returns AvsbServer::class from
 * getFacadeAccessor().
 */
final class Facade
{
    private static ?AvsbServer $instance = null;

    /** Set the AvsbServer instance backing the facade. */
    public static function setInstance(AvsbServer $server): void
    {
        self::$instance = $server;
    }

    /**
     * Forward all static calls to the underlying AvsbServer instance.
     *
     * @throws \RuntimeException When no instance has been registered.
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        if (self::$instance === null) {
            throw new \RuntimeException(
                'Avsb Facade: AvsbServer instance not registered. '
                . 'Ensure AvsbServiceProvider is loaded.'
            );
        }
        return self::$instance->$method(...$args);
    }
}
