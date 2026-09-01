<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Источники объявлений: какие категории и по каким словам собирать.
 * До этого категория «мобильные телефоны» была зашита в коллекторе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('platform', 32)->default('999');

            // Категория площадки: раздел и/или подкатегория (обе необязательны —
            // можно искать по одним ключевым словам во всём каталоге).
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('subcategory_id')->nullable();
            $table->string('category_label')->nullable();

            $table->string('query')->nullable();                 // ключевые слова для поиска площадки
            $table->json('exclude_keywords')->nullable();        // что выкидываем уже у себя
            $table->unsignedInteger('price_min')->nullable();    // MDL
            $table->unsignedInteger('price_max')->nullable();
            $table->unsignedSmallInteger('per_run')->default(40);

            // phones — существующий движок со справочником моделей,
            // generic — рынок считается по самим объявлениям профиля.
            $table->string('scoring', 16)->default('generic');

            $table->boolean('notify')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_found')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'platform']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('search_profile_id')->nullable()->after('platform')
                ->constrained('search_profiles')->nullOnDelete();
            $table->index(['search_profile_id', 'status']);
        });

        // Текущее поведение сохраняем как профиль по умолчанию.
        $profileId = DB::table('search_profiles')->insertGetId([
            'name' => 'Телефоны 999.md',
            'platform' => '999',
            'subcategory_id' => 40,
            'category_label' => 'Telefoane mobile',
            'scoring' => 'phones',
            'per_run' => 40,
            'notify' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('listings')->whereNull('search_profile_id')->update(['search_profile_id' => $profileId]);
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropForeign(['search_profile_id']);
            $table->dropIndex(['search_profile_id', 'status']);
            $table->dropColumn('search_profile_id');
        });

        Schema::dropIfExists('search_profiles');
    }
};
