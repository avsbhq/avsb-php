<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Version;
use PHPUnit\Framework\TestCase;

/**
 * Composer reads the version from the git tag, so the constant the heartbeat
 * and User-Agent report is kept by hand. These tests tie it to the two files a
 * release edits, so a release that forgets one fails here instead of reporting
 * the wrong version to the dashboard (the drift the JavaScript SDKs shipped,
 * N0-9).
 */
final class VersionTest extends TestCase
{
    public function testTheConstantIsTheNewestChangelogVersion(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        self::assertSame(1, preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $match));
        self::assertSame($match[1], Version::VERSION);
    }

    public function testTheUserAgentCarriesTheSameVersion(): void
    {
        self::assertSame('avsb-php/' . Version::VERSION, Version::userAgent());
    }

    public function testTheSdkTypeIsTheComposerPackageName(): void
    {
        /** @var array{name: string} $composer */
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame($composer['name'], Version::SDK_TYPE);
    }
}
