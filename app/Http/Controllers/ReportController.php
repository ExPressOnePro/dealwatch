<?php

namespace App\Http\Controllers;

use App\Services\TradeStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly TradeStats $stats,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        $filters = array_filter([
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
        ]);

        return Inertia::render('reports/index', [
            'summary' => $this->stats->summary($userId, $filters),
            'by_model' => $this->stats->byModel($userId, $filters),
            'by_month' => $this->stats->byMonth($userId, $filters),
            'by_channel' => $this->stats->byChannel($userId, $filters),
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
        ]);
    }
}
