<?php

namespace App\Skills;

interface SkillInterface
{
    /**
     * Unique snake_case skill name matching skills_registry.
     */
    public function getName(): string;

    /**
     * Execute the skill with validated input parameters.
     * Must return an array result.
     */
    public function execute(array $params, ?string $workflowId = null): array;

    /**
     * Return JSON Schema for input validation.
     */
    public function getInputSchema(): array;

    /**
     * Human-readable description.
     */
    public function getDescription(): string;
}
