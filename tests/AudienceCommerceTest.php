<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Audience;
use PHPUnit\Framework\TestCase;

/**
 * Commerce and alias operators, evaluated through Audience so the kinded
 * attribute lookup is exercised too. These are the operators an e-commerce
 * audience built in the dashboard actually writes, and every one of them used
 * to evaluate false on this SDK with no warning.
 */
final class AudienceCommerceTest extends TestCase
{
    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $context
     */
    private function evaluate(
        array $condition,
        array $context,
        ?int $currencyExponent = null
    ): bool {
        $audience = [
            'id' => 'aud_1',
            'rules' => ['id' => 'g1', 'logic' => 'AND', 'conditions' => [$condition]],
        ];

        return Audience::evaluateAudience($audience, $context, $currencyExponent);
    }

    /** @param array<string, mixed> $attributes */
    private function user(array $attributes = []): array
    {
        return array_merge(['kind' => 'user', 'key' => 'u1'], $attributes);
    }

    // ------------------------------------------------------------------
    // Alias operators
    // ------------------------------------------------------------------

    public function testIsAndIsNotBehaveAsEqualsAndNotEquals(): void
    {
        $is = ['type' => 'CUSTOM_ATTRIBUTE', 'attribute' => 'plan', 'operator' => 'is',
               'value' => 'enterprise', 'contextKind' => 'user'];
        self::assertTrue($this->evaluate($is, $this->user(['plan' => 'enterprise'])));
        self::assertFalse($this->evaluate($is, $this->user(['plan' => 'free'])));

        $isNot = ['type' => 'CUSTOM_ATTRIBUTE', 'attribute' => 'plan', 'operator' => 'is_not',
                  'value' => 'free', 'contextKind' => 'user'];
        self::assertTrue($this->evaluate($isNot, $this->user(['plan' => 'enterprise'])));
        self::assertFalse($this->evaluate($isNot, $this->user(['plan' => 'free'])));
    }

    public function testDoesNotContainAndDoesNotExist(): void
    {
        $doesNotContain = ['type' => 'CUSTOM_ATTRIBUTE', 'attribute' => 'email',
                           'operator' => 'does_not_contain', 'value' => '@acme.com',
                           'contextKind' => 'user'];
        self::assertTrue($this->evaluate($doesNotContain, $this->user(['email' => 'a@other.com'])));
        self::assertFalse($this->evaluate($doesNotContain, $this->user(['email' => 'a@ACME.com'])));

        $doesNotExist = ['type' => 'CUSTOM_ATTRIBUTE', 'attribute' => 'betaOptIn',
                         'operator' => 'does_not_exist', 'value' => '', 'contextKind' => 'user'];
        self::assertTrue($this->evaluate($doesNotExist, $this->user()));
        self::assertFalse($this->evaluate($doesNotExist, $this->user(['betaOptIn' => true])));
    }

    /**
     * A single user context cannot satisfy an organization-scoped condition,
     * not even does_not_exist: the sub-context is absent, so the condition is
     * not evaluable at all. This guard used to run for multi-contexts only.
     */
    public function testAConditionScopedToAnAbsentKindNeverMatches(): void
    {
        $condition = ['type' => 'CUSTOM_ATTRIBUTE', 'attribute' => 'tier',
                      'operator' => 'does_not_exist', 'value' => '',
                      'contextKind' => 'organization'];

        self::assertFalse($this->evaluate($condition, $this->user()));

        $multi = [
            'kind' => 'multi',
            'user' => ['kind' => 'user', 'key' => 'u1'],
            'organization' => ['kind' => 'organization', 'key' => 'org_1'],
        ];
        self::assertTrue($this->evaluate($condition, $multi));
    }

    public function testNumericOperatorsDoNotMatchTextAttributes(): void
    {
        $condition = ['type' => 'CUSTOM_ATTRIBUTE', 'attribute' => 'plan',
                      'operator' => 'greater_than', 'value' => '-1', 'contextKind' => 'user'];

        self::assertFalse($this->evaluate($condition, $this->user(['plan' => 'enterprise'])));
    }

    // ------------------------------------------------------------------
    // CART_VALUE
    // ------------------------------------------------------------------

    /** @param string|list<string> $value */
    private function cart(string $operator, string|array $value): array
    {
        return ['type' => 'CART_VALUE', 'id' => 'c', 'operator' => $operator,
                'value' => $value, 'contextKind' => 'user'];
    }

    public function testCartValueComparesDecimalMajorUnitsAgainstMinorUnitThresholds(): void
    {
        // 5000 minor units at exponent 2 is 50.00 major units.
        self::assertTrue($this->evaluate(
            $this->cart('greater_than', '5000'),
            $this->user(['cartValue' => 75])
        ));
        self::assertFalse($this->evaluate(
            $this->cart('greater_than', '5000'),
            $this->user(['cartValue' => 25])
        ));
    }

    public function testCartValueBetweenIsInclusiveOnBothEnds(): void
    {
        $condition = $this->cart('between', ['5000', '10000']);

        self::assertTrue($this->evaluate($condition, $this->user(['cartValue' => 50])));
        self::assertTrue($this->evaluate($condition, $this->user(['cartValue' => 100])));
        self::assertFalse($this->evaluate($condition, $this->user(['cartValue' => 100.01])));
    }

    public function testCartValueHonoursAZeroExponentCurrency(): void
    {
        $condition = $this->cart('greater_than', '25000');

        self::assertTrue($this->evaluate($condition, $this->user(['cartValue' => 25001]), 0));
        self::assertFalse($this->evaluate($condition, $this->user(['cartValue' => 100]), 0));
    }

    public function testCartValueFailsClosedOnMissingOrMistypedData(): void
    {
        self::assertFalse($this->evaluate($this->cart('greater_than', '5000'), $this->user()));
        // A numeric string is the wrong type: the caller owns this attribute.
        self::assertFalse($this->evaluate(
            $this->cart('greater_than', '5000'),
            $this->user(['cartValue' => '75'])
        ));
        self::assertFalse($this->evaluate(
            $this->cart('greater_than', 'lots'),
            $this->user(['cartValue' => 75])
        ));
        self::assertFalse($this->evaluate(
            $this->cart('between', ['5000']),
            $this->user(['cartValue' => 75])
        ));
    }

    // ------------------------------------------------------------------
    // PRODUCTS_VIEWED
    // ------------------------------------------------------------------

    /**
     * @param list<string> $value
     * @param array<string, mixed> $extras
     */
    private function viewed(string $operator, array $value, array $extras = []): array
    {
        return array_merge(
            ['type' => 'PRODUCTS_VIEWED', 'id' => 'c', 'operator' => $operator,
             'value' => $value, 'contextKind' => 'user'],
            $extras
        );
    }

    public function testViewedAnyMatchesTheViewedSkusList(): void
    {
        $condition = $this->viewed('viewed_any', ['SKU-RED-01', 'SKU-BLUE-02']);

        self::assertTrue($this->evaluate(
            $condition,
            $this->user(['viewedSkus' => ['SKU-GREEN-09', 'SKU-BLUE-02']])
        ));
        self::assertFalse($this->evaluate(
            $condition,
            $this->user(['viewedSkus' => ['SKU-GREEN-09']])
        ));
    }

    public function testNotViewedAnyInverts(): void
    {
        $condition = $this->viewed('not_viewed_any', ['SKU-RED-01']);

        self::assertTrue($this->evaluate(
            $condition,
            $this->user(['viewedSkus' => ['SKU-GREEN-09']])
        ));
        self::assertFalse($this->evaluate(
            $condition,
            $this->user(['viewedSkus' => ['SKU-RED-01']])
        ));
    }

    public function testCategoryMatchReadsTheCategoryList(): void
    {
        $condition = $this->viewed('viewed_any', ['shoes'], ['match' => 'category']);

        self::assertTrue($this->evaluate($condition, $this->user(['viewedCategories' => ['shoes']])));
        self::assertFalse($this->evaluate($condition, $this->user(['viewedSkus' => ['shoes']])));
    }

    /**
     * Dataset windows live in the browser, so the server cannot resolve
     * membership. Degrading to false beats an accidental full-traffic match.
     */
    public function testDatasetBackedConditionsAreFalseForBothOperators(): void
    {
        $viewedAny = $this->viewed(
            'viewed_any',
            ['SKU-RED-01'],
            ['datasetSlug' => 'recently-viewed']
        );
        self::assertFalse($this->evaluate(
            $viewedAny,
            $this->user(['viewedSkus' => ['SKU-RED-01']])
        ));

        $notViewedAny = $this->viewed(
            'not_viewed_any',
            ['SKU-RED-01'],
            ['datasetSlug' => 'recently-viewed']
        );
        self::assertFalse($this->evaluate($notViewedAny, $this->user(['viewedSkus' => []])));
    }

    // ------------------------------------------------------------------
    // PURCHASE_HISTORY
    // ------------------------------------------------------------------

    private function history(string $operator, string $value): array
    {
        return ['type' => 'PURCHASE_HISTORY', 'id' => 'c', 'operator' => $operator,
                'value' => $value, 'contextKind' => 'user'];
    }

    public function testHasPurchasedReadsTheProfile(): void
    {
        $buyer = $this->user(['purchaseHistory' => ['hasPurchased' => true]]);

        self::assertTrue($this->evaluate($this->history('has_purchased', ''), $buyer));
        self::assertFalse($this->evaluate($this->history('has_not_purchased', ''), $buyer));
    }

    /**
     * Frozen rule: absence means "not provided", not "never purchased". A
     * server caller owns this data, so the SDK does not guess on its behalf.
     */
    public function testAbsentPurchaseHistoryMakesEveryOperatorFalse(): void
    {
        self::assertFalse($this->evaluate($this->history('has_not_purchased', ''), $this->user()));
        self::assertFalse($this->evaluate($this->history('has_purchased', ''), $this->user()));
        self::assertFalse($this->evaluate($this->history('order_count_gt', '0'), $this->user()));
    }

    public function testLifetimeSpendComparesAgainstAMinorUnitThreshold(): void
    {
        $condition = $this->history('lifetime_spend_gt', '25000');

        self::assertTrue($this->evaluate($condition, $this->user([
            'purchaseHistory' => ['hasPurchased' => true, 'lifetimeSpend' => 250.01],
        ])));
        self::assertFalse($this->evaluate($condition, $this->user([
            'purchaseHistory' => ['hasPurchased' => true, 'lifetimeSpend' => 249],
        ])));
    }

    public function testOrderCountIsStrictlyGreaterThan(): void
    {
        $condition = $this->history('order_count_gt', '3');

        self::assertTrue($this->evaluate($condition, $this->user([
            'purchaseHistory' => ['hasPurchased' => true, 'orderCount' => 4],
        ])));
        self::assertFalse($this->evaluate($condition, $this->user([
            'purchaseHistory' => ['hasPurchased' => true, 'orderCount' => 3],
        ])));
    }

    public function testLastPurchaseOlderThanMeasuresAgainstTheClock(): void
    {
        $condition = $this->history('last_purchase_older_than', '30');

        self::assertTrue($this->evaluate($condition, $this->user([
            'purchaseHistory' => ['hasPurchased' => true, 'lastPurchaseAt' => 0],
        ])));
        self::assertFalse($this->evaluate($condition, $this->user([
            'purchaseHistory' => [
                'hasPurchased' => true,
                'lastPurchaseAt' => round(microtime(true) * 1000),
            ],
        ])));
    }

    public function testLastPurchaseIsVacuouslyOlderOnlyForSomeoneWhoNeverPurchased(): void
    {
        $condition = $this->history('last_purchase_older_than', '30');

        self::assertTrue($this->evaluate($condition, $this->user([
            'purchaseHistory' => ['hasPurchased' => false],
        ])));
        // A purchaser with an unknown timestamp is a "cannot know".
        self::assertFalse($this->evaluate($condition, $this->user([
            'purchaseHistory' => ['hasPurchased' => true],
        ])));
    }
}
