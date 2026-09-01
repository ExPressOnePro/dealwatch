<?php

namespace Tests\Feature\Deals;

use App\Jobs\CollectListings;
use App\Jobs\RefreshAnalytics;
use App\Models\Deal;
use App\Models\IgnoredListing;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DealsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_see_the_feed(): void
    {
        $this->get('/deals')->assertRedirect('/login');
    }

    public function test_feed_counts_only_private_targets(): void
    {
        Deal::factory()->create(['listing_id' => Listing::factory()->create()->id, 'verdict' => 'buy', 'potential_profit' => 2000]);
        Deal::factory()->create(['listing_id' => Listing::factory()->shop()->create()->id, 'verdict' => 'buy', 'potential_profit' => 5000]);
        Deal::factory()->create(['listing_id' => Listing::factory()->reseller()->create()->id, 'verdict' => 'buy', 'potential_profit' => 5000]);
        Deal::factory()->create(['listing_id' => Listing::factory()->wantBuy()->create()->id, 'verdict' => 'ignore', 'potential_profit' => null]);

        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('deals/index')
                ->where('stats.buy', 1)
                ->where('stats.total', 1)
                ->where('stats.shop_deals', 1)
                ->where('stats.reseller_deals', 1)
                ->where('stats.want_buy_deals', 1)
                ->where('stats.profit_sum', 2000)
                ->has('runs')
            );
    }

    public function test_closed_deals_leave_the_active_feed(): void
    {
        $open = Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);
        Deal::factory()->create([
            'listing_id' => Listing::factory()->create()->id,
            'user_status' => Deal::STATUS_COMPLETED,
        ]);

        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('deals', 1)
                ->where('deals.0.id', $open->id)
            );
    }

    public function test_completed_status_is_accepted_by_the_status_endpoint(): void
    {
        $deal = Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);

        $this->actingAs($this->user)
            ->patch("/deals/{$deal->id}", ['user_status' => Deal::STATUS_COMPLETED])
            ->assertRedirect();

        $this->assertSame(Deal::STATUS_COMPLETED, $deal->fresh()->user_status);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $deal = Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);

        $this->actingAs($this->user)
            ->patch("/deals/{$deal->id}", ['user_status' => 'whatever'])
            ->assertSessionHasErrors('user_status');
    }

    public function test_dismissing_a_deal_remembers_the_listing(): void
    {
        $listing = Listing::factory()->create();
        $deal = Deal::factory()->create(['listing_id' => $listing->id]);

        $this->actingAs($this->user)->patch("/deals/{$deal->id}", ['user_status' => Deal::STATUS_DISMISSED]);
        $this->assertTrue(IgnoredListing::isIgnored('999', (string) $listing->external_id));

        $this->actingAs($this->user)->patch("/deals/{$deal->id}", ['user_status' => Deal::STATUS_NEW]);
        $this->assertFalse(IgnoredListing::isIgnored('999', (string) $listing->external_id));
    }

    public function test_segment_filter_selects_the_right_listings(): void
    {
        $private = Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);
        $shop = Deal::factory()->create(['listing_id' => Listing::factory()->shop()->create()->id]);
        $wantBuy = Deal::factory()->create(['listing_id' => Listing::factory()->wantBuy()->create()->id]);
        $reseller = Deal::factory()->create(['listing_id' => Listing::factory()->reseller()->create()->id]);

        $expectations = [
            'targets' => $private->id,
            'shops' => $shop->id,
            'want_buy' => $wantBuy->id,
            'resellers' => $reseller->id,
        ];

        foreach ($expectations as $segment => $expectedId) {
            $this->actingAs($this->user)
                ->get('/deals?segment='.$segment)
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('deals', 1)
                    ->where('deals.0.id', $expectedId)
                );
        }
    }

    public function test_model_and_verdict_filters_narrow_the_feed(): void
    {
        $target = Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['brand' => 'Apple', 'model' => 'iPhone 13'])->id,
            'verdict' => 'buy',
        ]);
        Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['brand' => 'Samsung', 'model' => 'S23 Ultra'])->id,
            'verdict' => 'buy',
        ]);
        Deal::factory()->create([
            'listing_id' => Listing::factory()->create(['brand' => 'Apple', 'model' => 'iPhone 13'])->id,
            'verdict' => 'check',
        ]);

        $this->actingAs($this->user)
            ->get('/deals?model='.urlencode('Apple|iPhone 13').'&verdict=buy')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('deals', 1)
                ->where('deals.0.id', $target->id)
                ->has('models', 2)
            );
    }

    public function test_hidden_listing_moves_to_the_dismissed_tab(): void
    {
        $listing = Listing::factory()->create();
        $deal = Deal::factory()->create(['listing_id' => $listing->id]);
        IgnoredListing::remember('999', (string) $listing->external_id);

        $this->actingAs($this->user)
            ->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('deals', 0));

        $this->actingAs($this->user)
            ->get('/deals?status=dismissed')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('deals', 1)
                ->where('deals.0.id', $deal->id)
            );
    }

    public function test_sorting_by_model_does_not_duplicate_rows(): void
    {
        Deal::factory()->count(3)->sequence(
            ['listing_id' => Listing::factory()->create(['model' => 'iPhone 13'])->id],
            ['listing_id' => Listing::factory()->create(['model' => 'iPhone 12'])->id],
            ['listing_id' => Listing::factory()->create(['model' => 'iPhone 14 Pro'])->id],
        )->create();

        $this->actingAs($this->user)
            ->get('/deals?sort=model')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('deals', 3));
    }

    public function test_collect_is_queued_instead_of_running_in_the_request(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post('/deals/collect')
            ->assertRedirect();

        Queue::assertPushed(CollectListings::class);
    }

    public function test_analytics_refresh_is_queued(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post('/deals/refresh-analytics')
            ->assertRedirect();

        Queue::assertPushed(RefreshAnalytics::class);
    }

    public function test_import_rejects_a_foreign_url(): void
    {
        $this->actingAs($this->user)
            ->post('/deals/import', ['url' => 'https://example.com/12345678'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
