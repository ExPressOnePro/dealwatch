<?php

namespace App\Services;

use App\Models\Deal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.chat_id'));
    }

    public function notifyDeal(Deal $deal): bool
    {
        if (! $this->configured()) {
            Log::info('Telegram not configured, skip notify', ['deal_id' => $deal->id]);

            return false;
        }

        $deal->loadMissing('listing');
        $listing = $deal->listing;

        $emoji = match ($deal->verdict) {
            'buy' => '🔥',
            'check' => '🟡',
            default => '⚪',
        };

        $text = implode("\n", array_filter([
            "{$emoji} *НОВАЯ СДЕЛКА* · score *{$deal->deal_score}/100*",
            '',
            '*'.$this->escape($listing->displayName()).'*',
            'Цена: *'.$listing->formattedOriginalPrice().'*'
                .(($listing->currency && $listing->currency !== 'MDL')
                    ? ' ≈ '.number_format((int) $listing->priceForScoring(), 0, '.', ' ').' MDL'
                    : ''),
            'Рынок: '.number_format((int) $deal->market_price, 0, '.', ' ').' MDL',
            'Дисконт: '.round((float) $deal->discount_percent, 1).'%',
            'Потенциал: *+'.number_format((int) $deal->potential_profit, 0, '.', ' ').' MDL*',
            'Свежесть: '.$deal->freshness,
            $listing->location ? 'Город: '.$this->escape($listing->location) : null,
            $listing->seller_phone ? 'Тел: `'.$listing->seller_phone.'`' : null,
            '',
            '[Открыть объявление]('.$listing->url.')',
        ]));

        $row = [['text' => 'Открыть', 'url' => $listing->url]];
        $keyboard = ['inline_keyboard' => [$row]];

        $response = Http::asJson()->post(
            'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
            [
                'chat_id' => config('services.telegram.chat_id'),
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => false,
                'reply_markup' => $keyboard,
            ]
        );

        if (! $response->successful()) {
            Log::warning('Telegram notify failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        $deal->update([
            'notified' => true,
            'notified_at' => now(),
        ]);

        return true;
    }

    private function escape(string $value): string
    {
        return str_replace(['_', '*', '[', ']', '(', ')', '`'], ['\\_', '\\*', '\\[', '\\]', '\\(', '\\)', '\\`'], $value);
    }
}
