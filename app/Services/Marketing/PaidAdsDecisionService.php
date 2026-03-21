<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Log;

/**
 * Gate-keeper for paid advertising.
 *
 * Ads ONLY run when ALL three conditions are satisfied:
 *   1. Organic traffic is underperforming (not enough clicks/conversions)
 *   2. Product price is above the minimum viable threshold
 *   3. A conversion rate is known (not null, > 0)
 *
 * This enforces the "organic first, paid last resort" rule at the service layer.
 */
class PaidAdsDecisionService
{
    private const MIN_VIABLE_PRODUCT_PRICE = 10.0; // USD

    public function __construct(
        private readonly PerformanceService $performance,
    ) {}

    /**
     * Returns true ONLY if paid ads are warranted.
     */
    public function canRunAds(int $taskId, float $productPrice, ?float $conversionRate): bool
    {
        $check = $this->evaluate($taskId, $productPrice, $conversionRate);
        return $check['can_run'];
    }

    /**
     * Human-readable explanation of WHY ads were rejected.
     */
    public function getRejectionReason(int $taskId, float $productPrice, ?float $conversionRate): string
    {
        $check = $this->evaluate($taskId, $productPrice, $conversionRate);

        if ($check['can_run']) {
            return 'Ads are approved — all conditions met.';
        }

        return implode(' ', $check['reasons']);
    }

    /**
     * Full evaluation result — useful for logging and debugging.
     *
     * @return array{can_run: bool, conditions: array, reasons: string[]}
     */
    public function evaluate(int $taskId, float $productPrice, ?float $conversionRate): array
    {
        $conditions = [];
        $reasons    = [];

        // ── Condition 1: Organic underperforming ───────────────────────
        $organicWeak = $taskId > 0
            ? $this->performance->organicIsUnderperforming($taskId)
            : false;

        $conditions['organic_underperforming'] = $organicWeak;

        if (! $organicWeak) {
            $reasons[] = 'Organic content is performing well — ads not needed yet.';
        }

        // ── Condition 2: Product price above minimum ───────────────────
        $priceViable = $productPrice >= self::MIN_VIABLE_PRODUCT_PRICE;

        $conditions['product_price_viable'] = $priceViable;

        if (! $priceViable) {
            $reasons[] = "Product price \${$productPrice} is below minimum viable \$" . self::MIN_VIABLE_PRODUCT_PRICE . " for profitable ads.";
        }

        // ── Condition 3: Conversion rate is known ──────────────────────
        $conversionKnown = $conversionRate !== null && $conversionRate > 0;

        $conditions['conversion_rate_known'] = $conversionKnown;

        if (! $conversionKnown) {
            $reasons[] = 'Conversion rate is unknown — run organic first to establish a baseline.';
        }

        $canRun = $organicWeak && $priceViable && $conversionKnown;

        if (! $canRun) {
            Log::info("[PaidAdsDecisionService] Ads blocked for task {$taskId}.", [
                'conditions' => $conditions,
                'reasons'    => $reasons,
            ]);
        }

        return [
            'can_run'    => $canRun,
            'conditions' => $conditions,
            'reasons'    => $reasons,
        ];
    }
}
