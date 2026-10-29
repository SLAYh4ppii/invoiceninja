<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\Product;

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Utils\Number;

/**
 * Applies product price tiers (quantity based pricing) to the
 * line items of an entity (invoice, quote, credit, recurring invoice).
 *
 * A product may define `price_tiers` as a JSON array such as:
 *
 *     [
 *         {"min_quantity": 10,  "price": 9.5},
 *         {"min_quantity": 50,  "discount": 12.5},
 *         {"min_quantity": 100, "price": 7.0}
 *     ]
 *
 * How the tiers are resolved depends on the product's `price_tiers_mode`:
 *
 *  - `all_units`   every unit is billed at the highest tier satisfying
 *                  `quantity >= min_quantity`. A `price` tier sets the line
 *                  item `cost` directly, a `discount` tier sets the line
 *                  item `discount` (percent) and unsets `is_amount_discount`.
 *  - `progressive` each bracket is billed at its own rate (the first units
 *                  up to the first tier use the base price, the following
 *                  brackets use the tier rates). The line item `cost` is set
 *                  to the resulting blended unit price. A `discount` tier is
 *                  converted into a bracket rate relative to the base price.
 *
 * Pricing provenance and snapshots
 * --------------------------------
 * Every evaluated line item carries a private `_tier` marker recording what
 * the engine owns:
 *
 *     {
 *         "applied": true,                    engine wrote a tier price/discount
 *         "product_key": "ABC",
 *         "quantity": 20,                     signature fields at last run
 *         "cost": 9,
 *         "discount": 0,
 *         "base_cost": 10,                    cost before the engine first ran
 *         "prev_discount": 0,                 discount before the engine first ran
 *         "prev_is_amount_discount": false
 *     }
 *
 * A line is only re-derived when its pricing signature (product_key,
 * quantity, cost, discount and the document's is_amount_discount flag)
 * differs from the marker, or - for markerless lines - when it differs
 * from the stored document. Unchanged lines are
 * pinned: editing an unrelated field, changing a product's tiers or
 * enabling the company feature flag never reprices a stored document
 * implicitly. Because the marker travels inside the line item payload,
 * quotes converted to invoices and invoices generated from recurring
 * documents keep the prices that were agreed at the time.
 *
 * Re-derivation always starts from the captured `base_cost`, never from
 * the previously calculated values, so the result depends only on the
 * product definition and the current quantity - not on edit history.
 *
 * Manual overrides
 * ----------------
 * `cost` is user-owned when it differs from what the engine last wrote
 * (or, on a markerless line, from the product base price). `discount` is
 * user-owned when it differs from the engine result (or is non-zero on a
 * markerless line). User-owned fields are never overwritten; a `price`
 * tier therefore stacks with a manual discount rather than clearing it.
 * Setting `use_price_tiers` to `false` opts a line out and restores the
 * captured base values.
 *
 * Document discount mode
 * ----------------------
 * In Invoice Ninja `is_amount_discount` is a document level flag which
 * the totals engine copies onto every discounted line. A `discount` tier
 * therefore expresses its percentage in the mode the document currently
 * uses: in percent mode the percent value is written directly, in amount
 * mode the equivalent fixed amount (cost x quantity x percent) is
 * written so the effective line total is identical in both modes.
 */
class ApplyPriceTiers
{
    private Company|Client $entity;

    private string $locale = 'en';

    private bool $doc_amount_discount = false;

    /**
     * @param  array<object>|null  $line_items
     * @param  array<object>|null  $original_items  line items as currently stored (pins unchanged, markerless lines)
     * @param  bool  $is_amount_discount  the document level discount mode (invoice.is_amount_discount)
     * @return array<object>|null
     */
    public function run(?array $line_items, ?array $original_items, bool $is_amount_discount, Company $company, ?Client $client = null): ?array
    {
        if (! $company->enable_price_tiers || ! is_array($line_items) || count($line_items) === 0) {
            return $line_items;
        }

        $this->entity = $client ?? $company;
        $this->doc_amount_discount = $is_amount_discount;
        $this->locale = $client?->locale() ?? $company->locale() ?? 'en';

        $products = Product::query()
            ->where('company_id', $company->id)
            ->whereIn('product_key', $this->resolveProductKeys($line_items))
            ->whereNotNull('price_tiers')
            ->get()
            ->keyBy('product_key');

        foreach ($line_items as $index => $item) {
            $meta = $item->_tier ?? null;

            /* Opted out line: restore engine owned values and drop the marker */
            if (($item->use_price_tiers ?? true) === false) {
                if ($meta) {
                    $this->restoreLine($item, $meta);
                    $this->stripTierNote($item);
                    unset($item->_tier);
                }

                continue;
            }

            if (! $this->isTierable($item)) {
                continue;
            }

            /* Pricing signature unchanged since the last run - keep the snapshot */
            if ($meta && $this->signatureMatches($item, $meta)) {
                continue;
            }

            /* Markerless line identical to the stored one: an unrelated update
             * must not reprice it. Adopt a dropped marker or pin the line as-is. */
            $original = $original_items[$index] ?? null;

            if (! $meta && $original && $this->samePricingFields($item, $original)) {
                $item->_tier = $original->_tier ?? $this->buildMarker($item, false);

                continue;
            }

            $product = $products->get($item->product_key);

            if ($meta) {
                /* Re-derive from the captured base values; fields the user
                 * changed since the last run are user-owned and untouched */
                $manual_cost = ! $this->floatEquals($item->cost ?? 0, $meta->cost);
                $manual_discount = ! $this->floatEquals($item->discount ?? 0, $meta->discount);

                $base_cost = (float) ($meta->base_cost ?? $item->cost ?? 0);
                $prev_discount = (float) ($meta->prev_discount ?? 0);
                $prev_is_amount_discount = (bool) ($meta->prev_is_amount_discount ?? false);

                if (! $manual_cost) {
                    $item->cost = $base_cost;
                }

                if (! $manual_discount) {
                    $item->discount = $prev_discount;
                    $item->is_amount_discount = $prev_is_amount_discount;
                }
            } else {
                $base_cost = (float) ($item->cost ?? 0);
                $prev_discount = (float) ($item->discount ?? 0);
                $prev_is_amount_discount = (bool) ($item->is_amount_discount ?? false);

                $manual_cost = $product
                    && ! $this->floatEquals($item->cost ?? 0, 0)
                    && ! $this->floatEquals($item->cost ?? 0, $product->price);

                $manual_discount = ! $this->floatEquals($item->discount ?? 0, 0);
            }

            $this->stripTierNote($item);

            $applied = false;

            if ($product) {
                $applied = $this->applyProductTiers($item, $product, $base_cost, $manual_cost, $manual_discount);
            }

            $item->_tier = (object) [
                'applied' => $applied,
                'product_key' => (string) ($item->product_key ?? ''),
                'quantity' => (float) ($item->quantity ?? 0),
                'cost' => (float) ($item->cost ?? 0),
                'discount' => (float) ($item->discount ?? 0),
                'base_cost' => $base_cost,
                'prev_discount' => $prev_discount,
                'prev_is_amount_discount' => $prev_is_amount_discount,
                'doc_amount' => $this->doc_amount_discount,
            ];
        }

        return $line_items;
    }

    /**
     * Resolves the matching tier for the current quantity and writes it to
     * the line item. Only fields that are not user-owned are touched.
     *
     * @return bool whether the engine wrote a tier price or discount
     */
    private function applyProductTiers(object $item, Product $product, float $base_cost, bool $manual_cost, bool $manual_discount): bool
    {
        $quantity = (float) ($item->quantity ?? 0);

        if (($product->price_tiers_mode ?? 'all_units') === 'progressive') {
            $cost = $this->progressiveCost($product, $base_cost, $quantity);

            if ($cost === null || $manual_cost) {
                return false;
            }

            $item->cost = $cost;
            $this->appendTierNote($item, $this->progressiveNote($product, $base_cost));

            return true;
        }

        $tier = $product->priceTierForQuantity($quantity);

        if (! $tier) {
            return false;
        }

        if (isset($tier['price']) && ! $manual_cost) {
            $item->cost = (float) $tier['price'];
            $this->appendTierNote($item, ctrans('texts.price_tier_note', [
                'qty' => (float) $tier['min_quantity'],
                'rate' => Number::formatMoney((float) $tier['price'], $this->entity),
            ], $this->locale));

            return true;
        }

        if (isset($tier['discount']) && ! $manual_discount) {
            $percent = (float) $tier['discount'];

            /* Line level discounts follow the document discount mode: the
             * totals engine treats every line discount as a percent or a
             * fixed amount depending on invoice.is_amount_discount. In
             * amount mode the equivalent fixed amount is written so the
             * effective line total is identical in both modes */
            $item->discount = $this->doc_amount_discount
                ? Number::roundValue((float) $item->cost * $quantity * $percent / 100, 2)
                : $percent;

            $item->is_amount_discount = $this->doc_amount_discount;

            $this->appendTierNote($item, ctrans('texts.discount_tier_note', [
                'qty' => (float) $tier['min_quantity'],
                'discount' => $percent,
            ], $this->locale));

            return true;
        }

        return false;
    }

    /**
     * Whether the line's pricing fields still match the engine marker,
     * i.e. nothing relevant was edited since the last run.
     */
    private function signatureMatches(object $item, object $meta): bool
    {
        return (string) ($item->product_key ?? '') === (string) ($meta->product_key ?? '')
            && $this->floatEquals($item->quantity ?? 0, $meta->quantity ?? 0)
            && $this->floatEquals($item->cost ?? 0, $meta->cost ?? 0)
            && $this->floatEquals($item->discount ?? 0, $meta->discount ?? 0)
            && (bool) ($meta->doc_amount ?? false) === $this->doc_amount_discount;
    }

    /**
     * Whether an incoming markerless line carries the same pricing fields
     * as the line currently stored on the document.
     */
    private function samePricingFields(object $item, object $other): bool
    {
        return (string) ($item->product_key ?? '') === (string) ($other->product_key ?? '')
            && $this->floatEquals($item->quantity ?? 0, $other->quantity ?? 0)
            && $this->floatEquals($item->cost ?? 0, $other->cost ?? 0)
            && $this->floatEquals($item->discount ?? 0, $other->discount ?? 0)
            && ($other->use_price_tiers ?? true) !== false;
    }

    /**
     * Builds a marker for a pinned line: records the current values as the
     * pricing signature without applying a tier.
     */
    private function buildMarker(object $item, bool $applied): object
    {
        return (object) [
            'applied' => $applied,
            'product_key' => (string) ($item->product_key ?? ''),
            'quantity' => (float) ($item->quantity ?? 0),
            'cost' => (float) ($item->cost ?? 0),
            'discount' => (float) ($item->discount ?? 0),
            'base_cost' => (float) ($item->cost ?? 0),
            'prev_discount' => (float) ($item->discount ?? 0),
            'prev_is_amount_discount' => (bool) ($item->is_amount_discount ?? false),
            'doc_amount' => $this->doc_amount_discount,
        ];
    }

    /**
     * Restores the captured base values on fields the engine still owns
     * (opt-out and no-longer-matching re-derivations).
     */
    private function restoreLine(object $item, object $meta): void
    {
        if ($this->floatEquals($item->cost ?? 0, $meta->cost ?? 0)) {
            $item->cost = (float) ($meta->base_cost ?? $item->cost);
        }

        if ($this->floatEquals($item->discount ?? 0, $meta->discount ?? 0)) {
            $item->discount = (float) ($meta->prev_discount ?? 0);
            $item->is_amount_discount = (bool) ($meta->prev_is_amount_discount ?? false);
        }
    }

    /**
     * Blended unit price under progressive (bracket) pricing: every
     * quantity bracket between two `min_quantity` boundaries is billed at
     * the rate of the preceding tier.
     */
    private function progressiveCost(Product $product, float $base_price, float $quantity): ?float
    {
        if ($quantity <= 0) {
            return null;
        }

        $total = 0.0;
        $lower = 0.0;
        $rate = $base_price;

        foreach ($product->sortedPriceTiers() as $tier) {
            /* A tier applies to every unit from `min_quantity` onward, so
             * the bracket boundary sits one unit before it */
            $boundary = max(0, (float) $tier['min_quantity'] - 1);

            /* Tiers above the requested quantity are not reached */
            if ($boundary >= $quantity) {
                break;
            }

            /* Close the current bracket at the previous rate */
            if ($boundary > $lower) {
                $total += ($boundary - $lower) * $rate;
                $lower = $boundary;
            }

            $rate = isset($tier['price'])
                ? (float) $tier['price']
                : $base_price * (1 - (float) ($tier['discount'] ?? 0) / 100);
        }

        $total += ($quantity - $lower) * $rate;

        return Number::roundValue($total / $quantity, 4);
    }

    /**
     * Builds the bracket description for progressive pricing,
     * e.g. "1–9 @ €4.00 · 10–49 @ €3.60 · 50+ @ €3.20".
     */
    private function progressiveNote(Product $product, float $base_price): string
    {
        $tiers = $product->sortedPriceTiers()->values();

        $parts = [];

        if ($tiers->isNotEmpty() && (float) $tiers->first()['min_quantity'] > 1) {
            $parts[] = '1–' . ((float) $tiers->first()['min_quantity'] - 1)
                . ' @ ' . Number::formatMoney($base_price, $this->entity);
        }

        foreach ($tiers as $index => $tier) {
            $hi = isset($tiers[$index + 1])
                ? '–' . ((float) $tiers[$index + 1]['min_quantity'] - 1)
                : '+';

            $rate = isset($tier['price'])
                ? (float) $tier['price']
                : $base_price * (1 - (float) ($tier['discount'] ?? 0) / 100);

            $parts[] = (float) $tier['min_quantity'] . $hi
                . ' @ ' . Number::formatMoney($rate, $this->entity);
        }

        return ctrans('texts.progressive_tier_note', [
            'tiers' => implode(' · ', $parts),
        ], $this->locale);
    }

    /**
     * Removes previously generated tier notes so the line item never
     * accumulates stale pricing descriptions across re-saves.
     */
    private function stripTierNote(object $item): void
    {
        $notes = $item->notes ?? '';

        if (! is_string($notes) || $notes === '') {
            return;
        }

        $prefixes = array_map(function ($key) {
            $template = ctrans("texts.{$key}", [], $this->locale);

            $pos = strpos($template, ':');

            return $pos === false ? $template : substr($template, 0, $pos);
        }, ['price_tier_note', 'discount_tier_note', 'progressive_tier_note']);

        $lines = preg_split('/\r?\n/', $notes) ?: [];

        $kept = array_filter($lines, function ($line) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && str_starts_with(ltrim($line), rtrim($prefix))) {
                    return false;
                }
            }

            return true;
        });

        $item->notes = rtrim(implode("\n", $kept));
    }

    private function appendTierNote(object $item, string $note): void
    {
        $existing = trim($item->notes ?? '');

        $item->notes = $existing === '' ? $note : "{$existing}\n{$note}";
    }

    private function floatEquals(mixed $a, mixed $b): bool
    {
        return abs((float) $a - (float) $b) < 0.000001;
    }

    /**
     * @param  array<object>  $line_items
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function resolveProductKeys(array $line_items)
    {
        return collect($line_items)
            ->filter(function ($item) {
                return $this->isTierable($item);
            })
            ->pluck('product_key')
            ->unique()
            ->values();
    }

    private function isTierable(object $item): bool
    {
        if (($item->type_id ?? '1') != '1' || empty($item->product_key)) {
            return false;
        }

        return ($item->use_price_tiers ?? true) !== false;
    }
}
