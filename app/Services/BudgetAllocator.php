<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Economic intelligence layer.
 * Tracks per-domain daily AI spend and enforces soft/hard caps.
 * Advisory mode: warns but allows. Enforced mode: blocks.
 */
class BudgetAllocator
{
    private const DOMAIN_DEFAULTS = [
        'campaign' => 2.00,
        'content'  => 1.50,
        'hiring'   => 1.00,
        'growth'   => 0.75,
        'media'    => 1.00,
        'knowledge'=> 0.50,
        'strategic'=> 0.25,
        'default'  => 1.00,
    ];

    /**
     * Check if a domain can execute given estimated cost.
     * Returns true (allow) or false (block) based on mode.
     */
    public static function canExecute(string $domain, float $estimatedCostUsd = 0.01): bool
    {
        if (! config('agents.budget_tracking', true)) {
            return true;
        }

        self::ensureRow($domain);
        self::resetIfNewDay($domain);

        $row = DB::table('budget_allocations')->where('domain', $domain)->first();
        $remaining = $row->daily_budget - $row->used_today;

        if ($remaining < $estimatedCostUsd) {
            Log::warning('BudgetAllocator: budget near limit', [
                'domain'    => $domain,
                'budget'    => $row->daily_budget,
                'used'      => $row->used_today,
                'estimated' => $estimatedCostUsd,
            ]);

            // Hard enforcement only when explicitly enabled
            if (config('agents.budget_enforced', false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record actual spend after an operation completes.
     */
    public static function recordSpend(string $domain, float $actualCostUsd): void
    {
        if (! config('agents.budget_tracking', true)) {
            return;
        }

        self::ensureRow($domain);

        DB::table('budget_allocations')
            ->where('domain', $domain)
            ->increment('used_today', $actualCostUsd);
    }

    /**
     * Update ROI score for a domain (0.0–1.0).
     * Called by StrategicLearningService after outcomes are scored.
     */
    public static function updateRoi(string $domain, float $roiScore): void
    {
        DB::table('budget_allocations')
            ->where('domain', $domain)
            ->update(['roi_score' => $roiScore, 'updated_at' => now()]);
    }

    /**
     * Nightly rebalancing: shift budget toward high-ROI domains.
     */
    public static function rebalance(): void
    {
        $rows = DB::table('budget_allocations')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $totalBudget = $rows->sum('daily_budget');
        $avgRoi      = $rows->avg('roi_score');

        foreach ($rows as $row) {
            $oldBudget = $row->daily_budget;
            if ($row->roi_score > $avgRoi * 1.2) {
                $newBudget = min($oldBudget * 1.15, $oldBudget + 0.50);
            } elseif ($row->roi_score < $avgRoi * 0.6) {
                $newBudget = max($oldBudget * 0.85, 0.10);
            } else {
                $newBudget = $oldBudget;
            }

            if (abs($newBudget - $oldBudget) > 0.01) {
                DB::table('budget_allocations')
                    ->where('id', $row->id)
                    ->update(['daily_budget' => $newBudget, 'updated_at' => now()]);

                Log::info('BudgetAllocator: rebalanced', [
                    'domain'     => $row->domain,
                    'old_budget' => $oldBudget,
                    'new_budget' => $newBudget,
                    'roi_score'  => $row->roi_score,
                ]);
            }
        }
    }

    /**
     * Returns per-domain budget status for the dashboard.
     */
    public static function getStatus(): array
    {
        return DB::table('budget_allocations')->get()->map(fn ($r) => [
            'domain'       => $r->domain,
            'daily_budget' => $r->daily_budget,
            'used_today'   => round($r->used_today, 4),
            'remaining'    => round(max(0, $r->daily_budget - $r->used_today), 4),
            'utilisation'  => $r->daily_budget > 0 ? round($r->used_today / $r->daily_budget * 100, 1) : 0,
            'roi_score'    => round($r->roi_score, 3),
        ])->toArray();
    }

    private static function ensureRow(string $domain): void
    {
        $exists = DB::table('budget_allocations')->where('domain', $domain)->exists();
        if (! $exists) {
            DB::table('budget_allocations')->insert([
                'domain'       => $domain,
                'daily_budget' => self::DOMAIN_DEFAULTS[$domain] ?? self::DOMAIN_DEFAULTS['default'],
                'used_today'   => 0,
                'roi_score'    => 0.5,
                'reset_date'   => today(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    private static function resetIfNewDay(string $domain): void
    {
        $row = DB::table('budget_allocations')->where('domain', $domain)->first();
        if ($row && $row->reset_date !== today()->toDateString()) {
            DB::table('budget_allocations')
                ->where('domain', $domain)
                ->update(['used_today' => 0, 'reset_date' => today(), 'updated_at' => now()]);
        }
    }
}
