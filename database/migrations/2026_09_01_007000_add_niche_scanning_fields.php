<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перепись каталога: чтобы понимать, живая ниша или мёртвая, нужно знать,
 * какие объявления ещё висят, а какие ушли — и как быстро это происходит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Сколько переписей подряд объявление не встречалось в каталоге.
            $table->unsignedTinyInteger('missed_scans')->default(0)->after('gone_at');
            $table->index(['search_profile_id', 'gone_at']);
        });

        Schema::table('search_profiles', function (Blueprint $table) {
            $table->timestamp('last_scan_at')->nullable()->after('last_found');
            $table->unsignedSmallInteger('scan_depth')->default(300)->after('per_run');
            $table->unsignedInteger('last_scanned')->default(0)->after('last_scan_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['search_profile_id', 'gone_at']);
            $table->dropColumn('missed_scans');
        });

        Schema::table('search_profiles', function (Blueprint $table) {
            $table->dropColumn(['last_scan_at', 'scan_depth', 'last_scanned']);
        });
    }
};
