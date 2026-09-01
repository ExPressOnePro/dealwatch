<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->unsignedInteger('price_original')->nullable()->after('price');
            $table->unsignedInteger('price_mdl')->nullable()->after('price_original');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['price_original', 'price_mdl']);
        });
    }
};
