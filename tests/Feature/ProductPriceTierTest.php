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

namespace Tests\Feature;

use App\Factory\InvoiceItemFactory;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;
use App\Utils\Traits\MakesHash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Session;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Tests for quantity based product price tiers.
 *
 * @covers \App\Services\Product\ApplyPriceTiers
 * @covers \App\Models\Product::priceTierForQuantity
 */
class ProductPriceTierTest extends TestCase
{
    use MakesHash;
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        Session::start();
        Model::reguard();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );

        $this->makeTestData();
        $this->withoutExceptionHandling();

        /* Price tiers are gated by a company flag which defaults to false */
        $this->company->enable_price_tiers = true;
        $this->company->save();
    }

    private function apiHeaders(): array
    {
        return [
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ];
    }

    private function makeTieredProduct(): Product
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'product_key' => 'TIERED-PROD',
            'price' => 10,
            'cost' => 10,
            'quantity' => 1,
            'price_tiers' => [
                ['min_quantity' => 10, 'price' => 9],
                ['min_quantity' => 50, 'discount' => 20],
            ],
        ]);

        return $product;
    }

    private function postInvoice(array $item_overrides = [], array $doc_overrides = []): array
    {
        $item = InvoiceItemFactory::create();
        $item->product_key = 'TIERED-PROD';
        $item->type_id = '1';
        $item->cost = 10;
        $item->quantity = 1;

        foreach ($item_overrides as $key => $value) {
            $item->{$key} = $value;
        }

        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/invoices', array_merge([
            'client_id' => $this->client->hashed_id,
            'line_items' => [$item],
        ], $doc_overrides));

        $response->assertStatus(200);

        return $response->json('data');
    }

    private function putInvoice(string $hashed_id, array $payload): array
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->putJson('/api/v1/invoices/' . $hashed_id, $payload);

        $response->assertStatus(200);

        return $response->json('data');
    }

    public function testStoreProductWithPriceTiers()
    {
        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/products', [
            'product_key' => 'TIERED',
            'price' => 4,
            'price_tiers' => [
                ['min_quantity' => 200, 'price' => 3.2],
                ['min_quantity' => 50, 'discount' => 10],
            ],
        ])->assertStatus(200);

        $tiers = $response->json('data.price_tiers');

        /* Tiers are normalized and stored sorted by min_quantity */
        $this->assertEquals(50, $tiers[0]['min_quantity']);
        $this->assertEquals(200, $tiers[1]['min_quantity']);
    }

    public function testPriceTierValidationRequiresPriceOrDiscount()
    {
        $this->withExceptionHandling();

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/products', [
            'product_key' => 'TIERED-BAD',
            'price' => 4,
            'price_tiers' => [
                ['min_quantity' => 50],
            ],
        ])->assertStatus(422);
    }

    public function testPriceTierAppliedToInvoiceLineCost()
    {
        $this->makeTieredProduct();

        $data = $this->postInvoice(['quantity' => 20]);

        /* quantity 20 hits the {"min_quantity": 10, "price": 9} tier */
        $this->assertEquals(9, (float) $data['line_items'][0]['cost']);
    }

    public function testDiscountTierAppliedToInvoiceLineDiscount()
    {
        $this->makeTieredProduct();

        /* quantity 60 hits the {"min_quantity": 50, "discount": 20} tier;
         * in percent mode the percent is written directly */
        $data = $this->postInvoice(['quantity' => 60], ['is_amount_discount' => false]);

        $this->assertEquals(20, (float) $data['line_items'][0]['discount']);
        $this->assertFalse((bool) $data['line_items'][0]['is_amount_discount']);
        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
    }

    public function testDiscountTierInAmountModeWritesEquivalentAmount()
    {
        $this->makeTieredProduct();

        /* in amount mode the same tier writes the equivalent fixed
         * amount (10 x 60 x 20% = 120) - identical effective price */
        $data = $this->postInvoice(['quantity' => 60], ['is_amount_discount' => true]);

        $this->assertEquals(120, (float) $data['line_items'][0]['discount']);
        $this->assertTrue((bool) $data['line_items'][0]['is_amount_discount']);
        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
        $this->assertStringContainsString('Quantity discount: 20%', (string) $data['line_items'][0]['notes']);
    }

    public function testNoTierBelowThreshold()
    {
        $this->makeTieredProduct();

        $data = $this->postInvoice(['quantity' => 5]);

        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
        $this->assertEquals(0, (float) ($data['line_items'][0]['discount'] ?? 0));
    }

    public function testManualLineDiscountIsNotOverwritten()
    {
        $this->makeTieredProduct();

        $data = $this->postInvoice(['quantity' => 60, 'discount' => 33]);

        $this->assertEquals(33, (float) $data['line_items'][0]['discount']);
    }

    public function testLineItemCanOptOutOfPriceTiers()
    {
        $this->makeTieredProduct();

        $data = $this->postInvoice(['quantity' => 20, 'use_price_tiers' => false]);

        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
    }

    public function testProductBasePriceNotOverwrittenByTieredCost()
    {
        $product = $this->makeTieredProduct();

        $this->postInvoice(['quantity' => 20]);

        /* UpdateOrCreateProduct must not sync the tiered line cost (9)
         * back onto the product base price (10) */
        $this->assertEquals(10, (float) $product->fresh()->price);
    }

    public function testPriceTierForQuantityModelMethod()
    {
        $product = $this->makeTieredProduct();

        $this->assertNull($product->priceTierForQuantity(5));
        $this->assertEquals(9, $product->priceTierForQuantity(10)['price']);
        $this->assertEquals(9, $product->priceTierForQuantity(49)['price']);
        $this->assertEquals(20, $product->priceTierForQuantity(50)['discount']);
        $this->assertEquals(20, $product->priceTierForQuantity(5000)['discount']);
    }

    public function testPriceTiersModeValidation()
    {
        $this->withExceptionHandling();

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/products', [
            'product_key' => 'TIERED-MODE',
            'price' => 4,
            'price_tiers_mode' => 'banana',
        ])->assertStatus(422);

        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/products', [
            'product_key' => 'TIERED-MODE',
            'price' => 4,
            'price_tiers_mode' => 'progressive',
        ])->assertStatus(200);

        $this->assertEquals('progressive', $response->json('data.price_tiers_mode'));
    }

    public function testProgressiveModeAppliesBlendedUnitCost()
    {
        Product::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'product_key' => 'TIERED-PROD',
            'price' => 10,
            'price_tiers_mode' => 'progressive',
            'price_tiers' => [
                ['min_quantity' => 10, 'price' => 9],
                ['min_quantity' => 50, 'price' => 8],
            ],
        ]);

        $data = $this->postInvoice(['quantity' => 100]);

        /* units 1-9 @10 + units 10-49 @9 + units 50-100 @8 = 858 -> 8.58 per unit */
        $this->assertEquals(8.58, (float) $data['line_items'][0]['cost']);

        /* quantity below the second tier only fills the first bracket */
        $data = $this->postInvoice(['quantity' => 20]);
        $this->assertEquals(9.45, (float) $data['line_items'][0]['cost']);

        /* quantity below the first tier keeps the base price */
        $data = $this->postInvoice(['quantity' => 5]);
        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
    }

    public function testProgressiveModeKeepsManualDiscount()
    {
        Product::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'product_key' => 'TIERED-PROD',
            'price' => 10,
            'price_tiers_mode' => 'progressive',
            'price_tiers' => [
                ['min_quantity' => 10, 'discount' => 10],
            ],
        ]);

        $data = $this->postInvoice(['quantity' => 20, 'discount' => 33]);

        /* blended cost: units 1-9 @10 + units 10-20 @9 = 189 -> 9.45 per unit,
         * the manual 33% discount stays untouched */
        $this->assertEquals(9.45, (float) $data['line_items'][0]['cost']);
        $this->assertEquals(33, (float) $data['line_items'][0]['discount']);
    }

    public function testTierApplicationWritesNoteOnLineItem()
    {
        $this->makeTieredProduct();

        $data = $this->postInvoice(['quantity' => 20]);

        $this->assertStringContainsString('Quantity pricing: 10+', (string) $data['line_items'][0]['notes']);
    }

    public function testPriceTiersSkippedWhenCompanyFlagDisabled()
    {
        $this->company->enable_price_tiers = false;
        $this->company->save();

        $this->makeTieredProduct();

        $data = $this->postInvoice(['quantity' => 20]);

        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
    }

    public function testBasePriceRestoredWhenQuantityDropsBelowTier()
    {
        $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 20]);

        $item = $invoice['line_items'][0];
        $item['quantity'] = 5;

        $data = $this->putInvoice($invoice['id'], ['line_items' => [$item]]);

        /* re-derivation starts from the captured base cost, not the
         * previously calculated tier cost */
        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
    }

    public function testDiscountTierAppliesToBasePriceNotTieredCost()
    {
        $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 20], ['is_amount_discount' => false]);

        $item = $invoice['line_items'][0];
        $item['quantity'] = 60;

        $data = $this->putInvoice($invoice['id'], ['line_items' => [$item]]);

        /* 20% applies to the base price of 10 (effective 8), not to
         * the previously tiered cost of 9 (which would yield 7.20) */
        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
        $this->assertEquals(20, (float) $data['line_items'][0]['discount']);
    }

    public function testTierDiscountClearedWhenDroppingBackToPriceTier()
    {
        $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 60], ['is_amount_discount' => false]);

        $item = $invoice['line_items'][0];
        $item['quantity'] = 20;

        $data = $this->putInvoice($invoice['id'], ['line_items' => [$item]]);

        /* the engine owned 20% discount is removed and the price
         * tier applies - not both stacked */
        $this->assertEquals(9, (float) $data['line_items'][0]['cost']);
        $this->assertEquals(0, (float) $data['line_items'][0]['discount']);
    }

    public function testOptOutRestoresTieredCost()
    {
        $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 20]);

        $item = $invoice['line_items'][0];
        $item['use_price_tiers'] = false;

        $data = $this->putInvoice($invoice['id'], ['line_items' => [$item]]);

        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);
        $this->assertStringNotContainsString('Quantity pricing', (string) $data['line_items'][0]['notes']);
    }

    public function testOptOutRestoresTierDiscount()
    {
        $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 60], ['is_amount_discount' => false]);

        $item = $invoice['line_items'][0];
        $item['use_price_tiers'] = false;

        $data = $this->putInvoice($invoice['id'], ['line_items' => [$item]]);

        $this->assertEquals(0, (float) $data['line_items'][0]['discount']);
        $this->assertStringNotContainsString('Quantity discount', (string) $data['line_items'][0]['notes']);
    }

    public function testUnrelatedUpdateDoesNotRepriceAfterTierChange()
    {
        $product = $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 20]);

        /* the product tier changes after the document was priced */
        $product->price_tiers = [['min_quantity' => 10, 'price' => 7]];
        $product->save();

        $data = $this->putInvoice($invoice['id'], ['date' => '2026-11-01']);

        /* an unrelated field update must not implicitly refresh pricing */
        $this->assertEquals(9, (float) $data['line_items'][0]['cost']);
    }

    public function testUnchangedLineIsNotRepricedAfterTierChange()
    {
        $product = $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 20]);

        $product->price_tiers = [['min_quantity' => 10, 'price' => 7]];
        $product->save();

        /* resave echoing the stored line items unchanged */
        $data = $this->putInvoice($invoice['id'], ['line_items' => $invoice['line_items']]);

        $this->assertEquals(9, (float) $data['line_items'][0]['cost']);
    }

    public function testEnablingFeatureDoesNotRepriceExistingDocuments()
    {
        $this->company->enable_price_tiers = false;
        $this->company->save();

        $product = $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 20]);

        $this->company->enable_price_tiers = true;
        $this->company->save();

        /* unrelated update after enabling the feature */
        $data = $this->putInvoice($invoice['id'], ['date' => '2026-11-01']);

        $this->assertEquals(10, (float) $data['line_items'][0]['cost']);

        /* only an actual line item edit triggers tier resolution */
        $item = $data['line_items'][0];
        $item['quantity'] = 21;

        $data = $this->putInvoice($invoice['id'], ['line_items' => [$item]]);

        $this->assertEquals(9, (float) $data['line_items'][0]['cost']);
    }

    public function testManualDiscountRespectedWithExplicitOptIn()
    {
        $this->makeTieredProduct();

        /* explicit use_price_tiers=true must not allow the tier to
         * overwrite a manually entered discount */
        $data = $this->postInvoice(['quantity' => 60, 'discount' => 33, 'use_price_tiers' => true]);

        $this->assertEquals(33, (float) $data['line_items'][0]['discount']);
    }

    public function testManualLineCostIsNotOverwrittenByTier()
    {
        $this->makeTieredProduct();

        /* a cost the user typed (differing from the product base price)
         * is user-owned and kept */
        $data = $this->postInvoice(['quantity' => 20, 'cost' => 50]);

        $this->assertEquals(50, (float) $data['line_items'][0]['cost']);
    }

    public function testEngineDiscountIsNotMistakenForManualDiscount()
    {
        $this->makeTieredProduct();

        $invoice = $this->postInvoice(['quantity' => 60], ['is_amount_discount' => false]);

        /* unchanged resave: the engine written discount and its note
         * must both survive intact */
        $data = $this->putInvoice($invoice['id'], ['line_items' => $invoice['line_items']]);

        $this->assertEquals(20, (float) $data['line_items'][0]['discount']);
        $this->assertStringContainsString('Quantity discount', (string) $data['line_items'][0]['notes']);
    }

    public function testQuoteConversionKeepsAgreedTieredPrice()
    {
        $product = $this->makeTieredProduct();

        $item = InvoiceItemFactory::create();
        $item->product_key = 'TIERED-PROD';
        $item->type_id = '1';
        $item->cost = 10;
        $item->quantity = 20;

        $response = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/quotes', [
            'client_id' => $this->client->hashed_id,
            'line_items' => [$item],
        ])->assertStatus(200);

        $quote = Quote::find($this->decodePrimaryKey($response->json('data.id')));

        $this->assertEquals(9, (float) $quote->line_items[0]->cost);

        /* the tier price changes before the agreed quote is converted */
        $product->price_tiers = [['min_quantity' => 10, 'price' => 7]];
        $product->save();

        $invoice = $quote->service()->convertToInvoice();

        /* the agreed price is preserved - conversion is not a repricing point */
        $this->assertEquals(9, (float) $invoice->line_items[0]->cost);
    }
}
