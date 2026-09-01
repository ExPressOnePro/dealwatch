<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Listing;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.chat_id', '42');
    }

    private function deal(array $listingAttributes = []): Deal
    {
        $listing = Listing::factory()->create($listingAttributes);

        return Deal::factory()->create(['listing_id' => $listing->id]);
    }

    public function test_sends_html_message_and_marks_deal_notified(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $deal = $this->deal();

        $this->assertTrue(app(TelegramNotifier::class)->notifyDeal($deal));
        $this->assertTrue($deal->fresh()->notified);
        $this->assertNotNull($deal->fresh()->notified_at);

        Http::assertSent(function (Request $request) {
            return $request['parse_mode'] === 'HTML'
                && $request['chat_id'] === '42'
                && str_contains($request['text'], 'НОВАЯ СДЕЛКА');
        });
    }

    public function test_special_characters_in_title_do_not_break_the_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        // Подчёркивание ломало legacy-Markdown; угловые скобки ломают HTML, если их не экранировать.
        $deal = $this->deal([
            'title' => 'iPhone 13 <новый> & чехол_в_подарок',
            'brand' => null,
            'model' => null,
            'storage_gb' => null,
        ]);

        app(TelegramNotifier::class)->notifyDeal($deal);

        Http::assertSent(function (Request $request) {
            return str_contains($request['text'], '&lt;новый&gt; &amp; чехол_в_подарок');
        });
    }

    public function test_failed_delivery_leaves_deal_unnotified(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);

        $deal = $this->deal();

        $this->assertFalse(app(TelegramNotifier::class)->notifyDeal($deal));
        $this->assertFalse($deal->fresh()->notified);
    }

    public function test_without_credentials_nothing_is_sent(): void
    {
        config()->set('services.telegram.bot_token', null);
        Http::fake();

        $this->assertFalse(app(TelegramNotifier::class)->notifyDeal($this->deal()));

        Http::assertNothingSent();
    }

    public function test_plain_alert_is_sent_as_text(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->assertTrue(app(TelegramNotifier::class)->notifyText('Сбор пуст 3 прогона подряд'));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Сбор пуст 3 прогона подряд'));
    }
}
