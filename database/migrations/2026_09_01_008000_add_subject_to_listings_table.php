<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Предмет объявления: сам товар, запчасти, аксессуар или услуга — и оригинал ли.
 * Иначе «Piese JBL Charge» за 350 лей попадает в рынок колонок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('subject', 16)->default('item')->after('listing_kind');
            $table->boolean('is_replica')->default(false)->after('subject');

            $table->index(['search_profile_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['search_profile_id', 'subject']);
            $table->dropColumn(['subject', 'is_replica']);
        });
    }
};
