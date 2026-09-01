<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `price` дублировал `price_mdl` (коллектор писал в обе колонки одно значение),
 * а исходная цена лежит в `price_original`. Оставляем две осмысленные колонки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('listings', 'price')) {
            return;
        }

        DB::table('listings')->whereNull('price_mdl')->whereNotNull('price')
            ->update(['price_mdl' => DB::raw('price')]);
        DB::table('listings')->whereNull('price_original')->whereNotNull('price')
            ->update(['price_original' => DB::raw('price')]);

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['status', 'price']);
            $table->dropColumn('price');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->index(['status', 'price_mdl']);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('listings', 'price')) {
            return;
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['status', 'price_mdl']);
            $table->unsignedInteger('price')->nullable()->after('description');
        });

        DB::table('listings')->whereNotNull('price_mdl')->update(['price' => DB::raw('price_mdl')]);

        Schema::table('listings', function (Blueprint $table) {
            $table->index(['status', 'price']);
        });
    }
};
