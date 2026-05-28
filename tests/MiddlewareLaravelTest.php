<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;
use Avsbhq\Avsb\Middleware\Laravel\Facade;
use Avsbhq\Avsb\Middleware\Laravel\Middleware;
use PHPUnit\Framework\TestCase;

/**
 * MiddlewareLaravelTest — minimal tests for the Laravel integration layer.
 *
 * These tests do NOT require laravel/framework to be installed. They
 * exercise the AvsbServer singleton registration and the Facade static
 * proxy using lightweight fakes.
 */
final class MiddlewareLaravelTest extends TestCase
{
    private function emptyServer(): AvsbServer
    {
        return new AvsbServer([
            'version'         => 2,
            'projectType'     => 'FEATURE_FLAG',
            'sdkKey'          => 'test',
            'environmentKey'  => 'test',
            'publishedAt'     => '',
            'collectEndpoint' => '',
            'flags'           => [],
            'audiences'       => [],
        ]);
    }

    protected function tearDown(): void
    {
        // Reset the facade singleton between tests.
        Facade::setInstance($this->emptyServer());
    }

    // ------------------------------------------------------------------

    public function testFacadeProxiesGetBoolFlagToServer(): void
    {
        Facade::setInstance($this->emptyServer());
        $flag = Facade::getBoolFlag('non-existent', false);
        self::assertFalse($flag->value);
        self::assertSame('not_found', $flag->source->value);
    }

    public function testFacadeThrowsWhenNoInstanceRegistered(): void
    {
        // Reset to null via reflection.
        $ref  = new \ReflectionClass(Facade::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->expectException(\RuntimeException::class);
        Facade::getBoolFlag('flag', false);
    }

    public function testMiddlewareBindsContextToFakeRequest(): void
    {
        $server = $this->emptyServer();

        // Build a minimal fake request that satisfies the middleware's duck-typed access.
        $fakeRequest = new class {
            public object $attributes;

            public function __construct()
            {
                $this->attributes = new class {
                    private array $bag = [];
                    public function set(string $k, mixed $v): void { $this->bag[$k] = $v; }
                    public function get(string $k): mixed { return $this->bag[$k] ?? null; }
                };
            }

            public function user(): ?object { return null; }
            public function all(): array { return []; }
        };

        $middleware = new Middleware($server);
        $called     = false;
        $next       = function (object $req) use (&$called): object {
            $called = true;
            return $req;
        };

        $middleware->handle($fakeRequest, $next);

        self::assertTrue($called, 'next() must be called by the middleware');
        $ctx = $fakeRequest->attributes->get('avsb_context');
        self::assertInstanceOf(EvalContext::class, $ctx);
    }

    public function testServiceProviderRegistersServerInContainer(): void
    {
        // Fake Laravel-style app container.
        $bindings = [];
        $fakeApp  = new class ($bindings) {
            private array $singletons = [];
            public function __construct(private array &$bindings) {}

            public function singleton(string $abstract, callable $factory): void
            {
                $this->singletons[$abstract] = $factory;
                $this->bindings[$abstract] = $factory;
            }

            public function make(string $abstract): mixed
            {
                if (isset($this->singletons[$abstract])) {
                    return ($this->singletons[$abstract])($this);
                }
                return null;
            }

            public function config(): object
            {
                return new class {
                    public function get(string $key, mixed $default = null): mixed { return $default; }
                };
            }
        };

        $provider = new \Avsbhq\Avsb\Middleware\Laravel\ServiceProvider($fakeApp);
        $provider->register();

        // The singleton must be callable and return an AvsbServer.
        self::assertArrayHasKey(AvsbServer::class, $bindings);
        $server = $fakeApp->make(AvsbServer::class);
        self::assertInstanceOf(AvsbServer::class, $server);
    }
}
