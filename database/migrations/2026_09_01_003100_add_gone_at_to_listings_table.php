<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Когда объявление пропало с площадки — косвенный признак, что телефон продан. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('gone_at')->nullable()->after('last_seen_at');
            $table->boolean('archived')->default(false)->after('gone_at');

            $table->index('gone_at');
            $table->index('archived');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['gone_at']);
            $table->dropIndex(['archived']);
            $table->dropColumn(['gone_at', 'archived']);
        });
    }
};
