<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\AvsbServerOptions;
use Avsbhq\Avsb\OpenFeatureProvider;
use PHPUnit\Framework\TestCase;

final class OpenFeatureProviderTest extends TestCase
{
    private function server(): AvsbServer
    {
        return AvsbServer::fromDatafile([
            'version'         => 2,
            'projectType'     => 'FEATURE_FLAG',
            'sdkKey'          => 'test',
            'environmentKey'  => 'test',
            'publishedAt'     => '2026-01-01T00:00:00Z',
            'collectEndpoint' => '',
            'flags'           => [
                [
                    'id'                 => 'flag_bool',
                    'key'                => 'bool-flag',
                    'type'               => 'boolean',
                    'enabled'            => true,
                    'defaultVariationId' => 'var_on',
                    'variations'         => [
                        ['id' => 'var_on',  'key' => 'on',  'value' => true],
                        ['id' => 'var_off', 'key' => 'off', 'value' => false],
                    ],
                    'overrides' => [],
                    'rules'     => [],
                ],
            ],
            'audiences' => [],
        ], new AvsbServerOptions(events: false, heartbeat: false));
    }

    public function testGetMetadataReturnsProviderName(): void
    {
        $provider = new OpenFeatureProvider($this->server());
        $meta     = $provider->getMetadata();
        self::assertSame('avsb', $meta->name);
    }

    public function testResolveBooleanValueReturnsDefaultVariationValue(): void
    {
        $provider = new OpenFeatureProvider($this->server());
        $result   = $provider->resolveBooleanValue('bool-flag', false);
        // The flag is enabled and has defaultVariationId = var_on (true).
        self::assertTrue($result['value']);
    }

    public function testResolveBooleanValueFallsBackToDefaultWhenNotFound(): void
    {
        $provider = new OpenFeatureProvider($this->server());
        $result   = $provider->resolveBooleanValue('non-existent', false);
        self::assertFalse($result['value']);
        // OpenFeature's own vocabulary, not the raw A vs B source: a missing
        // flag reads as DEFAULT to every OpenFeature consumer.
        self::assertSame('DEFAULT', $result['reason']);
        self::assertSame('not_found', $result['flagMetadata']['source']);
    }

    public function testResolveReportsProviderNotReadyWhenNoDatafileLoaded(): void
    {
        // No datafile and no key: nothing can be evaluated, and OpenFeature
        // has a name for that state which is not "the flag is missing".
        $server = new AvsbServer('', new AvsbServerOptions(events: false, heartbeat: false));
        $provider = new OpenFeatureProvider($server);

        $result = $provider->resolveBooleanValue('bool-flag', true);

        self::assertTrue($result['value']);
        self::assertSame('ERROR', $result['reason']);
        self::assertSame('PROVIDER_NOT_READY', $result['errorCode']);
    }

    public function testResolveStringValueReturnsDefault(): void
    {
        $provider = new OpenFeatureProvider($this->server());
        $result   = $provider->resolveStringValue('non-existent', 'fallback');
        self::assertSame('fallback', $result['value']);
    }

    public function testResolveIntegerValueFallbackWhenMissing(): void
    {
        $provider = new OpenFeatureProvider($this->server());
        $result   = $provider->resolveIntegerValue('non-existent', 42);
        self::assertSame(42.0, $result['value']);
    }

    public function testResolveObjectValueFallbackWhenMissing(): void
    {
        $provider = new OpenFeatureProvider($this->server());
        $result   = $provider->resolveObjectValue('non-existent', ['default' => true]);
        self::assertSame(['default' => true], $result['value']);
    }
}
