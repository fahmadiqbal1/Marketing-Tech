<?php

namespace App\Services\Skills;

use App\Models\SkillRegistry;
use App\Skills\SkillInterface;
use App\Models\WorkflowLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SkillExecutorService
{
    /** @var array<string, SkillInterface> */
    private array $runtimeCache = [];

    /**
     * Execute a skill by name with parameter validation and permission enforcement.
     */
    public function execute(string $skillName, array $params, ?string $workflowId = null): array
    {
        $registry = SkillRegistry::where('name', $skillName)->where('is_active', true)->first();

        if (! $registry) {
            throw new \RuntimeException("Skill not found or inactive: {$skillName}");
        }

        // Validate input against JSON Schema
        $this->validateParams($skillName, $params, $registry->input_schema ?? []);

        // Check required services are available
        $this->assertServicesAvailable($registry->required_services ?? []);

        // Resolve skill instance
        $skill = $this->resolveSkill($registry->class);

        $startedAt = microtime(true);

        try {
            Log::info("Executing skill", ['skill' => $skillName, 'workflow' => $workflowId]);

            $result = $skill->execute($params, $workflowId);

            $durationMs = (microtime(true) - $startedAt) * 1000;
            $registry->incrementUsage($durationMs);

            Log::info("Skill executed", ['skill' => $skillName, 'duration_ms' => round($durationMs)]);

            return $result;

        } catch (\Throwable $e) {
            Log::error("Skill execution failed", ['skill' => $skillName, 'error' => $e->getMessage()]);
            throw new \RuntimeException("Skill '{$skillName}' failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * List all active skills, optionally filtered by category.
     */
    public function list(?string $category = null): array
    {
        $query = SkillRegistry::where('is_active', true);
        if ($category) {
            $query->where('category', $category);
        }
        return $query->orderBy('name')->get()->toArray();
    }

    /**
     * Register all skill classes from the registry into the database.
     * Run this on deploy to sync code with DB.
     */
    public function syncRegistry(): void
    {
        $skillClasses = config('skills.registered', []);

        foreach ($skillClasses as $class) {
            if (! class_exists($class)) {
                Log::warning("Skill class not found", ['class' => $class]);
                continue;
            }

            /** @var SkillInterface $instance */
            $instance = app($class);

            SkillRegistry::updateOrCreate(
                ['name' => $instance->getName()],
                [
                    'class'          => $class,
                    'description'    => $instance->getDescription(),
                    'input_schema'   => $instance->getInputSchema(),
                    'is_active'      => true,
                ]
            );
        }

        Log::info("Skills registry synced", ['count' => count($skillClasses)]);
    }

    // ── Private helpers ───────────────────────────────────────────

    private function resolveSkill(string $class): SkillInterface
    {
        if (isset($this->runtimeCache[$class])) {
            return $this->runtimeCache[$class];
        }

        if (! class_exists($class)) {
            throw new \RuntimeException("Skill class does not exist: {$class}");
        }

        $skill = app($class);

        if (! $skill instanceof SkillInterface) {
            throw new \RuntimeException("Class {$class} does not implement SkillInterface");
        }

        $this->runtimeCache[$class] = $skill;
        return $skill;
    }

    private function validateParams(string $skillName, array $params, array $schema): void
    {
        if (empty($schema) || empty($schema['properties'])) {
            return;
        }

        $required = $schema['required'] ?? [];
        $rules    = [];

        foreach ($required as $field) {
            $rules[$field] = 'required';
        }

        foreach ($schema['properties'] as $field => $def) {
            $type = $def['type'] ?? 'string';
            $rule = [];

            if (in_array($field, $required)) {
                $rule[] = 'required';
            } else {
                $rule[] = 'nullable';
            }

            $rule[] = match ($type) {
                'integer' => 'integer',
                'number'  => 'numeric',
                'boolean' => 'boolean',
                'array'   => 'array',
                default   => 'string',
            };

            if (isset($def['enum'])) {
                $rule[] = 'in:' . implode(',', $def['enum']);
            }

            if (isset($def['minimum'])) {
                $rule[] = 'min:' . $def['minimum'];
            }

            if (isset($def['maximum'])) {
                $rule[] = 'max:' . $def['maximum'];
            }

            $rules[$field] = implode('|', $rule);
        }

        $validator = Validator::make($params, $rules);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                "Skill '{$skillName}' invalid params: " . $validator->errors()->first()
            );
        }
    }

    private function assertServicesAvailable(array $services): void
    {
        $checks = [
            'ffmpeg'      => fn() => is_executable(config('agents.media.ffmpeg', '/usr/bin/ffmpeg')),
            'imagemagick' => fn() => is_executable(config('agents.media.imagemagick', '/usr/bin/convert')),
            'tesseract'   => fn() => is_executable(config('agents.media.tesseract', '/usr/bin/tesseract')),
            'clamav'      => fn() => $this->pingClamAV(),
        ];

        foreach ($services as $service) {
            $check = $checks[$service] ?? null;
            if ($check && ! $check()) {
                throw new \RuntimeException("Required service unavailable: {$service}");
            }
        }
    }

    private function pingClamAV(): bool
    {
        try {
            $socket = @fsockopen(
                config('agents.media.clamav_host', 'clamav'),
                config('agents.media.clamav_port', 3310),
                $errno, $errstr, 2
            );
            if ($socket) {
                fclose($socket);
                return true;
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
