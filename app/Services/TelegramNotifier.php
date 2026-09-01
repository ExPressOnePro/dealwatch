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

        $priceLine = 'Цена: <b>'.$this->escape($listing->formattedOriginalPrice()).'</b>';
        if ($listing->currency && $listing->currency !== 'MDL') {
            $priceLine .= ' ≈ '.$this->escape(number_format((int) $listing->priceForScoring(), 0, '.', ' ').' MDL');
        }

        $text = implode("\n", array_filter([
            $emoji.' <b>НОВАЯ СДЕЛКА</b> · score <b>'.(int) $deal->deal_score.'/100</b>',
            '',
            '<b>'.$this->escape($listing->displayName()).'</b>',
            $priceLine,
            'Рынок: '.$this->escape(number_format((int) $deal->market_price, 0, '.', ' ')).' MDL',
            'Дисконт: '.$this->escape((string) round((float) $deal->discount_percent, 1)).'%',
            'Потенциал: <b>+'.$this->escape(number_format((int) $deal->potential_profit, 0, '.', ' ')).' MDL</b>',
            'Свежесть: '.$this->escape((string) $deal->freshness),
            $listing->location ? 'Город: '.$this->escape($listing->location) : null,
            $listing->seller_phone ? 'Тел: <code>'.$this->escape($listing->seller_phone).'</code>' : null,
            '',
            '<a href="'.$this->escape($listing->url).'">Открыть объявление</a>',
        ]));

        $sent = $this->send($text, [
            'inline_keyboard' => [[['text' => 'Открыть', 'url' => $listing->url]]],
        ]);

        if (! $sent) {
            return false;
        }

        $deal->update([
            'notified' => true,
            'notified_at' => now(),
        ]);

        return true;
    }

    /**
     * Plain operational message (integration broken, collector silent, …).
     */
    public function notifyText(string $text): bool
    {
        if (! $this->configured()) {
            Log::info('Telegram not configured, skip alert');

            return false;
        }

        return $this->send($this->escape($text));
    }

    /**
     * @param  array<string, mixed>|null  $keyboard
     */
    private function send(string $html, ?array $keyboard = null): bool
    {
        $payload = [
            'chat_id' => config('services.telegram.chat_id'),
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = $keyboard;
        }

        $response = Http::asJson()->post(
            'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
            $payload
        );

        if (! $response->successful()) {
            Log::warning('Telegram notify failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Telegram HTML mode only reserves &, < and > — safer than escaping
     * a dozen Markdown metacharacters by hand.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
