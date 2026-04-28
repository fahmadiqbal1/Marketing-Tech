<?php

namespace App\Agents\Concerns;

trait HookableTrait
{
    protected array $hooks = [
        'before_run'  => [],
        'after_run'   => [],
        'before_tool' => [],
        'after_tool'  => [],
    ];

    public function on(string $event, callable $callback): static
    {
        $this->hooks[$event][] = $callback;
        return $this;
    }

    protected function fireHook(string $event, mixed ...$args): void
    {
        foreach ($this->hooks[$event] ?? [] as $cb) {
            try {
                $cb(...$args);
            } catch (\Throwable $e) {
                // Hooks must never break agent execution
                \Illuminate\Support\Facades\Log::warning("HookableTrait: hook [{$event}] threw: " . $e->getMessage());
            }
        }
    }
}
