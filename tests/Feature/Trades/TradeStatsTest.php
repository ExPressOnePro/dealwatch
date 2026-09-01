<?php

namespace Tests\Feature\Trades;

use App\Models\Trade;
use App\Models\User;
use App\Services\TradeStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TradeStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function trade(array $attributes = [], array $expenses = []): Trade
    {
        $trade = Trade::factory()->create(array_merge(['user_id' => $this->user->id], $attributes));

        foreach ($expenses as $label => $amount) {
            $trade->expenses()->create(['label' => $label, 'amount' => $amount]);
        }

        return $trade->fresh();
    }

    public function test_summary_counts_expenses_in_profit(): void
    {
        $this->trade(['purchase_price' => 8000, 'sale_price' => 11000], ['Стекло' => 500]);
        $this->trade(['purchase_price' => 9000, 'sale_price' => 10000]);
        // ещё не проданы — в оборот и прибыль не попадают
        $this->trade(['status' => Trade::STATUS_LISTED, 'purchase_price' => 7000, 'sale_price' => null, 'sale_date' => null]);
        $this->trade(['status' => Trade::STATUS_BOUGHT, 'purchase_price' => 5000, 'sale_price' => null, 'sale_date' => null]);

        $summary = app(TradeStats::class)->summary($this->user->id);

        $this->assertSame(2, $summary['sold']);
        $this->assertSame(21000, $summary['turnover']);
        // (11000 − 8500) + (10000 − 9000)
        $this->assertSame(3500, $summary['profit']);
        $this->assertSame(500, $summary['expenses']);
        $this->assertSame(1750, $summary['avg_profit']);
    }

    public function test_summary_ignores_other_users(): void
    {
        $this->trade(['purchase_price' => 8000, 'sale_price' => 11000]);
        Trade::factory()->create(['user_id' => User::factory()->create()->id, 'sale_price' => 99000]);

        $this->assertSame(11000, app(TradeStats::class)->summary($this->user->id)['turnover']);
    }

    public function test_locked_money_counts_unsold_phones(): void
    {
        $this->trade(['status' => Trade::STATUS_BOUGHT, 'purchase_price' => 6000, 'sale_price' => null], ['Ремонт' => 700]);
        $this->trade(['purchase_price' => 8000, 'sale_price' => 11000]);

        $summary = app(TradeStats::class)->summary($this->user->id);

        $this->assertSame(6700, $summary['locked_money']);
        $this->assertSame(1, $summary['open']);
    }

    public function test_models_are_ranked_by_profit(): void
    {
        $this->trade(['brand' => 'Apple', 'model' => 'iPhone 13', 'purchase_price' => 8000, 'sale_price' => 11000]);
        $this->trade(['brand' => 'Apple', 'model' => 'iPhone 13', 'purchase_price' => 8500, 'sale_price' => 10500]);
        $this->trade(['brand' => 'Samsung', 'model' => 'S23 Ultra', 'purchase_price' => 9000, 'sale_price' => 15000]);

        $rows = app(TradeStats::class)->byModel($this->user->id);

        $this->assertSame('Samsung S23 Ultra', $rows[0]['label']);
        $this->assertSame(6000, $rows[0]['profit']);
        $this->assertSame('Apple iPhone 13', $rows[1]['label']);
        $this->assertSame(5000, $rows[1]['profit']);
        $this->assertSame(2500, $rows[1]['avg_profit']);
    }

    public function test_months_and_channels_are_grouped(): void
    {
        $this->trade([
            'sale_date' => '2026-07-10',
            'purchase_price' => 8000,
            'sale_price' => 11000,
            'sale_channel' => '999.md',
        ]);
        $this->trade([
            'sale_date' => '2026-07-20',
            'purchase_price' => 5000,
            'sale_price' => 6000,
            'sale_channel' => 'Facebook',
        ]);
        $this->trade([
            'sale_date' => '2026-08-05',
            'purchase_price' => 4000,
            'sale_price' => 5500,
            'sale_channel' => '999.md',
        ]);

        $stats = app(TradeStats::class);

        $months = $stats->byMonth($this->user->id);
        $this->assertSame(['2026-07', '2026-08'], array_column($months, 'month'));
        $this->assertSame(4000, $months[0]['profit']);

        $channels = $stats->byChannel($this->user->id);
        $this->assertSame('999.md', $channels[0]['channel']);
        $this->assertSame(4500, $channels[0]['profit']);
    }

    public function test_period_filter_narrows_the_report(): void
    {
        $this->trade(['sale_date' => '2026-06-01', 'purchase_date' => '2026-05-20', 'purchase_price' => 8000, 'sale_price' => 11000]);
        $this->trade(['sale_date' => '2026-08-15', 'purchase_date' => '2026-08-01', 'purchase_price' => 5000, 'sale_price' => 7000]);

        $summary = app(TradeStats::class)->summary($this->user->id, ['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame(1, $summary['sold']);
        $this->assertSame(2000, $summary['profit']);
    }

    public function test_reports_page_renders_all_sections(): void
    {
        $this->trade(['purchase_price' => 8000, 'sale_price' => 11000, 'sale_channel' => '999.md']);

        $this->actingAs($this->user)
            ->get('/reports')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('reports/index')
                ->has('by_model', 1)
                ->has('by_month', 1)
                ->has('by_channel', 1)
                ->where('summary.profit', 3000)
            );
    }
}
