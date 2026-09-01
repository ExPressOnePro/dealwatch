<?php

namespace Tests\Feature\Deals;

use App\Services\CollectorHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectorHealthMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.chat_id', '42');
        config()->set('dealwatch.collector.empty_runs_before_alert', 3);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    public function test_alert_fires_only_after_the_configured_streak(): void
    {
        $monitor = app(CollectorHealthMonitor::class);

        $this->assertSame(1, $monitor->recordRun(0));
        $this->assertSame(2, $monitor->recordRun(0));
        Http::assertNothingSent();

        $this->assertSame(3, $monitor->recordRun(0));
        Http::assertSentCount(1);
    }

    public function test_alert_is_not_repeated_on_every_run(): void
    {
        $monitor = app(CollectorHealthMonitor::class);

        foreach (range(1, 6) as $ignored) {
            $monitor->recordRun(0);
        }

        Http::assertSentCount(1);
    }

    public function test_network_outage_counts_as_an_empty_run(): void
    {
        // Телеграм отвечает, 999 недоступен — сбор не падает, а копит серию пустых прогонов.
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            '999.md/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->artisan('deals:collect-999', ['--no-notify' => true])->assertSuccessful();

        $this->assertSame(1, app(CollectorHealthMonitor::class)->emptyStreak());
    }

    public function test_successful_run_resets_the_streak(): void
    {
        $monitor = app(CollectorHealthMonitor::class);

        $monitor->recordRun(0);
        $monitor->recordRun(0);

        $this->assertSame(0, $monitor->recordRun(12));
        $this->assertSame(0, $monitor->emptyStreak());
        $this->assertSame(1, $monitor->recordRun(0));
        Http::assertNothingSent();
    }
}
