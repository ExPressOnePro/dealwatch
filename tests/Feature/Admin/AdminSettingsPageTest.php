<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\User;
use App\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
    }

    public function test_regular_user_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/settings')
            ->assertForbidden();
    }

    public function test_admin_sees_the_settings_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/settings')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/settings')
                ->has('groups.ai.fields')
                ->has('groups.economics.fields')
                ->has('usage.calls_limit')
            );
    }

    public function test_api_key_never_reaches_the_browser(): void
    {
        app(SettingsRepository::class)->set('ai.api_key', 'sk-super-secret-value');

        $response = $this->actingAs($this->admin)->get('/admin/settings');

        $response->assertDontSee('sk-super-secret-value');
        $response->assertInertia(function (AssertableInertia $page) {
            $field = collect($page->toArray()['props']['groups']['ai']['fields'])
                ->firstWhere('key', 'ai.api_key');

            $this->assertNull($field['value']);
            $this->assertTrue($field['overridden']);
            $this->assertStringEndsWith('alue', (string) $field['masked']);
        });
    }

    public function test_admin_can_save_key_and_models(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/settings', ['values' => [
                'ai.api_key' => 'sk-new-key',
                'ai.model_screen' => 'screen-model-x',
                'ai.model_deep' => 'deep-model-y',
                'ai.daily_cost_usd' => '7.5',
                'ai.enabled' => true,
            ]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $repo = app(SettingsRepository::class);
        $this->assertSame('sk-new-key', $repo->get('ai.api_key'));
        $this->assertSame('screen-model-x', $repo->get('ai.model_screen'));

        $repo->apply();
        $this->assertSame('sk-new-key', config('services.openai.key'));
        $this->assertSame('deep-model-y', config('dealwatch.ai.models.deep.name'));
        $this->assertSame(7.5, config('dealwatch.ai.limits.daily_cost_usd'));
    }

    public function test_empty_secret_field_keeps_the_saved_key(): void
    {
        $repo = app(SettingsRepository::class);
        $repo->set('ai.api_key', 'sk-existing');

        $this->actingAs($this->admin)->patch('/admin/settings', ['values' => [
            'ai.api_key' => '',
            'ai.batch_size' => '30',
        ]]);

        $this->assertSame('sk-existing', $repo->get('ai.api_key'));
        $this->assertSame(30, $repo->get('ai.batch_size'));
    }

    public function test_secret_can_be_removed_explicitly(): void
    {
        app(SettingsRepository::class)->set('ai.api_key', 'sk-existing');

        $this->actingAs($this->admin)
            ->delete('/admin/settings/secret', ['key' => 'ai.api_key'])
            ->assertRedirect();

        $this->assertNull(AppSetting::find('ai.api_key'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/settings', ['values' => ['ai.batch_size' => 0]])
            ->assertSessionHasErrors('values.ai.batch_size');
    }

    public function test_connection_check_reports_success(): void
    {
        app(SettingsRepository::class)->set('ai.api_key', 'sk-test');
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'model-a'], ['id' => 'model-b']]])]);

        $this->actingAs($this->admin)
            ->post('/admin/settings/test-connection')
            ->assertRedirect()
            ->assertSessionHas('ping', fn ($ping) => $ping['ok'] === true && str_contains($ping['message'], '2'));
    }

    public function test_connection_check_reports_rejected_key(): void
    {
        app(SettingsRepository::class)->set('ai.api_key', 'sk-bad');
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'invalid'], 401)]);

        $this->actingAs($this->admin)
            ->post('/admin/settings/test-connection')
            ->assertSessionHas('ping', fn ($ping) => $ping['ok'] === false && str_contains($ping['message'], '401'));
    }

    public function test_model_list_is_offered_for_selection(): void
    {
        app(SettingsRepository::class)->set('ai.api_key', 'sk-test');
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-b'], ['id' => 'gpt-a']]])]);

        $this->actingAs($this->admin)
            ->post('/admin/settings/refresh-models')
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get('/admin/settings')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('models', ['gpt-a', 'gpt-b']));
    }
}
