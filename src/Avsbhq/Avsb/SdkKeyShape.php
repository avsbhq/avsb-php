<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

/**
 * SDK key shape validation at construction (spec section 1.2).
 *
 * Real keys are minted as sdk_<environmentKey>_<cuid2>, where the environment
 * key matches /^[a-z][a-z0-9_]*$/ (it may itself contain underscores, which is
 * why this validates with one anchored pattern instead of splitting on "_")
 * and the cuid2 is 24 lowercase alphanumerics.
 *
 * A bad key used to be invisible: the CDN 404s, the fetch returns nothing, and
 * every flag silently serves its default. The common bad values are a pasted
 * dashboard URL, a truncated copy, a snippet key, or an API token in the wrong
 * field, and all four are recognisable on sight.
 *
 * This NEVER throws. A key we cannot recognise may still be valid (the mint
 * format can grow), so the SDK logs and proceeds with the value it was given;
 * the datafile fetch is the real arbiter. Wording is kept in step with
 * packages/avsb-browser/src/sdkKeyShape.ts on purpose: one message, every SDK.
 */
final class SdkKeyShape
{
    /** Matches sdk_<envKey>_<id>; the trailing id segment carries no underscore. */
    private const PATTERN = '/^sdk_[a-z][a-z0-9_]*_[a-z0-9]{8,}$/';

    /** A real-shaped example, so the message is copy-comparable. */
    public const EXAMPLE_KEY = 'sdk_production_ttqm0eaj4vth1krcb2xn';

    public static function isValid(?string $sdkKey): bool
    {
        return $sdkKey !== null && preg_match(self::PATTERN, $sdkKey) === 1;
    }

    /** The message to log for a key that does not look right, or null. */
    public static function messageFor(?string $sdkKey): ?string
    {
        if (self::isValid($sdkKey)) {
            return null;
        }

        return 'AvsB SDK key "' . self::describe($sdkKey) . '" does not look like an SDK key: '
            . self::diagnose($sdkKey) . '. Keys look like ' . self::EXAMPLE_KEY
            . ' (sdk_<environment>_<id>). Copy the key for the environment you want from '
            . 'app.avsb.cloud, then Environments in the project sidebar. The SDK will keep going '
            . 'with the value you passed, so if the datafile request 404s next, this is why.';
    }

    /**
     * Redact all but the recognisable head of a key so a message can be pasted
     * into a bug report. The environment name is the useful half ("you used
     * the development key"); the cuid2 tail is not.
     */
    public static function describe(?string $sdkKey): string
    {
        $key = $sdkKey ?? '';
        if ($key === '') {
            return '(empty)';
        }
        if (strlen($key) <= 12) {
            return $key;
        }

        return substr($key, 0, 12) . '...' . substr($key, -4);
    }

    private static function diagnose(?string $sdkKey): string
    {
        $key = $sdkKey ?? '';
        $trimmed = trim($key);

        if ($trimmed === '') {
            return 'no SDK key was passed at all';
        }
        if ($trimmed !== $key) {
            return 'it has leading or trailing whitespace, which usually means a copy-paste '
                . 'picked up a line break';
        }
        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return 'it looks like a URL, not a key. Copy the key itself, not the address of '
                . 'the page it is on';
        }
        if (str_starts_with($trimmed, 'avsb_pat_') || str_starts_with($trimmed, 'avsb_svc_')) {
            return 'it looks like an API token. API tokens talk to the management API; SDKs '
                . 'need the environment SDK key';
        }
        if (str_starts_with($trimmed, 'sdk-')
            || str_starts_with($trimmed, 'pub_')
            || str_starts_with($trimmed, 'srv_')
        ) {
            return 'that key prefix has never existed in A vs B. There is one key format '
                . 'for SDKs';
        }
        if (!str_starts_with($trimmed, 'sdk_')) {
            return 'it does not start with "sdk_"';
        }
        if (count(explode('_', $trimmed)) < 3) {
            return 'it is missing the environment segment, so it may have been truncated';
        }

        return 'the id after the environment name is too short or has characters a generated '
            . 'id never contains, so it may have been truncated or edited';
    }
}
