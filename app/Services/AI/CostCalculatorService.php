<?php

namespace App\Services\AI;

/**
 * Single source of truth for AI model pricing.
 * Used by both AIRouter and AIGateway to avoid cost-logic drift.
 *
 * All rates are per 1,000,000 tokens (input_rate, output_rate).
 */
class CostCalculatorService
{
    /** @var array<string, array{0: float, 1: float}> [model => [input_$/1M, output_$/1M]] */
    private array $priceTable = [
        // OpenAI
        'gpt-4o'                     => [2.50,   10.00],
        'gpt-4o-mini'                => [0.15,    0.60],
        'gpt-4-turbo'                => [10.00,  30.00],
        'o1'                         => [15.00,  60.00],
        'o1-mini'                    => [3.00,   12.00],
        'text-embedding-3-large'     => [0.13,    0.00],
        'text-embedding-3-small'     => [0.02,    0.00],
        // Anthropic
        'claude-opus-4-5'            => [15.00,  75.00],
        'claude-opus-4-6'            => [15.00,  75.00],
        'claude-sonnet-4-6'          => [3.00,   15.00],
        'claude-haiku-4-5-20251001'  => [0.25,    1.25],
        // Google Gemini
        'gemini-1.5-flash'           => [0.075,   0.30],
        'gemini-1.5-pro'             => [3.50,   10.50],
        'gemini-2.0-flash'           => [0.10,    0.40],
    ];

    /**
     * Calculate cost in USD for a given model and token counts.
     */
    public function calculate(string $provider, string $model, int $tokensIn, int $tokensOut): float
    {
        [$inRate, $outRate] = $this->priceTable[$model] ?? $this->defaultRate($provider);

        return ($tokensIn * $inRate + $tokensOut * $outRate) / 1_000_000;
    }

    /**
     * Return the full pricing table (for inspection / admin UI).
     */
    public function getPriceTable(): array
    {
        return $this->priceTable;
    }

    /**
     * Conservative default rates when a model is not in the table.
     * @return array{0: float, 1: float}
     */
    private function defaultRate(string $provider): array
    {
        return match ($provider) {
            'anthropic' => [3.00, 15.00],
            'gemini'    => [0.10,  0.40],
            default     => [1.00,  3.00],
        };
    }
}
