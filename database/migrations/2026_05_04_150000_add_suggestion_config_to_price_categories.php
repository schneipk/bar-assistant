<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('price_categories', function (Blueprint $table): void {
            $table->string('suggestion_provider')->nullable()->after('is_base_category');
            $table->json('suggestion_config')->nullable()->after('suggestion_provider');
        });
    }

    public function down(): void
    {
        Schema::table('price_categories', function (Blueprint $table): void {
            $table->dropColumn(['suggestion_provider', 'suggestion_config']);
        });
    }
};
