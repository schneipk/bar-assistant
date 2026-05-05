<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use Kami\Cocktail\Models\BarMembership;
use Kami\Cocktail\Models\PriceCategory;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PriceCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private BarMembership $barMembership;

    public function setUp(): void
    {
        parent::setUp();

        $this->barMembership = $this->setupBarMembership();
        $this->actingAs($this->barMembership->user);
    }

    public function test_list_price_categories_response(): void
    {
        PriceCategory::factory()->recycle($this->barMembership->bar)->count(10)->create();

        $response = $this->getJson('/api/price-categories', ['Bar-Assistant-Bar-Id' => $this->barMembership->bar_id]);

        $response->assertStatus(200);
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data', 10)
                ->etc()
        );
    }

    public function test_show_price_category_response(): void
    {
        $cat = PriceCategory::factory()->recycle($this->barMembership->bar)->create([
            'currency' => 'EUR'
        ]);

        $response = $this->getJson('/api/price-categories/' . $cat->id);

        $response->assertStatus(200);
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data')
                ->where('data.id', $cat->id)
                ->where('data.name', $cat->name)
                ->where('data.description', $cat->description)
                ->where('data.currency', $cat->currency)
                ->where('data.currency_symbol', '')
                ->where('data.is_base_category', false)
                ->where('data.suggestion_provider', null)
                ->where('data.suggestion_config', null)
                ->etc()
        );
    }

    public function test_create_price_category_response(): void
    {
        $response = $this->postJson('/api/price-categories', [
            'name' => 'Test cat',
            'description' => 'Test cat desc',
            'currency' => 'USD',
            'is_base_category' => true,
            'suggestion_provider' => 'external-json',
            'suggestion_config' => [
                'url_template' => 'https://example.com/search?q={query}',
                'price_path' => 'price',
            ],
        ], ['Bar-Assistant-Bar-Id' => $this->barMembership->bar_id]);

        $response->assertCreated();
        $this->assertNotEmpty($response->headers->get('Location'));
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data')
                ->has('data.id')
                ->where('data.name', 'Test cat')
                ->where('data.description', 'Test cat desc')
                ->where('data.currency', 'USD')
                ->where('data.currency_symbol', '')
                ->where('data.is_base_category', true)
                ->where('data.suggestion_provider', 'external-json')
                ->where('data.suggestion_config.url_template', 'https://example.com/search?q={query}')
                ->where('data.suggestion_config.price_path', 'price')
                ->etc()
        );
    }

    public function test_create_price_category_response_with_rumundco_provider_form_payload(): void
    {
        $response = $this->postJson('/api/price-categories', [
            'name' => 'Rum&Co',
            'description' => 'Rum search',
            'currency' => 'EUR',
            'suggestion_provider' => 'rumundco-search',
            'suggestion_config' => [
                'url_template' => 'https://www.rumundco.de/search?search={query}',
                'skip_non_alcoholic' => true,
                'refine_with_strength_above_results' => 12,
                'price_path' => null,
            ],
        ], ['Bar-Assistant-Bar-Id' => $this->barMembership->bar_id]);

        $response->assertCreated();
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->where('data.name', 'Rum&Co')
                ->where('data.suggestion_provider', 'rumundco-search')
                ->where('data.suggestion_config.url_template', 'https://www.rumundco.de/search?search={query}')
                ->where('data.suggestion_config.skip_non_alcoholic', true)
                ->where('data.suggestion_config.refine_with_strength_above_results', 12)
                ->etc()
        );
    }

    public function test_create_price_category_response_with_amazon_provider_form_payload(): void
    {
        $response = $this->postJson('/api/price-categories', [
            'name' => 'Amazon',
            'description' => 'Amazon search',
            'currency' => 'EUR',
            'suggestion_provider' => 'amazon-search',
            'suggestion_config' => [
                'url_template' => 'https://www.amazon.de/s?k={query}',
                'refine_with_strength_above_results' => 12,
                'price_path' => null,
            ],
        ], ['Bar-Assistant-Bar-Id' => $this->barMembership->bar_id]);

        $response->assertCreated();
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->where('data.name', 'Amazon')
                ->where('data.suggestion_provider', 'amazon-search')
                ->where('data.suggestion_config.url_template', 'https://www.amazon.de/s?k={query}')
                ->where('data.suggestion_config.refine_with_strength_above_results', 12)
                ->etc()
        );
    }

    public function test_update_price_category_response(): void
    {
        $cat = PriceCategory::factory()->recycle($this->barMembership->bar)->create();

        $response = $this->putJson('/api/price-categories/' . $cat->id, [
            'name' => 'Test cat',
            'description' => 'Test cat desc',
            'currency' => 'JPY',
            'is_base_category' => true,
            'suggestion_provider' => 'external-json',
            'suggestion_config' => [
                'url_template' => 'https://example.com/api?q={query}',
                'items_path' => 'items',
                'price_path' => 'price.value',
                'name_path' => 'name',
            ],
        ]);

        $response->assertSuccessful();
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data')
                ->where('data.id', $cat->id)
                ->where('data.name', 'Test cat')
                ->where('data.description', 'Test cat desc')
                ->where('data.currency', 'JPY')
                ->where('data.currency_symbol', '')
                ->where('data.is_base_category', true)
                ->where('data.suggestion_provider', 'external-json')
                ->where('data.suggestion_config.items_path', 'items')
                ->where('data.suggestion_config.price_path', 'price.value')
                ->etc()
        );
    }

    public function test_delete_price_category_response(): void
    {
        $cat = PriceCategory::factory()->recycle($this->barMembership->bar)->create();

        $response = $this->delete('/api/price-categories/' . $cat->id);

        $response->assertNoContent();

        $this->assertDatabaseMissing('price_categories', ['id' => $cat->id]);
    }
}
