<?php

namespace App\Agents\Contracts;

interface AgentOutputContract
{
    public function validate(string $output): bool;
    public function getValidationError(): ?string;
}
