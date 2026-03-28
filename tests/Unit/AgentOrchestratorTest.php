<?php

namespace Tests\Unit;

use App\Agents\AgentOrchestrator;
use App\Jobs\RunAgentJob;
use App\Models\AgentJob;
use App\Services\AI\OpenAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AgentOrchestratorTest
 *
 * Covers AgentOrchestrator::dispatch():
 *  - Known agent types create an AgentJob record with correct fields
 *  - RunAgentJob is dispatched to the configured queue
 *  - Unknown agent type throws InvalidArgumentException
 *  - All six valid agent types are accepted
 *
 * dispatchFromTelegram() is NOT tested here because it calls OpenAI for
 * classification; those tests belong in a separate integration suite with
 * a mocked OpenAIService.
 */
class AgentOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private AgentOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake the queue so RunAgentJob::dispatch() does not actually run
        Queue::fake();

        // Mock OpenAIService — the orchestrator takes it as a constructor dep
        // but dispatch() itself never calls it (only classifyInstruction does)
        $openai = $this->createMock(OpenAIService::class);

        $this->orchestrator = new AgentOrchestrator($openai);
    }

    // ─── dispatch() — happy paths ─────────────────────────────────────────────

    /**
     * @dataProvider validAgentTypeProvider
     */
    public function test_dispatch_accepts_all_valid_agent_types(string $agentType): void
    {
        $job = $this->orchestrator->dispatch(
            agentType:   $agentType,
            instruction: 'Do something useful',
            chatId:      100,
            userId:      200,
        );

        $this->assertInstanceOf(AgentJob::class, $job);
        $this->assertEquals($agentType, $job->agent_type);
    }

    public static function validAgentTypeProvider(): array
    {
        return [
            'marketing agent' => ['marketing'],
            'content agent'   => ['content'],
            'media agent'     => ['media'],
            'hiring agent'    => ['hiring'],
            'growth agent'    => ['growth'],
            'knowledge agent' => ['knowledge'],
        ];
    }

    public function test_dispatch_persists_agent_job_to_database(): void
    {
        $instruction = 'Write a compelling email campaign for product launch';

        $job = $this->orchestrator->dispatch(
            agentType:   'marketing',
            instruction: $instruction,
            chatId:      42,
            userId:      99,
        );

        $this->assertDatabaseHas('agent_jobs', [
            'id'          => $job->id,
            'agent_type'  => 'marketing',
            'status'      => 'pending',
            'chat_id'     => 42,
            'user_id'     => 99,
        ]);

        // Instruction stored verbatim
        $this->assertEquals($instruction, AgentJob::find($job->id)->instruction);
    }

    public function test_dispatch_creates_job_with_pending_status(): void
    {
        $job = $this->orchestrator->dispatch(
            agentType:   'content',
            instruction: 'Write a blog post',
            chatId:      1,
            userId:      1,
        );

        $this->assertEquals('pending', $job->status);
    }

    public function test_dispatch_sets_short_description_to_first_80_chars(): void
    {
        $longInstruction = str_repeat('x', 200);

        $job = $this->orchestrator->dispatch(
            agentType:   'content',
            instruction: $longInstruction,
            chatId:      1,
            userId:      1,
        );

        $this->assertEquals(80, mb_strlen($job->short_description));
    }

    public function test_dispatch_assigns_correct_agent_class(): void
    {
        $expectedClasses = [
            'marketing' => \App\Agents\MarketingAgent::class,
            'content'   => \App\Agents\ContentAgent::class,
            'media'     => \App\Agents\MediaAgent::class,
            'hiring'    => \App\Agents\HiringAgent::class,
            'growth'    => \App\Agents\GrowthAgent::class,
            'knowledge' => \App\Agents\KnowledgeAgent::class,
        ];

        foreach ($expectedClasses as $type => $expectedClass) {
            $job = $this->orchestrator->dispatch(
                agentType:   $type,
                instruction: 'test',
                chatId:      1,
                userId:      1,
            );

            $this->assertEquals(
                $expectedClass,
                $job->agent_class,
                "Expected agent_class {$expectedClass} for type {$type}"
            );
        }
    }

    // ─── dispatch() — queue dispatch ─────────────────────────────────────────

    public function test_dispatch_pushes_run_agent_job_onto_queue(): void
    {
        $this->orchestrator->dispatch(
            agentType:   'content',
            instruction: 'Generate ad copy',
            chatId:      1,
            userId:      1,
        );

        Queue::assertPushed(RunAgentJob::class);
    }

    public function test_dispatch_pushes_exactly_one_job_per_call(): void
    {
        $this->orchestrator->dispatch('content', 'First task',  1, 1);
        $this->orchestrator->dispatch('content', 'Second task', 1, 1);

        Queue::assertPushed(RunAgentJob::class, 2);
    }

    public function test_dispatch_pushes_job_to_correct_queue_for_agent_type(): void
    {
        // The queue name for each agent type comes from config('agents.agents.{type}.queue').
        // We test 'content' here — if the config key exists it uses its queue,
        // otherwise falls back to 'default'.
        $this->orchestrator->dispatch(
            agentType:   'content',
            instruction: 'Write something',
            chatId:      1,
            userId:      1,
        );

        $expectedQueue = config('agents.agents.content.queue', 'default');

        Queue::assertPushedOn($expectedQueue, RunAgentJob::class);
    }

    // ─── dispatch() — error cases ─────────────────────────────────────────────

    public function test_dispatch_throws_for_unknown_agent_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown agent type: robot_overlord');

        $this->orchestrator->dispatch(
            agentType:   'robot_overlord',
            instruction: 'Take over the world',
            chatId:      1,
            userId:      1,
        );
    }

    public function test_dispatch_throws_for_empty_agent_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->orchestrator->dispatch(
            agentType:   '',
            instruction: 'Do something',
            chatId:      1,
            userId:      1,
        );
    }

    // ─── Job ID is a valid UUID ───────────────────────────────────────────────

    public function test_dispatched_job_has_uuid_id(): void
    {
        $job = $this->orchestrator->dispatch(
            agentType:   'growth',
            instruction: 'Analyse funnel',
            chatId:      7,
            userId:      7,
        );

        $this->assertTrue(
            Str::isUuid($job->id),
            "Expected job ID to be a UUID, got: {$job->id}"
        );
    }
}
