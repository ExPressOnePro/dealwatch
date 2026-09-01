<?php

namespace Tests\Feature\Trades;

use App\Jobs\ArchiveListing;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TradeJournalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_trade_can_be_started_from_a_deal_and_archives_the_listing(): void
    {
        Queue::fake();

        $listing = Listing::factory()->create(['price_mdl' => 8200]);
        $deal = Deal::factory()->create(['listing_id' => $listing->id]);

        $this->actingAs($this->user)
            ->post('/trades', ['deal_id' => $deal->id])
            ->assertRedirect(route('trades.index'))
            ->assertSessionHas('success');

        $trade = Trade::sole();
        $this->assertSame($this->user->id, $trade->user_id);
        $this->assertSame($listing->id, $trade->listing_id);
        $this->assertSame('Apple iPhone 13 128 GB', $trade->title);
        $this->assertSame(8200, $trade->purchase_price);
        $this->assertSame(Trade::STATUS_BOUGHT, $trade->status);

        Queue::assertPushed(ArchiveListing::class, fn ($job) => $job->listingId === $listing->id);
    }

    public function test_trade_can_be_added_by_hand(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/trades', [
            'title' => 'Samsung S23 Ultra 256',
            'brand' => 'Samsung',
            'model' => 'S23 Ultra',
            'purchase_price' => 9000,
        ])->assertRedirect();

        $trade = Trade::sole();
        $this->assertSame('Samsung S23 Ultra 256', $trade->title);
        $this->assertSame(9000, $trade->purchase_price);
        Queue::assertNothingPushed();
    }

    public function test_trade_without_title_or_deal_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post('/trades', [])
            ->assertSessionHas('error');

        $this->assertSame(0, Trade::query()->count());
    }

    public function test_profit_accounts_for_extra_expenses(): void
    {
        $trade = Trade::factory()->open()->create(['user_id' => $this->user->id, 'purchase_price' => 8000]);

        $this->actingAs($this->user)->patch("/trades/{$trade->id}", [
            'title' => $trade->title,
            'status' => Trade::STATUS_SOLD,
            'purchase_price' => 8000,
            'purchase_date' => now()->subDays(10)->toDateString(),
            'sale_price' => 11000,
            'sale_date' => now()->toDateString(),
            'sale_channel' => 'Facebook',
            'expenses' => [
                ['label' => 'Замена стекла', 'amount' => 400],
                ['label' => 'Дорога', 'amount' => 100],
            ],
        ])->assertRedirect();

        $trade->refresh()->load('expenses');
        $this->assertSame(500, $trade->expensesTotal());
        $this->assertSame(8500, $trade->totalCost());
        $this->assertSame(2500, $trade->netProfit());
        $this->assertSame(29.4, $trade->roiPercent());
        $this->assertSame(10, $trade->holdDays());
    }

    public function test_expenses_are_replaced_not_duplicated(): void
    {
        $trade = Trade::factory()->create(['user_id' => $this->user->id]);
        $trade->expenses()->create(['label' => 'Старый расход', 'amount' => 999]);

        $this->actingAs($this->user)->patch("/trades/{$trade->id}", [
            'title' => $trade->title,
            'status' => Trade::STATUS_SOLD,
            'expenses' => [['label' => 'Плёнка', 'amount' => 150]],
        ]);

        $expenses = $trade->fresh()->expenses;
        $this->assertCount(1, $expenses);
        $this->assertSame('Плёнка', $expenses[0]->label);
    }

    public function test_other_users_trade_is_protected(): void
    {
        $trade = Trade::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->user)
            ->patch("/trades/{$trade->id}", ['title' => 'Взлом', 'status' => Trade::STATUS_SOLD])
            ->assertForbidden();

        $this->actingAs($this->user)->delete("/trades/{$trade->id}")->assertForbidden();
    }

    public function test_journal_shows_only_my_trades_with_totals(): void
    {
        Trade::factory()->count(2)->create(['user_id' => $this->user->id]);
        Trade::factory()->open()->create(['user_id' => $this->user->id]);
        Trade::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->user)
            ->get('/trades')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('trades/index')
                ->has('trades', 3)
                ->where('summary.sold', 2)
                ->where('summary.open', 1)
                ->where('summary.turnover', 22000)
                ->where('summary.profit', 6000)
            );
    }

    public function test_trade_can_be_deleted(): void
    {
        $trade = Trade::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->delete("/trades/{$trade->id}")->assertRedirect();

        $this->assertSame(0, Trade::query()->count());
    }

    public function test_guests_have_no_journal(): void
    {
        $this->get('/trades')->assertRedirect('/login');
    }
}
