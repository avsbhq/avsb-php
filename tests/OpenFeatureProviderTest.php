<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\OpenFeatureProvider;
use PHPUnit\Framework\TestCase;

final class OpenFeatureProviderTest extends TestCase
{
    private function server(): AvsbServer
    {
        return new AvsbServer([
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
        ]);
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
        self::assertSame('not_found', $result['reason']);
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
