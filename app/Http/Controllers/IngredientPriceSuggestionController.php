<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OAT;
use Kami\Cocktail\OpenAPI as BAO;
use Kami\Cocktail\Models\Ingredient;
use Illuminate\Http\Resources\Json\JsonResource;
use Kami\Cocktail\Models\IngredientPriceSuggestion;
use Kami\Cocktail\Http\Resources\IngredientResource;
use Kami\Cocktail\Http\Resources\IngredientPriceResource;
use Kami\Cocktail\Jobs\GenerateIngredientPriceSuggestions;
use Kami\Cocktail\Services\IngredientPriceSuggestionService;
use Kami\Cocktail\Http\Resources\IngredientPriceSuggestionResource;
use Kami\Cocktail\Http\Requests\AcceptIngredientPriceSuggestionRequest;
use Kami\Cocktail\Http\Requests\RefreshIngredientPriceSuggestionsRequest;

class IngredientPriceSuggestionController extends Controller
{
    #[OAT\Get(path: '/ingredients/{id}/price-suggestions', tags: ['Ingredients'], operationId: 'ingredientPriceSuggestions', summary: 'List price suggestions', description: 'Show stored price suggestions for an ingredient.', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ])]
    #[BAO\SuccessfulResponse(content: [
        new BAO\WrapItemsWithData(IngredientPriceSuggestionResource::class),
    ])]
    public function index(Request $request, string $idOrSlug): JsonResource
    {
        $ingredient = Ingredient::query()
            ->where('id', $idOrSlug)
            ->orWhere('slug', $idOrSlug)
            ->firstOrFail();

        if ($request->user()->cannot('show', $ingredient)) {
            abort(403);
        }

        $suggestions = $ingredient->priceSuggestions()
            ->with(['ingredient', 'priceCategory'])
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get();

        return IngredientPriceSuggestionResource::collection($suggestions);
    }

    #[OAT\Post(path: '/ingredients/{id}/price-suggestions/refresh', tags: ['Ingredients'], operationId: 'refreshIngredientPriceSuggestions', summary: 'Refresh price suggestions', description: 'Generate fresh price suggestions for an ingredient.', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ], requestBody: new OAT\RequestBody(required: false, content: [
        new OAT\JsonContent(type: 'object', properties: [
            new OAT\Property(property: 'price_category_id', type: 'integer', nullable: true, example: 1),
        ]),
    ]))]
    #[BAO\SuccessfulResponse(content: [
        new BAO\WrapItemsWithData(IngredientPriceSuggestionResource::class),
    ])]
    public function refresh(RefreshIngredientPriceSuggestionsRequest $request, string $idOrSlug): JsonResource
    {
        $ingredient = Ingredient::with('prices.priceCategory')
            ->where('id', $idOrSlug)
            ->orWhere('slug', $idOrSlug)
            ->firstOrFail();

        if ($request->user()->cannot('edit', $ingredient)) {
            abort(403);
        }

        GenerateIngredientPriceSuggestions::dispatch($ingredient->id, $request->integer('price_category_id') ?: null);

        $suggestions = $ingredient->priceSuggestions()
            ->with(['ingredient', 'priceCategory'])
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get();

        return IngredientPriceSuggestionResource::collection($suggestions);
    }

    #[OAT\Post(path: '/ingredient-price-suggestions/{id}/accept', tags: ['Ingredients'], operationId: 'acceptIngredientPriceSuggestion', summary: 'Accept price suggestion', description: 'Store a price suggestion as a real ingredient price.', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ], requestBody: new OAT\RequestBody(required: false, content: [
        new OAT\JsonContent(type: 'object', properties: [
            new OAT\Property(property: 'price', type: 'integer', nullable: true, example: 1599),
            new OAT\Property(property: 'amount', type: 'number', format: 'float', nullable: true, example: 700),
            new OAT\Property(property: 'units', type: 'string', nullable: true, example: 'ml'),
            new OAT\Property(property: 'description', type: 'string', nullable: true, example: 'Confirmed Amazon result'),
        ]),
    ]))]
    #[BAO\SuccessfulResponse(content: [
        new BAO\WrapObjectWithData(IngredientPriceResource::class),
    ])]
    public function accept(int $id, AcceptIngredientPriceSuggestionRequest $request, IngredientPriceSuggestionService $ingredientPriceSuggestionService): JsonResource
    {
        $suggestion = IngredientPriceSuggestion::with(['ingredient', 'priceCategory'])->findOrFail($id);

        if ($request->user()->cannot('edit', $suggestion->ingredient)) {
            abort(403);
        }

        $ingredientPrice = $ingredientPriceSuggestionService->acceptSuggestion($suggestion, $request->validated());

        return new IngredientPriceResource($ingredientPrice);
    }

    #[OAT\Post(path: '/ingredient-price-suggestions/{id}/create-variant', tags: ['Ingredients'], operationId: 'createVariantFromIngredientPriceSuggestion', summary: 'Create variant from price suggestion', description: 'Create a new ingredient variant from a stored price suggestion, optionally importing the product image and price.', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ])]
    #[OAT\Response(response: 201, description: 'Successful response', content: [
        new BAO\WrapObjectWithData(IngredientResource::class),
    ], headers: [
        new OAT\Header(header: 'Location', description: 'URL of the new resource', schema: new OAT\Schema(type: 'string')),
    ])]
    public function createVariant(int $id, Request $request, IngredientPriceSuggestionService $ingredientPriceSuggestionService): JsonResponse
    {
        $suggestion = IngredientPriceSuggestion::with(['ingredient', 'priceCategory'])->findOrFail($id);

        if ($request->user()->cannot('edit', $suggestion->ingredient)) {
            abort(403);
        }

        $ingredient = $ingredientPriceSuggestionService->createVariantFromSuggestion($suggestion, $request->user()->id);

        return (new IngredientResource($ingredient))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('ingredients.show', $ingredient->id));
    }

    #[OAT\Delete(path: '/ingredient-price-suggestions/{id}', tags: ['Ingredients'], operationId: 'deleteIngredientPriceSuggestion', summary: 'Delete price suggestion', description: 'Delete a price suggestion.', parameters: [
        new BAO\Parameters\DatabaseIdParameter(),
    ])]
    #[OAT\Response(response: 204, description: 'Successful response')]
    public function delete(Request $request, int $id): Response
    {
        $suggestion = IngredientPriceSuggestion::with('ingredient')->findOrFail($id);

        if ($request->user()->cannot('edit', $suggestion->ingredient)) {
            abort(403);
        }

        $suggestion->delete();

        return new Response(null, 204);
    }
}
