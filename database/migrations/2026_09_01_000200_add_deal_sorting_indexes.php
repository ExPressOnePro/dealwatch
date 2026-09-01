<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Лента сортируется по прибыли и фильтруется по статусу — без индексов это seq scan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->index(['user_status', 'potential_profit'], 'deals_status_profit_index');
            $table->index('potential_profit');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex('deals_status_profit_index');
            $table->dropIndex(['potential_profit']);
        });
    }
};
