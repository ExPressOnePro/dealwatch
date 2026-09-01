// Формы данных, которые отдаёт DealController::index.

export type Valuation = {
    condition_score: number;
    condition_label: string;
    valuation_confidence: string;
    valuation_confidence_note: string;
    market_mid_clean: number;
    sell_low_clean: number;
    sell_high_clean: number;
    condition_haircut_percent: number;
    negotiation_percent: number;
    fair_ask: number;
    expected_sale: number;
    quick_sale: number;
    optimistic_sale: number;
    penalties: Array<{ code: string; label: string; percent: number }>;
    max_buy_for_profit: number;
    profit_at_expected: number | null;
    profit_at_quick: number | null;
    summary: string;
};

export type AnalystReport = {
    known?: string[];
    ask?: string[];
    risks?: Array<{ label: string; probability: number; detail: string }>;
    sms?: string;
    battery_from_text?: number | null;
    valuation?: Valuation;
    brief?: {
        headline?: string;
        model?: {
            label: string;
            title: string;
            site_model?: string | null;
            source?: string | null;
            confidence?: number | null;
            storage_gb?: number | null;
        };
        price_alerts?: string[];
        text_alerts?: string[];
        seller_facts?: string[];
        general?: string[];
        risk_level?: string;
        flags?: string[];
        valuation?: {
            headline?: string;
            expected_sale?: number;
            condition_score?: number;
            condition_label?: string;
        };
    };
    notes?: string[];
};

export type AiDefect = {
    source: 'text' | 'photo';
    label: string;
    severity: 'low' | 'medium' | 'high';
    evidence?: string | null;
    price_impact_mdl?: number | null;
};

export type AiListingReport = {
    id: number;
    kind: 'text' | 'vision';
    status: 'running' | 'done' | 'failed';
    model?: string | null;
    verdict?: 'take' | 'check' | 'skip' | null;
    condition_score?: number | null;
    target_price_mdl?: number | null;
    summary?: string | null;
    defects: AiDefect[];
    mismatches: string[];
    questions: string[];
    checks_on_meeting: string[];
    photo_notes: string[];
    confidence?: string | null;
    images_analyzed?: number;
    cost_usd?: number | null;
    error?: string | null;
    created_at?: string | null;
};

export type AiListingReports = {
    text?: AiListingReport | null;
    vision?: AiListingReport | null;
};

export type DealRow = {
    id: number;
    deal_score: number;
    verdict: 'buy' | 'check' | 'ignore';
    freshness: string;
    discount_percent: number | null;
    potential_profit: number | null;
    market_price: number | null;
    liquidity: number | null;
    user_status: string;
    is_favorite?: boolean;
    notified: boolean;
    suspicious?: boolean;
    subject?: string | null;
    subject_label?: string | null;
    staleness?: 'fresh' | 'suspect' | 'dead' | null;
    listing_age_days?: number | null;
    stale_note?: string | null;
    is_bait?: boolean;
    is_reseller?: boolean;
    listing_kind?: string;
    seller_listings_count?: number;
    analyst_risk?: string | null;
    analyst_comment?: string | null;
    analyst_flags?: string[];
    analyst_report?: AnalystReport | null;
    sms_text?: string | null;
    valuation?: Valuation | null;
    ai_reports?: AiListingReports;
    market_mid_clean?: number | null;
    price_zones?: {
        total_private: number;
        total_shop: number;
        private_median: number | null;
        buy_min: number;
        buy_max: number;
        sell_low: number;
        sell_high: number;
        mid: number;
        zones: Array<{
            key: string;
            short_label: string;
            from: number | null;
            to: number | null;
            tone: string;
            all: number;
            private: number;
            shop: number;
        }>;
        ask_zone: string | null;
        ask_price: number | null;
    } | null;
    market?: {
        id: number;
        sell_low: number;
        sell_high: number;
        buy_max: number;
        buy_min?: number;
        anchor?: string | null;
        buy_rule?: string | null;
        rationale?: string | null;
        foundation: string;
        calc?: string | null;
    } | null;
    listing: {
        id: number;
        external_id?: string;
        title: string;
        description?: string | null;
        display_name: string;
        brand?: string | null;
        model?: string | null;
        storage_gb?: number | null;
        price_original?: number | null;
        price_mdl?: number | null;
        currency?: string | null;
        url: string;
        location: string | null;
        seller_phone: string | null;
        seller_type: string | null;
        platform: string;
        battery_health: number | null;
        published_at: string | null;
        first_seen_at: string | null;
        analyst_comment?: string | null;
        is_bait?: boolean;
        is_reseller?: boolean;
        listing_kind?: string;
        seller_listings_count?: number;
        analyst_risk?: string | null;
        analyst_flags?: string[];
        analyst_report?: AnalystReport | null;
    };
};

export type ModelOption = { key: string; label: string; count: number };

export type PipelineRun = {
    key?: string;
    state: 'queued' | 'running' | 'done' | 'failed';
    message: string;
    stats?: Record<string, unknown>;
    queued_at?: string;
    started_at?: string;
    finished_at?: string;
} | null;

export type PipelineRuns = {
    collect?: PipelineRun;
    analytics?: PipelineRun;
};

export type AiAnalysisItem = {
    deal_id: number;
    title?: string | null;
    url?: string | null;
    price_mdl?: number | null;
    engine_score?: number | null;
    engine_verdict?: string | null;
    ai_verdict: 'take' | 'check' | 'skip';
    rank: number;
    risk?: string | null;
    reason?: string | null;
    call_priority?: number | null;
    target_price_mdl?: number | null;
    reasoning?: string | null;
    questions?: string[];
    red_flags?: string[];
};

export type AiAnalysis = {
    id: number;
    status: 'running' | 'done' | 'failed';
    source: 'filter' | 'query';
    query?: string | null;
    listing_count: number;
    summary?: string | null;
    recommendation?: string | null;
    items: AiAnalysisItem[];
    cost_usd?: number | null;
    model_screen?: string | null;
    model_deep?: string | null;
    error?: string | null;
    created_at?: string | null;
};
