<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Middleware\Symfony;

use Avsbhq\Avsb\Middleware\Symfony\DependencyInjection\AvsbExtension;

/**
 * AvsbBundle: Symfony bundle for the A vs B SDK.
 *
 * Requires symfony/framework-bundle (listed as a suggested dependency).
 *
 * Register in config/bundles.php:
 *   return [
 *       Avsbhq\Avsb\Middleware\Symfony\Bundle::class => ['all' => true],
 *   ];
 *
 * Configuration (config/packages/avsb.yaml):
 *   avsb:
 *     datafile_path: '%kernel.project_dir%/var/avsb/datafile.json'
 *     sdk_key: '%env(AVSB_SDK_KEY)%'
 *
 * This class uses duck-typing so it compiles without symfony/framework-bundle.
 * The real implementation at runtime will extend AbstractBundle.
 */
final class Bundle
{
    public function getContainerExtension(): AvsbExtension
    {
        return new AvsbExtension();
    }

    public function build(object $container): void
    {
        // No additional compiler passes required.
    }

    public function getPath(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getName(): string
    {
        return 'AvsbBundle';
    }
}
