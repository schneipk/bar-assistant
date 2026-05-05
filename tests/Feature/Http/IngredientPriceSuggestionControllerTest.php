<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\Image;
use Kami\Cocktail\Models\PriceCategory;
use Kami\Cocktail\Models\IngredientPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kami\Cocktail\Models\IngredientPriceSuggestion;

class IngredientPriceSuggestionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_creates_price_suggestions_for_missing_category_prices(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create([
            'name' => 'Gin',
            'distillery' => 'Sample Distillery',
            'strength' => 40,
        ]);

        $baseCategory = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Base price',
            'currency' => 'USD',
            'is_base_category' => true,
        ]);
        $amazonCategory = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Amazon',
            'currency' => 'USD',
            'is_base_category' => false,
        ]);

        IngredientPrice::factory()->for($ingredient)->for($baseCategory)->create([
            'price' => 1800,
            'amount' => 700,
            'units' => 'ml',
        ]);

        $response = $this->postJson('/api/ingredients/' . $ingredient->id . '/price-suggestions/refresh');

        $response->assertOk();
        $response->assertJsonPath('data.0.provider_name', 'base-category-seed');
        $response->assertJsonPath('data.0.price_category.id', $amazonCategory->id);
        $response->assertJsonPath('data.0.ingredient.id', $ingredient->id);
        $response->assertJsonPath('data.0.suggested_price_minor', 1800);
        $response->assertJsonPath('data.0.suggested_amount', 700);
        $response->assertJsonPath('data.0.suggested_units', 'ml');

        $this->assertDatabaseHas('ingredient_price_suggestions', [
            'ingredient_id' => $ingredient->id,
            'price_category_id' => $amazonCategory->id,
            'provider_name' => 'base-category-seed',
            'status' => 'pending',
        ]);
    }

    public function test_accepting_suggestion_creates_ingredient_price_and_marks_suggestion_as_accepted(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create();
        $priceCategory = PriceCategory::factory()->for($membership->bar)->create([
            'currency' => 'USD',
        ]);

        $suggestion = IngredientPriceSuggestion::factory()->for($ingredient)->for($priceCategory)->create([
            'price' => 1599,
            'amount' => 700,
            'units' => 'ml',
            'provider_name' => 'base-category-seed',
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/ingredient-price-suggestions/' . $suggestion->id . '/accept', [
            'description' => 'Confirmed suggestion',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.price.price_minor', 1599);
        $response->assertJsonPath('data.amount', 700);
        $response->assertJsonPath('data.units', 'ml');

        $this->assertDatabaseHas('ingredient_prices', [
            'ingredient_id' => $ingredient->id,
            'price_category_id' => $priceCategory->id,
            'price' => 1599,
            'amount' => 700,
            'units' => 'ml',
            'description' => 'Confirmed suggestion',
        ]);

        $suggestion->refresh();
        $this->assertSame('accepted', $suggestion->status);
        $this->assertNotNull($suggestion->accepted_at);
    }

    public function test_lists_and_deletes_price_suggestions(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create();
        $priceCategory = PriceCategory::factory()->for($membership->bar)->create();

        $suggestion = IngredientPriceSuggestion::factory()->for($ingredient)->for($priceCategory)->create();

        $listResponse = $this->getJson('/api/ingredients/' . $ingredient->id . '/price-suggestions');
        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.0.id', $suggestion->id);

        $deleteResponse = $this->deleteJson('/api/ingredient-price-suggestions/' . $suggestion->id);
        $deleteResponse->assertNoContent();

        $this->assertDatabaseMissing('ingredient_price_suggestions', ['id' => $suggestion->id]);
    }

    public function test_refresh_creates_external_json_price_suggestion_when_category_is_configured(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create([
            'name' => 'Gin',
            'distillery' => 'Acme Distillery',
            'strength' => 40,
        ]);

        PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Basis',
            'currency' => 'EUR',
            'is_base_category' => true,
        ]);

        $category = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Configured API',
            'currency' => 'EUR',
            'suggestion_provider' => 'external-json',
            'suggestion_config' => [
                'url_template' => 'https://pricing.example.test/search?q={query}',
                'items_path' => 'items',
                'name_path' => 'title',
                'price_path' => 'offer.price',
                'url_path' => 'offer.url',
                'amount_path' => 'size.amount',
                'units_path' => 'size.units',
            ],
        ]);

        Http::fake([
            'https://pricing.example.test/*' => Http::response([
                'items' => [
                    [
                        'title' => 'Acme Gin 700ml',
                        'offer' => [
                            'price' => 21.49,
                            'url' => 'https://pricing.example.test/products/acme-gin',
                        ],
                        'size' => [
                            'amount' => 700,
                            'units' => 'ml',
                        ],
                    ],
                    [
                        'title' => 'Acme Reserve Gin 700ml',
                        'offer' => [
                            'price' => 24.99,
                            'url' => 'https://pricing.example.test/products/acme-reserve-gin',
                        ],
                        'size' => [
                            'amount' => 700,
                            'units' => 'ml',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/ingredients/' . $ingredient->id . '/price-suggestions/refresh', [
            'price_category_id' => $category->id,
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.provider_name', 'external-json');
        $response->assertJsonPath('data.0.price_category.id', $category->id);
        $response->assertJsonPath('data.0.match_name', 'Acme Gin 700ml');
        $response->assertJsonPath('data.0.external_url', 'https://pricing.example.test/products/acme-gin');
        $response->assertJsonPath('data.0.suggested_price_minor', 2149);
        $response->assertJsonPath('data.1.match_name', 'Acme Reserve Gin 700ml');
        $response->assertJsonPath('data.1.external_url', 'https://pricing.example.test/products/acme-reserve-gin');
        $response->assertJsonPath('data.1.suggested_price_minor', 2499);
    }

    public function test_refresh_creates_rumundco_price_suggestion_for_best_matching_result(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create([
            'name' => 'Amaretto Likör',
            'distillery' => 'Disaronno',
            'strength' => 28,
        ]);

        $baseCategory = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Basis',
            'currency' => 'EUR',
            'is_base_category' => true,
        ]);

        IngredientPrice::factory()->for($ingredient)->for($baseCategory)->create([
            'price' => 1899,
            'amount' => 700,
            'units' => 'ml',
        ]);

        $category = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Rum&Co',
            'currency' => 'EUR',
            'suggestion_provider' => 'rumundco-search',
            'suggestion_config' => [
                'url_template' => 'https://www.rumundco.de/search?search={query}',
                'skip_non_alcoholic' => true,
            ],
        ]);

        Http::fake([
            'https://www.rumundco.de/*' => Http::response(<<<'HTML'
<div class="product-box-inner">
  <div class="product-box-content">
    <a class="product-title" href="https://www.rumundco.de/adriatico-amaretto-zero-07l">Adriatico Amaretto Zero 0,7l (alkoholfrei)</a>
        <img src="https://cdn.rum.test/adriatico-zero.webp" alt="Adriatico" />
    <div class="price-container"><div class="price">24,90 €</div></div>
  </div>
</div>
<div class="product-box-inner">
    <div class="product-box-content">
        <a class="product-title" href="https://www.rumundco.de/Luxardo-Amaretto-24-07l">Luxardo Amaretto 24% 0,7l</a>
                <img src="https://cdn.rum.test/luxardo.webp" alt="Luxardo" />
        <div class="price-container"><div class="price">17,90 €</div></div>
    </div>
</div>
<div class="product-box-inner">
  <div class="product-box-content">
    <a class="product-title" href="https://www.rumundco.de/Disaronno-Amaretto-Likoer-28-07l">Disaronno Amaretto Likör 28% 0,7l</a>
        <img src="https://cdn.rum.test/disaronno.webp" alt="Disaronno" />
    <div class="price-container"><div class="price">18,50 €</div></div>
  </div>
</div>
HTML),
        ]);

        $response = $this->postJson('/api/ingredients/' . $ingredient->id . '/price-suggestions/refresh', [
            'price_category_id' => $category->id,
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.provider_name', 'rumundco-search');
        $response->assertJsonPath('data.0.price_category.id', $category->id);
        $response->assertJsonPath('data.0.match_name', 'Disaronno Amaretto Likör 28% 0,7l');
        $response->assertJsonPath('data.0.external_url', 'https://www.rumundco.de/Disaronno-Amaretto-Likoer-28-07l');
        $response->assertJsonPath('data.0.image_url', 'https://cdn.rum.test/disaronno.webp');
        $response->assertJsonPath('data.0.suggested_price_minor', 1850);
        $response->assertJsonPath('data.0.suggested_amount', 700);
        $response->assertJsonPath('data.0.suggested_units', 'ml');
        $response->assertJsonPath('data.1.match_name', 'Luxardo Amaretto 24% 0,7l');
    }

    public function test_refresh_uses_rumundco_query_without_strength_when_result_count_is_low(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create([
            'name' => 'Amaretto Likör',
            'distillery' => 'Disaronno',
            'strength' => 28,
        ]);

        $category = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Rum&Co',
            'currency' => 'EUR',
            'suggestion_provider' => 'rumundco-search',
            'suggestion_config' => [
                'url_template' => 'https://www.rumundco.de/search?search={query}',
                'skip_non_alcoholic' => true,
                'refine_with_strength_above_results' => 5,
            ],
        ]);

        $requestedUrls = [];

        Http::fake(function ($request) use (&$requestedUrls) {
            $requestedUrls[] = $request->url();

            return Http::response(<<<'HTML'
<div class="product-box-inner">
  <div class="product-box-content">
    <a class="product-title" href="https://www.rumundco.de/Disaronno-Amaretto-Likoer-28-07l">Disaronno Amaretto Likör 28% 0,7l</a>
    <img src="https://cdn.rum.test/disaronno.webp" alt="Disaronno" />
    <div class="price-container"><div class="price">18,50 €</div></div>
  </div>
</div>
HTML);
        });

        $response = $this->postJson('/api/ingredients/' . $ingredient->id . '/price-suggestions/refresh', [
            'price_category_id' => $category->id,
        ]);

        $response->assertOk();
        $this->assertCount(1, $requestedUrls);
        $this->assertStringNotContainsString('28%25', $requestedUrls[0]);
    }

    public function test_refresh_creates_amazon_price_suggestions_when_category_is_configured(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create([
            'name' => 'Amaretto Likör',
            'distillery' => 'Disaronno',
            'strength' => 28,
        ]);

        $baseCategory = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Basis',
            'currency' => 'EUR',
            'is_base_category' => true,
        ]);

        IngredientPrice::factory()->for($ingredient)->for($baseCategory)->create([
            'price' => 1899,
            'amount' => 700,
            'units' => 'ml',
        ]);

        $category = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Amazon',
            'currency' => 'EUR',
            'suggestion_provider' => 'amazon-search',
            'suggestion_config' => [
                'url_template' => 'https://www.amazon.de/s?k={query}',
                'refine_with_strength_above_results' => 10,
            ],
        ]);

        Http::fake([
            'https://www.amazon.de/*' => Http::response(<<<'HTML'
<div data-component-type="s-search-result">
  <h2><a href="/Disaronno-Amaretto-700ml/dp/B0001"><span>Disaronno Amaretto Likör 28% 700ml</span></a></h2>
  <img class="s-image" src="https://images.amazon.test/disaronno.jpg" />
  <span class="a-price"><span class="a-offscreen">18,49 €</span></span>
</div>
<div data-component-type="s-search-result">
  <h2><a href="/Luxardo-Amaretto-700ml/dp/B0002"><span>Luxardo Amaretto 700ml</span></a></h2>
  <img class="s-image" src="https://images.amazon.test/luxardo.jpg" />
  <span class="a-price"><span class="a-offscreen">17,99 €</span></span>
</div>
HTML),
        ]);

        $response = $this->postJson('/api/ingredients/' . $ingredient->id . '/price-suggestions/refresh', [
            'price_category_id' => $category->id,
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.provider_name', 'amazon-search');
        $response->assertJsonPath('data.0.match_name', 'Disaronno Amaretto Likör 28% 700ml');
        $response->assertJsonPath('data.0.external_url', 'https://www.amazon.de/Disaronno-Amaretto-700ml/dp/B0001');
        $response->assertJsonPath('data.0.image_url', 'https://images.amazon.test/disaronno.jpg');
        $response->assertJsonPath('data.0.suggested_price_minor', 1849);
        $response->assertJsonPath('data.1.match_name', 'Luxardo Amaretto 700ml');
        $response->assertJsonPath('data.1.suggested_price_minor', 1799);
    }

        public function test_refresh_creates_amazon_price_suggestions_with_current_title_recipe_markup(): void
        {
                $membership = $this->setupBarMembership();
                $this->actingAs($membership->user);

                $ingredient = Ingredient::factory()->for($membership->bar)->create([
                        'name' => 'Amaretto Likör',
                        'distillery' => 'Disaronno',
                        'strength' => 28,
                ]);

                $baseCategory = PriceCategory::factory()->for($membership->bar)->create([
                        'name' => 'Basis',
                        'currency' => 'EUR',
                        'is_base_category' => true,
                ]);

                IngredientPrice::factory()->for($ingredient)->for($baseCategory)->create([
                        'price' => 1899,
                        'amount' => 700,
                        'units' => 'ml',
                ]);

                $category = PriceCategory::factory()->for($membership->bar)->create([
                        'name' => 'Amazon',
                        'currency' => 'EUR',
                        'suggestion_provider' => 'amazon-search',
                        'suggestion_config' => [
                                'url_template' => 'https://www.amazon.de/s?k={query}',
                                'refine_with_strength_above_results' => 10,
                        ],
                ]);

                Http::fake([
                        'https://www.amazon.de/*' => Http::response(<<<'HTML'
<div data-component-type="s-search-result" data-asin="B0876FMMNG">
    <div class="sg-col-inner">
        <div class="s-product-image-container">
            <a aria-hidden="true" class="a-link-normal s-no-outline" tabindex="-1" href="/DISARONNO-VELVET-Liqueur-17-Likoere/dp/B0876FMMNG/ref=sr_1_1">
                <img class="s-image" src="https://images.amazon.test/disaronno-velvet.jpg" />
            </a>
        </div>
        <div class="a-section a-spacing-small puis-padding-left-small puis-padding-right-small">
            <div data-cy="title-recipe" class="a-section a-spacing-none a-spacing-top-small s-title-instructions-style">
                <a class="a-link-normal s-line-clamp-4 s-link-style a-text-normal" href="/DISARONNO-VELVET-Liqueur-17-Likoere/dp/B0876FMMNG/ref=sr_1_1">
                    DISARONNO DISARONNO VELVET Liqueur 17% Volume 0.7 l Liköre
                </a>
            </div>
            <span class="a-price"><span class="a-offscreen">18,49 €</span></span>
        </div>
    </div>
</div>
HTML),
                ]);

                $response = $this->postJson('/api/ingredients/' . $ingredient->id . '/price-suggestions/refresh', [
                        'price_category_id' => $category->id,
                ]);

                $response->assertOk();
                $response->assertJsonCount(1, 'data');
                $response->assertJsonPath('data.0.provider_name', 'amazon-search');
                $response->assertJsonPath('data.0.match_name', 'DISARONNO DISARONNO VELVET Liqueur 17% Volume 0.7 l Liköre');
                $response->assertJsonPath('data.0.external_url', 'https://www.amazon.de/DISARONNO-VELVET-Liqueur-17-Likoere/dp/B0876FMMNG/ref=sr_1_1');
                $response->assertJsonPath('data.0.image_url', 'https://images.amazon.test/disaronno-velvet.jpg');
                $response->assertJsonPath('data.0.suggested_price_minor', 1849);
                $response->assertJsonPath('data.0.suggested_amount', 700);
                $response->assertJsonPath('data.0.suggested_units', 'ml');
        }

    public function test_create_variant_from_price_suggestion_creates_child_ingredient_with_price_and_image(): void
    {
        Storage::fake('uploads');

        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create([
            'name' => 'Amaretto Likör',
            'distillery' => 'Original',
            'strength' => 22,
            'units' => 'ml',
        ]);

        $priceCategory = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Rum&Co',
            'currency' => 'EUR',
        ]);

        $suggestion = IngredientPriceSuggestion::factory()->for($ingredient)->for($priceCategory)->create([
            'provider_name' => 'rumundco-search',
            'match_name' => 'Disaronno Amaretto Likör 28% 0,7l',
            'external_url' => 'https://www.rumundco.de/Disaronno-Amaretto-Likoer-28-07l',
            'price' => 1850,
            'amount' => 700,
            'units' => 'ml',
            'meta' => [
                'image_url' => 'https://cdn.rum.test/disaronno.webp',
            ],
        ]);

        Http::fake([
            'https://cdn.rum.test/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5WQAAAAASUVORK5CYII='), 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->postJson('/api/ingredient-price-suggestions/' . $suggestion->id . '/create-variant');

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Disaronno Amaretto Likör 28% 0,7l');
        $response->assertJsonPath('data.hierarchy.parent_ingredient.id', $ingredient->id);
        $response->assertJsonPath('data.distillery', 'Disaronno');
        $response->assertJsonPath('data.strength', 28);

        $createdIngredientId = $response->json('data.id');

        $this->assertDatabaseHas('ingredients', [
            'id' => $createdIngredientId,
            'parent_ingredient_id' => $ingredient->id,
            'name' => 'Disaronno Amaretto Likör 28% 0,7l',
        ]);

        $this->assertDatabaseHas('ingredient_prices', [
            'ingredient_id' => $createdIngredientId,
            'price_category_id' => $priceCategory->id,
            'price' => 1850,
            'amount' => 700,
            'units' => 'ml',
        ]);

        $image = Image::query()->where('imageable_type', Ingredient::class)->where('imageable_id', $createdIngredientId)->first();
        $this->assertNotNull($image);

        $suggestion->refresh();
        $this->assertSame('accepted', $suggestion->status);
        $this->assertNotNull($suggestion->accepted_at);
    }
}
