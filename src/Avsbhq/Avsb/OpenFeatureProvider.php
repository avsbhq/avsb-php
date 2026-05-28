<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

/**
 * OpenFeatureProvider — A vs B provider for the OpenFeature PHP SDK.
 *
 * Implements the structural contract that the OpenFeature PHP SDK expects
 * from a feature-flag provider. Because openfeature/sdk-php is listed as a
 * suggested dependency (not a hard require), the interface is declared
 * inline here rather than extended from the OpenFeature base class.
 *
 * To use with the OpenFeature PHP SDK:
 *   composer require open-feature/sdk
 *   OpenFeatureAPI::getInstance()->setProvider(new OpenFeatureProvider($server));
 *
 * Required OpenFeature interface methods:
 *   - getMetadata(): object{name: string}
 *   - resolveBooleanValue(string $flagKey, bool $defaultValue, ?object $ctx): ResolutionDetails
 *   - resolveStringValue(string $flagKey, string $defaultValue, ?object $ctx): ResolutionDetails
 *   - resolveIntegerValue(string $flagKey, int $defaultValue, ?object $ctx): ResolutionDetails
 *   - resolveFloatValue(string $flagKey, float $defaultValue, ?object $ctx): ResolutionDetails
 *   - resolveObjectValue(string $flagKey, mixed $defaultValue, ?object $ctx): ResolutionDetails
 */
final class OpenFeatureProvider
{
    private const PROVIDER_NAME = 'avsb';

    public function __construct(private readonly AvsbServer $server) {}

    /**
     * Provider metadata required by the OpenFeature SDK.
     *
     * @return object{name: string}
     */
    public function getMetadata(): object
    {
        return new readonly class (self::PROVIDER_NAME) {
            public function __construct(public string $name) {}
        };
    }

    public function resolveBooleanValue(
        string $flagKey,
        bool $defaultValue,
        ?object $ctx = null
    ): array {
        $flag = $this->server->getBoolFlag($flagKey, $defaultValue, $this->adaptContext($ctx));
        return $this->resolution($flag);
    }

    public function resolveStringValue(
        string $flagKey,
        string $defaultValue,
        ?object $ctx = null
    ): array {
        $flag = $this->server->getStringFlag($flagKey, $defaultValue, $this->adaptContext($ctx));
        return $this->resolution($flag);
    }

    public function resolveIntegerValue(
        string $flagKey,
        int $defaultValue,
        ?object $ctx = null
    ): array {
        $flag = $this->server->getNumberFlag($flagKey, (float) $defaultValue, $this->adaptContext($ctx));
        return $this->resolution($flag);
    }

    public function resolveFloatValue(
        string $flagKey,
        float $defaultValue,
        ?object $ctx = null
    ): array {
        $flag = $this->server->getNumberFlag($flagKey, $defaultValue, $this->adaptContext($ctx));
        return $this->resolution($flag);
    }

    public function resolveObjectValue(
        string $flagKey,
        mixed $defaultValue,
        ?object $ctx = null
    ): array {
        $flag = $this->server->getJsonFlag($flagKey, $defaultValue, $this->adaptContext($ctx));
        return $this->resolution($flag);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Convert an OpenFeature EvaluationContext object to an EvalContext.
     * The OpenFeature SDK passes a context object with a getTargetingKey()
     * method and attribute access. We inspect dynamically so we don't
     * import the OpenFeature classes at compile time.
     */
    private function adaptContext(?object $ctx): ?EvalContext
    {
        if ($ctx === null) {
            return null;
        }

        $key = '';
        if (method_exists($ctx, 'getTargetingKey')) {
            $key = (string) ($ctx->getTargetingKey() ?? '');
        }

        $attrs = [];
        if (method_exists($ctx, 'getAttributes')) {
            $raw = $ctx->getAttributes();
            if (is_array($raw)) {
                $attrs = $raw;
            }
        }

        return EvalContext::user($key, $attrs);
    }

    /**
     * Build an OpenFeature ResolutionDetails-compatible associative array
     * from a Flag value object.
     */
    private function resolution(Flag $flag): array
    {
        return [
            'value'       => $flag->value,
            'variant'     => $flag->variationKey,
            'reason'      => $flag->source->value,
            'flagMetadata'=> ['ruleId' => $flag->ruleId, 'reasons' => $flag->reasons],
        ];
    }
}
