<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Datafile\DatafileFetcher;
use Avsbhq\Avsb\Datafile\FileDatafileCache;
use Avsbhq\Avsb\Datafile\NullDatafileCache;
use Avsbhq\Avsb\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * The fetch path that makes `new AvsbServer($sdkKey)` work. PHP was the only
 * SDK without one, so every customer had to build datafile delivery themselves
 * before they could evaluate a flag.
 */
final class DatafileFetcherTest extends TestCase
{
    private const SDK_KEY = 'sdk_production_fetcherfixture01';

    private string $cacheDirectory = '';

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/avsb-fetch-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->cacheDirectory !== '' && is_dir($this->cacheDirectory)) {
            foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheDirectory);
        }
    }

    /** @return array<string, mixed> */
    private function datafile(): array
    {
        return ['version' => 2, 'flags' => [], 'audiences' => []];
    }

    public function testItReadsTheCanonicalDatafileUrl(): void
    {
        $fetcher = new DatafileFetcher(new FakeTransport(), new NullDatafileCache());

        self::assertSame(
            'https://cdn.avsb.cloud/' . self::SDK_KEY . '/datafile.json',
            $fetcher->url(self::SDK_KEY)
        );
    }

    public function testACustomCdnOriginIsHonouredForProxiesAndLocalDev(): void
    {
        $fetcher = new DatafileFetcher(
            new FakeTransport(),
            new NullDatafileCache(),
            'http://localhost:3000/'
        );

        self::assertSame(
            'http://localhost:3000/' . self::SDK_KEY . '/datafile.json',
            $fetcher->url(self::SDK_KEY)
        );
    }

    public function testASuccessfulFetchIsReturnedAndCached(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, (string) json_encode($this->datafile()))
        );
        $cache = new FileDatafileCache($this->cacheDirectory);
        $fetcher = new DatafileFetcher($transport, $cache);

        $first = $fetcher->fetch(self::SDK_KEY);
        self::assertSame($this->datafile(), $first['datafile']);
        self::assertSame('network', $first['source']);

        // Second call is served from the cache: one CDN read per TTL, not one
        // per request, which is what makes this usable in a per-request
        // runtime.
        $second = $fetcher->fetch(self::SDK_KEY);
        self::assertSame('cache', $second['source']);
        self::assertCount(1, $transport->requests);
    }

    public function testAnExpiredCacheEntryIsRefetched(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, (string) json_encode($this->datafile()))
        );
        $cache = new FileDatafileCache($this->cacheDirectory);
        $cache->put(self::SDK_KEY, $this->datafile());
        // Age the entry past the TTL.
        touch($cache->path(self::SDK_KEY), time() - 3600);

        $result = (new DatafileFetcher($transport, $cache, ttlSeconds: 60))->fetch(self::SDK_KEY);

        self::assertSame('network', $result['source']);
    }

    public function testAStaleCacheEntryIsServedWhenTheFetchFails(): void
    {
        // An older datafile is a better answer than every flag falling back to
        // its default.
        $cache = new FileDatafileCache($this->cacheDirectory);
        $cache->put(self::SDK_KEY, $this->datafile());
        touch($cache->path(self::SDK_KEY), time() - 3600);
        $transport = new FakeTransport(new HttpResponse(500, ''));

        $result = (new DatafileFetcher($transport, $cache, ttlSeconds: 60))->fetch(self::SDK_KEY);

        self::assertSame('cache', $result['source']);
        self::assertSame($this->datafile(), $result['datafile']);
        self::assertNotNull($result['error']);
    }

    public function testA404ExplainsPublishingAndPrintsACurl(): void
    {
        $fetcher = new DatafileFetcher(
            new FakeTransport(new HttpResponse(404, 'not found')),
            new NullDatafileCache()
        );

        $result = $fetcher->fetch(self::SDK_KEY);

        self::assertNull($result['datafile']);
        self::assertSame('error', $result['source']);
        self::assertStringContainsString('HTTP 404', (string) $result['error']);
        self::assertStringContainsString('publish', (string) $result['error']);
        self::assertStringContainsString('curl -i ', (string) $result['error']);
    }

    public function testARequestThatNeverLandedIsExplained(): void
    {
        $fetcher = new DatafileFetcher(
            new FakeTransport(new HttpResponse(0, '', 'curl: could not resolve host')),
            new NullDatafileCache()
        );

        $result = $fetcher->fetch(self::SDK_KEY);

        self::assertStringContainsString('failed before it got a response', (string) $result['error']);
        self::assertStringContainsString('proxy or firewall', (string) $result['error']);
    }

    public function testABodyThatIsNotJsonIsExplainedRatherThanThrown(): void
    {
        $fetcher = new DatafileFetcher(
            new FakeTransport(new HttpResponse(200, '<html>captive portal</html>')),
            new NullDatafileCache()
        );

        $result = $fetcher->fetch(self::SDK_KEY);

        self::assertNull($result['datafile']);
        self::assertStringContainsString('was not JSON', (string) $result['error']);
        self::assertStringContainsString('captive portal', (string) $result['error']);
    }

    public function testTheCachePathDoesNotEscapeItsDirectory(): void
    {
        // The key comes from configuration and is a path segment, so a key
        // with a slash in it must not write outside the cache directory.
        $cache = new FileDatafileCache($this->cacheDirectory);

        $path = $cache->path('../../etc/passwd');

        self::assertStringStartsWith($this->cacheDirectory . '/', $path);
        self::assertStringNotContainsString('..', $path);
    }
}
