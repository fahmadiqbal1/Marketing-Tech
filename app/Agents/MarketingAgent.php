<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\Campaign;
use App\Services\Marketing\CampaignService;
use Illuminate\Support\Facades\Log;

class MarketingAgent extends BaseAgent
{
    protected string $agentType = 'marketing';

    public function __construct(
        \App\Services\AI\OpenAIService       $openai,
        \App\Services\AI\AnthropicService    $anthropic,
        \App\Services\Telegram\TelegramBotService $telegram,
        \App\Services\Knowledge\VectorStoreService $knowledge,
        private readonly CampaignService     $campaigns,
    ) {
        parent::__construct($openai, $anthropic, $telegram, $knowledge);
    }

    protected function executeTool(string $name, array $args, AgentJob $job): mixed
    {
        return match ($name) {
            'create_campaign'     => $this->toolCreateCampaign($args, $job),
            'get_campaign_stats'  => $this->toolGetCampaignStats($args),
            'schedule_email'      => $this->toolScheduleEmail($args, $job),
            'generate_ad_copy'    => $this->toolGenerateAdCopy($args),
            'analyze_funnel'      => $this->toolAnalyzeFunnel($args),
            'list_campaigns'      => $this->toolListCampaigns($args),
            'pause_campaign'      => $this->toolPauseCampaign($args),
            default               => $this->toolResult(false, null, "Unknown tool: {$name}"),
        };
    }

    protected function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_campaign',
                    'description' => 'Create a new marketing campaign (email, social, or ads)',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'name'        => ['type' => 'string', 'description' => 'Campaign name'],
                            'type'        => ['type' => 'string', 'enum' => ['email', 'social', 'meta_ads', 'google_ads'], 'description' => 'Campaign type'],
                            'subject'     => ['type' => 'string', 'description' => 'Email subject or ad headline'],
                            'body'        => ['type' => 'string', 'description' => 'Email body or ad copy (HTML for email)'],
                            'audience'    => ['type' => 'string', 'description' => 'Target audience segment description'],
                            'schedule_at' => ['type' => 'string', 'description' => 'ISO 8601 datetime to schedule send, or "now"'],
                            'ab_variants' => [
                                'type'        => 'array',
                                'description' => 'Optional A/B test variants with different subjects',
                                'items'       => ['type' => 'string'],
                            ],
                        ],
                        'required'   => ['name', 'type', 'subject', 'body'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_campaign_stats',
                    'description' => 'Retrieve performance statistics for one or more campaigns',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'campaign_id' => ['type' => 'string', 'description' => 'Specific campaign ID, or "all" for aggregate'],
                            'date_from'   => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                            'date_to'     => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                        ],
                        'required'   => ['campaign_id'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'schedule_email',
                    'description' => 'Schedule an email send to a list or segment',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'campaign_id' => ['type' => 'string'],
                            'list_id'     => ['type' => 'string', 'description' => 'Mailing list ID or segment name'],
                            'send_at'     => ['type' => 'string', 'description' => 'ISO 8601 datetime'],
                            'timezone'    => ['type' => 'string', 'description' => 'e.g. UTC, America/New_York'],
                        ],
                        'required'   => ['campaign_id', 'list_id', 'send_at'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_ad_copy',
                    'description' => 'Generate ad copy variants for Meta (Facebook/Instagram) or Google Ads',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'platform'    => ['type' => 'string', 'enum' => ['meta', 'google', 'twitter', 'linkedin']],
                            'product'     => ['type' => 'string', 'description' => 'Product or service name'],
                            'usp'         => ['type' => 'string', 'description' => 'Unique selling proposition'],
                            'cta'         => ['type' => 'string', 'description' => 'Call to action text'],
                            'tone'        => ['type' => 'string', 'enum' => ['professional', 'casual', 'urgent', 'playful']],
                            'count'       => ['type' => 'integer', 'description' => 'Number of variants to generate (1-5)'],
                        ],
                        'required'   => ['platform', 'product', 'usp'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'analyze_funnel',
                    'description' => 'Analyse the conversion funnel and identify drop-off points',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'funnel_name' => ['type' => 'string', 'description' => 'Funnel identifier (e.g. signup, purchase, onboarding)'],
                            'date_from'   => ['type' => 'string'],
                            'date_to'     => ['type' => 'string'],
                            'segment'     => ['type' => 'string', 'description' => 'Optional audience segment filter'],
                        ],
                        'required'   => ['funnel_name'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'list_campaigns',
                    'description' => 'List recent campaigns with their status',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['all', 'draft', 'scheduled', 'sent', 'active']],
                            'limit'  => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'pause_campaign',
                    'description' => 'Pause an active campaign',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'campaign_id' => ['type' => 'string'],
                        ],
                        'required'   => ['campaign_id'],
                    ],
                ],
            ],
        ];
    }

    // ─── Tool Implementations ─────────────────────────────────────

    private function toolCreateCampaign(array $args, AgentJob $job): string
    {
        try {
            $campaign = Campaign::create([
                'name'        => $args['name'],
                'type'        => $args['type'],
                'subject'     => $args['subject'],
                'body'        => $args['body'],
                'audience'    => $args['audience'] ?? 'all',
                'status'      => isset($args['schedule_at']) ? 'scheduled' : 'draft',
                'schedule_at' => isset($args['schedule_at']) && $args['schedule_at'] !== 'now'
                    ? \Carbon\Carbon::parse($args['schedule_at'])
                    : ($args['schedule_at'] === 'now' ? now() : null),
                'ab_variants' => $args['ab_variants'] ?? [],
                'agent_job_id' => $job->id,
                'created_by_agent' => true,
            ]);

            // If schedule_at is now, dispatch immediately
            if (isset($args['schedule_at']) && $args['schedule_at'] === 'now') {
                $this->campaigns->sendCampaign($campaign);
            }

            return $this->toolResult(true, [
                'campaign_id'  => $campaign->id,
                'name'         => $campaign->name,
                'status'       => $campaign->status,
                'schedule_at'  => $campaign->schedule_at?->toIso8601String(),
                'ab_variants'  => count($campaign->ab_variants),
            ]);
        } catch (\Throwable $e) {
            Log::error("Create campaign failed", ['error' => $e->getMessage()]);
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGetCampaignStats(array $args): string
    {
        try {
            if ($args['campaign_id'] === 'all') {
                $stats = $this->campaigns->getAggregateStats(
                    from: $args['date_from'] ?? now()->subDays(30)->toDateString(),
                    to:   $args['date_to']   ?? now()->toDateString(),
                );
            } else {
                $campaign = Campaign::findOrFail($args['campaign_id']);
                $stats    = $this->campaigns->getCampaignStats($campaign);
            }

            return $this->toolResult(true, $stats);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolScheduleEmail(array $args, AgentJob $job): string
    {
        try {
            $campaign = Campaign::findOrFail($args['campaign_id']);
            $sendAt   = \Carbon\Carbon::parse($args['send_at'], $args['timezone'] ?? 'UTC');

            $campaign->update([
                'status'      => 'scheduled',
                'schedule_at' => $sendAt->utc(),
                'list_id'     => $args['list_id'],
            ]);

            return $this->toolResult(true, [
                'campaign_id' => $campaign->id,
                'scheduled'   => $sendAt->utc()->toIso8601String(),
                'list_id'     => $args['list_id'],
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGenerateAdCopy(array $args): string
    {
        $count    = min((int) ($args['count'] ?? 3), 5);
        $platform = $args['platform'];
        $tone     = $args['tone'] ?? 'professional';

        $platformLimits = [
            'meta'     => ['headline' => 40, 'body' => 125],
            'google'   => ['headline' => 30, 'body' => 90],
            'twitter'  => ['headline' => 0,  'body' => 280],
            'linkedin' => ['headline' => 50, 'body' => 150],
        ];

        $limits = $platformLimits[$platform] ?? $platformLimits['meta'];

        // Generate via OpenAI directly (no tool call recursion)
        $prompt = <<<PROMPT
Generate {$count} {$tone} ad copy variants for {$platform}.
Product/Service: {$args['product']}
USP: {$args['usp']}
CTA: {$args['cta']}

Platform limits: headline max {$limits['headline']} chars, body max {$limits['body']} chars.

Return JSON array: [{"headline": "...", "body": "...", "cta": "..."}]
PROMPT;

        try {
            $response = $this->openai->complete($prompt, 'gpt-4o', 1024, 0.8);
            $variants = json_decode($response, true);

            return $this->toolResult(true, [
                'platform' => $platform,
                'variants' => $variants,
                'count'    => count($variants ?? []),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolAnalyzeFunnel(array $args): string
    {
        try {
            $stats = $this->campaigns->getFunnelStats(
                funnel:  $args['funnel_name'],
                from:    $args['date_from'] ?? now()->subDays(30)->toDateString(),
                to:      $args['date_to']   ?? now()->toDateString(),
                segment: $args['segment']   ?? null,
            );

            return $this->toolResult(true, $stats);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolListCampaigns(array $args): string
    {
        $query = Campaign::query()->orderBy('created_at', 'desc');
        $status = $args['status'] ?? 'all';

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $campaigns = $query->limit($args['limit'] ?? 10)
            ->get(['id', 'name', 'type', 'status', 'schedule_at', 'created_at'])
            ->toArray();

        return $this->toolResult(true, $campaigns);
    }

    private function toolPauseCampaign(array $args): string
    {
        try {
            $campaign = Campaign::findOrFail($args['campaign_id']);
            $this->campaigns->pauseCampaign($campaign);
            return $this->toolResult(true, ['paused' => true, 'campaign_id' => $campaign->id]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }
}
