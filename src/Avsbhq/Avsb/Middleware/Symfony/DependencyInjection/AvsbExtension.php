<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Middleware\Symfony\DependencyInjection;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\AvsbServerOptions;

/**
 * AvsbExtension: Symfony DI extension that wires AvsbServer into the
 * service container.
 *
 * Registers:
 *   - Avsbhq\Avsb\AvsbServer (service id: avsb.server)
 *   - Avsbhq\Avsb\Middleware\Symfony\EventListener (kernel.event_listener)
 *
 * Requires symfony/dependency-injection (listed as a suggested dependency).
 * This class compiles without the framework present via duck-typing.
 */
final class AvsbExtension
{
    /**
     * Load configuration and register services.
     *
     * @param array  $configs   Merged configuration arrays from avsb.yaml.
     * @param object $container Symfony ContainerBuilder instance.
     */
    public function load(array $configs, object $container): void
    {
        $datafilePath = $configs[0]['datafile_path'] ?? '';
        $sdkKey = $configs[0]['sdk_key'] ?? '';

        // Register the AvsbServer as a container service.
        // In a real Symfony setup this would use a Definition/Reference DSL;
        // we use a factory closure that resolves at runtime.
        if (method_exists($container, 'register')) {
            $def = $container->register(AvsbServer::class, AvsbServer::class);
            $def->setFactory([self::class, 'createServer']);
            $def->setArguments([$sdkKey, $datafilePath]);
            $def->setPublic(true);

            // Register the event listener.
            if (class_exists(\Avsbhq\Avsb\Middleware\Symfony\EventListener::class)) {
                $listenerDef = $container->register(
                    \Avsbhq\Avsb\Middleware\Symfony\EventListener::class,
                    \Avsbhq\Avsb\Middleware\Symfony\EventListener::class
                );
                $listenerDef->addTag('kernel.event_listener', [
                    'event'  => 'kernel.request',
                    'method' => 'onKernelRequest',
                ]);
            }
        }
    }

    public function getAlias(): string
    {
        return 'avsb';
    }

    /**
     * Factory method called by the DI container to construct AvsbServer.
     * Public so Symfony's container can invoke it.
     *
     * A datafile path wins when the file exists: that is the agent or daemon
     * shape, where something else keeps it fresh. Otherwise the SDK fetches
     * from the CDN through its own TTL cache, so an application that sets only
     * AVSB_SDK_KEY works.
     */
    public static function createServer(string $sdkKey, string $datafilePath = ''): AvsbServer
    {
        if ($datafilePath !== '' && is_file($datafilePath)) {
            $raw = file_get_contents($datafilePath);
            if ($raw !== false) {
                /** @var array<string, mixed> $datafile */
                $datafile = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                return new AvsbServer($sdkKey, new AvsbServerOptions(datafile: $datafile));
            }
        }

        return new AvsbServer($sdkKey);
    }
}
