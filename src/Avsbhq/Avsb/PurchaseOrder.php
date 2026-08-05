<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

/**
 * A purchase, typed rather than an associative array.
 *
 * Named arguments make the call site readable, and the constructor signature
 * documents which fields the ingestion contract accepts, so a typo is a
 * compile-time error rather than a silently dropped field.
 *
 *   $server->trackPurchase($context, new PurchaseOrder(
 *       orderId: 'ORD-1001',
 *       total: 99.95,
 *       currency: 'USD',
 *       items: [['sku' => 'SKU-1', 'price' => 99.95, 'quantity' => 1]],
 *   ));
 */
readonly class PurchaseOrder
{
    /**
     * @param string $orderId  your order or transaction identifier
     * @param float $total     decimal major units as displayed, not minor
     *                         units. Must be greater than zero: ingest rejects
     *                         a zero or negative total.
     * @param string|null $currency ISO 4217 code
     * @param float|null $subtotal
     * @param float|null $shipping
     * @param float|null $tax
     * @param float|null $discount
     * @param string|null $coupon
     * @param bool|null $test  true stores the order but excludes it from every
     *                         revenue, profit, recommendation, and audience
     *                         calculation. Only send it when you mean it.
     * @param list<array<string, mixed>>|null $items each takes sku, and
     *                         optionally productKey, name, price, quantity,
     *                         and category
     */
    public function __construct(
        public string $orderId,
        public float $total,
        public ?string $currency = null,
        public ?float $subtotal = null,
        public ?float $shipping = null,
        public ?float $tax = null,
        public ?float $discount = null,
        public ?string $coupon = null,
        public ?bool $test = null,
        public ?array $items = null,
    ) {}

    /**
     * The wire body, with absent optional fields omitted rather than sent as
     * null.
     *
     * @return array<string, mixed>
     */
    public function toWireArray(): array
    {
        $wire = ['orderId' => $this->orderId, 'total' => $this->total];

        foreach ([
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'shipping' => $this->shipping,
            'tax' => $this->tax,
            'discount' => $this->discount,
            'coupon' => $this->coupon,
            'test' => $this->test,
            'items' => $this->items,
        ] as $field => $value) {
            if ($value !== null) {
                $wire[$field] = $value;
            }
        }

        return $wire;
    }
}
