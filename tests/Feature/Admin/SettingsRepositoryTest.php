<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Settings\SettingsRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repo(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    public function test_values_are_cast_to_their_declared_type(): void
    {
        $repo = $this->repo();

        $repo->set('ai.batch_size', '25');
        $repo->set('ai.daily_cost_usd', '3.5');
        $repo->set('ai.enabled', false);
        $repo->set('ai.model_screen', 'my-screen-model');

        $this->assertSame(25, $repo->get('ai.batch_size'));
        $this->assertSame(3.5, $repo->get('ai.daily_cost_usd'));
        $this->assertFalse($repo->get('ai.enabled'));
        $this->assertSame('my-screen-model', $repo->get('ai.model_screen'));
    }

    public function test_secrets_are_stored_encrypted(): void
    {
        $this->repo()->set('ai.api_key', 'sk-super-secret');

        $stored = AppSetting::find('ai.api_key');
        $this->assertTrue($stored->is_encrypted);
        $this->assertStringNotContainsString('sk-super-secret', $stored->value);
        $this->assertSame('sk-super-secret', $this->repo()->get('ai.api_key'));
    }

    public function test_settings_override_config(): void
    {
        config(['dealwatch.economics.prep_cost' => 300, 'services.openai.key' => null]);

        $repo = $this->repo();
        $repo->set('economics.prep_cost', 450);
        $repo->set('ai.api_key', 'sk-from-admin');
        $repo->apply();

        $this->assertSame(450, config('dealwatch.economics.prep_cost'));
        $this->assertSame('sk-from-admin', config('services.openai.key'));
    }

    public function test_forgetting_a_setting_restores_the_env_value(): void
    {
        config(['dealwatch.economics.prep_cost' => 300]);
        $repo = $this->repo();

        $repo->set('economics.prep_cost', 999);
        $repo->apply();
        $this->assertSame(999, config('dealwatch.economics.prep_cost'));

        $repo->forget('economics.prep_cost');
        config(['dealwatch.economics.prep_cost' => 300]); // как при новом запросе — из .env
        $repo->apply();

        $this->assertSame(300, config('dealwatch.economics.prep_cost'));
    }

    public function test_removed_setting_returns_to_the_env_value_without_restart(): void
    {
        // Долгоживущий воркер применяет настройки перед каждой задачей: удалённое
        // значение должно вернуться к .env, а не застрять в памяти процесса.
        config(['dealwatch.economics.prep_cost' => 300]);
        $repo = $this->repo();

        $repo->apply();
        $repo->set('economics.prep_cost', 900);
        $repo->apply();
        $this->assertSame(900, config('dealwatch.economics.prep_cost'));

        $repo->forget('economics.prep_cost');
        $repo->apply();

        $this->assertSame(300, config('dealwatch.economics.prep_cost'));
    }

    public function test_queue_jobs_pick_up_fresh_settings(): void
    {
        config(['services.openai.key' => null]);

        // Ключ добавили уже после старта воркера — задача должна увидеть его
        // без перезапуска процесса.
        $this->repo()->set('ai.api_key', 'sk-added-later');

        SettingsProbeJob::$seenKey = null;
        dispatch(new SettingsProbeJob);

        $this->assertSame('sk-added-later', SettingsProbeJob::$seenKey);
    }

    public function test_values_are_read_from_cache(): void
    {
        $repo = $this->repo();
        $repo->set('ai.batch_size', 15);
        $repo->all();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $repo->all();
        $repo->all();

        $this->assertSame(0, $queries);
    }

    public function test_unknown_setting_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repo()->set('ai.superpower', 'on');
    }

    public function test_broken_encrypted_value_does_not_break_the_app(): void
    {
        AppSetting::create(['key' => 'ai.api_key', 'value' => 'не-шифртекст', 'is_encrypted' => true]);

        $this->assertNull($this->repo()->get('ai.api_key'));
    }
}

/** Заглушка: запоминает, каким конфиг увидела очередь. */
class SettingsProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?string $seenKey = null;

    public function handle(): void
    {
        self::$seenKey = config('services.openai.key');
    }
}
