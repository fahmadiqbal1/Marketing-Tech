<?php

namespace App\Agents\Concerns;

use App\Agents\Contracts\AgentOutputContract;

trait ValidatesOutput
{
    protected ?AgentOutputContract $outputContract = null;

    public function withOutputContract(AgentOutputContract $contract): static
    {
        $this->outputContract = $contract;
        return $this;
    }

    protected function assertOutput(string $result): string
    {
        if ($this->outputContract === null) {
            return $result;
        }

        if (! $this->outputContract->validate($result)) {
            $this->fireHook('after_run', null, 'VALIDATION_FAILED');
            throw new \UnexpectedValueException(
                'Agent output failed contract: ' . $this->outputContract->getValidationError()
            );
        }

        return $result;
    }
}
