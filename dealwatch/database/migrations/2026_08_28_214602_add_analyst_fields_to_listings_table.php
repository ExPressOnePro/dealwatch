<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->text('analyst_comment')->nullable()->after('parse_confidence');
            $table->json('analyst_flags')->nullable()->after('analyst_comment');
            $table->boolean('is_bait')->default(false)->after('analyst_flags');
            $table->string('analyst_risk', 16)->nullable()->after('is_bait');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['analyst_comment', 'analyst_flags', 'is_bait', 'analyst_risk']);
        });
    }
};
