<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('price_categories', function (Blueprint $table) {
            $table->boolean('is_base_category')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('price_categories', function (Blueprint $table) {
            $table->dropColumn('is_base_category');
        });
    }
};
