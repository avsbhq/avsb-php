<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

/**
 * The published package identity, in one place.
 *
 * Composer does not expose a package version to its own code, and a literal
 * that lives in three files drifts. Everything that reports a version (the
 * heartbeat body, the User-Agent) reads it from here.
 */
final class Version
{
    /** Keep in step with the tag when releasing. */
    public const VERSION = '1.0.0';

    /**
     * The package identifier, exactly as published. The dashboard groups
     * environments by this string, so it must match the composer package name.
     */
    public const SDK_TYPE = 'avsbhq/avsb';

    public static function userAgent(): string
    {
        return 'avsb-php/' . self::VERSION;
    }
}
