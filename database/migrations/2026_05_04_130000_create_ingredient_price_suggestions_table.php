<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ingredient_price_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('price_category_id')->constrained('price_categories')->cascadeOnDelete();
            $table->string('provider_name');
            $table->string('status')->default('pending');
            $table->text('search_query')->nullable();
            $table->text('match_name')->nullable();
            $table->text('external_url')->nullable();
            $table->integer('price')->nullable();
            $table->decimal('amount')->nullable();
            $table->string('units')->nullable();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->json('meta')->default('{}');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['ingredient_id', 'price_category_id']);
            $table->index(['ingredient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_price_suggestions');
    }
};
