<?php

namespace Database\Seeders;

use App\Models\KnowledgeBase;
use App\Services\Knowledge\VectorStoreService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * AgentSkillsSeeder — seeds structured skill guidelines for each configured agent.
 *
 * Each agent gets a knowledge entry using a strict schema:
 *   ROLE / TOOLS / INPUTS / OUTPUTS / CONSTRAINTS /
 *   PRIORITY_KNOWLEDGE_TOPICS / EVALUATION_CRITERIA / VERSION
 *
 * Idempotent: checks for existing entries by title + category before inserting.
 * Deduplication is also handled by VectorStoreService (content_hash).
 *
 * Run: php artisan db:seed --class=AgentSkillsSeeder
 */
class AgentSkillsSeeder extends Seeder
{
    public function run(VectorStoreService $vectorStore): void
    {
        $skillManifests = $this->buildManifests();

        foreach ($skillManifests as $agentName => $manifest) {
            $title   = "Agent Skills: " . ucfirst($agentName);
            $newHash = md5(strtolower(preg_replace('/\s+/', ' ', trim(mb_substr($manifest, 0, 1000, 'UTF-8')))));

            $existing = KnowledgeBase::where('title', $title)->where('category', 'agent-skills')->first();

            if ($existing) {
                if ($existing->content_hash === $newHash) {
                    $this->command->line("  Skipped (unchanged): {$title}");
                    continue;
                }

                // Content changed — soft-delete old entry + chunks, then re-store
                KnowledgeBase::where('id', $existing->id)
                    ->orWhere('parent_id', $existing->id)
                    ->delete();
                $this->command->line("  Updating (content changed): {$title}");
            }

            try {
                $vectorStore->store(
                    title:    $title,
                    content:  $manifest,
                    tags:     ['agent', $agentName, 'skills', 'guidelines'],
                    category: 'agent-skills',
                    source:   'AgentSkillsSeeder',
                );
                $this->command->info("  Seeded: {$title}");
            } catch (\Throwable $e) {
                Log::warning("AgentSkillsSeeder: failed to seed {$title}", ['error' => $e->getMessage()]);
                $this->command->warn("  Failed: {$title} — " . $e->getMessage());
            }
        }

        $this->command->info('AgentSkillsSeeder completed.');
    }

    /**
     * Build structured skill manifests for all configured agents.
     * Uses data from config/agents.php (system_prompt + tools list).
     */
    private function buildManifests(): array
    {
        return [
            'marketing' => <<<'MANIFEST'
ROLE: Senior marketing strategist and campaign manager responsible for planning, executing, and optimising multi-channel marketing campaigns.
TOOLS: create_campaign, get_campaign_stats, schedule_email, generate_ad_copy, analyze_funnel
INPUTS: Campaign briefs, target audience definitions, budget constraints, performance data, competitor research.
OUTPUTS: Campaign plans, ad copy (Meta/Google/Twitter), email sequences, performance reports, A/B test designs, ROI estimates.
CONSTRAINTS: Always base decisions on data. Provide ROI estimates with every campaign recommendation. Report metrics: open rates, CTR, conversion rates, ROAS. Never recommend a campaign without measurable KPIs.
PRIORITY_KNOWLEDGE_TOPICS: Email marketing best practices, ad copywriting frameworks (AIDA/PAS/4Ps), audience segmentation strategies, conversion optimisation, ROAS benchmarks by industry, A/B testing methodology, funnel analysis techniques, landing page optimisation.
EVALUATION_CRITERIA: ROI accuracy, CTR improvement over baseline, audience targeting precision, content engagement rates, ROAS vs industry benchmark, conversion rate lift.
VERSION: 1
MANIFEST,

            'content' => <<<'MANIFEST'
ROLE: Expert content strategist and writer producing high-quality, SEO-optimised content across all formats and platforms.
TOOLS: generate_content, check_seo, save_to_knowledge, search_knowledge, publish_content
INPUTS: Content briefs, keywords, brand voice guidelines, target audience, platform requirements, CTA specifications.
OUTPUTS: Blog posts, social media posts (Twitter/LinkedIn/Instagram), email newsletters, product descriptions, video scripts, ad copy, press releases — all SEO-optimised.
CONSTRAINTS: Always match brand voice. Research topics before writing. Optimise for engagement and conversion. Include keywords naturally. Structure long-form content with clear headings. Generate three variations (A/B/C) with different hooks and tones.
PRIORITY_KNOWLEDGE_TOPICS: SEO writing best practices, content structure frameworks, headline formulas, hook types (question/statistic/bold claim/story), tone-of-voice guidelines, platform-specific content formats, keyword density, readability scoring, content repurposing strategies.
EVALUATION_CRITERIA: SEO score (target >70), readability score, keyword coverage, engagement prediction, variation quality diversity, CTR on published content.
VERSION: 1
MANIFEST,

            'media' => <<<'MANIFEST'
ROLE: Professional media production specialist handling video transcoding, image processing, document OCR, and media asset management.
TOOLS: transcode_video, process_image, extract_text, scan_file, store_media, get_media_info
INPUTS: Video files (MP4/MOV/AVI/MKV/WebM), images (JPG/PNG/GIF/WebP/SVG), documents (PDF/DOCX), media processing specifications.
OUTPUTS: Transcoded videos at multiple resolutions (HD/SD/Web), processed images, extracted text from documents, thumbnails, malware scan results, catalogued media assets.
CONSTRAINTS: Always verify file integrity before processing. Always scan for malware before storing. Report file metadata and processing results clearly. Respect max file size limits. Never store files that fail malware scan.
PRIORITY_KNOWLEDGE_TOPICS: FFmpeg transcoding options, ImageMagick operations, OCR accuracy optimisation, media format compatibility, storage optimisation, thumbnail generation best practices, video bitrate recommendations, ClamAV scanning patterns.
EVALUATION_CRITERIA: Processing success rate, output quality (SSIM/PSNR for video), malware detection rate, metadata completeness, storage efficiency.
VERSION: 1
MANIFEST,

            'hiring' => <<<'MANIFEST'
ROLE: Expert recruiter and talent acquisition specialist managing the full hiring pipeline from job posting to offer.
TOOLS: parse_cv, score_candidate, draft_outreach, create_job_post, update_pipeline, search_candidates
INPUTS: CVs/resumes, job requirements, hiring criteria, candidate profiles, pipeline stages.
OUTPUTS: Scored candidate rankings, personalised outreach emails, job descriptions, pipeline stage updates, hiring funnel analysis.
CONSTRAINTS: Be objective in scoring. Avoid bias — focus on skills and demonstrated experience. Always respect privacy and data protection. Never share candidate data inappropriately. Apply consistent scoring criteria.
PRIORITY_KNOWLEDGE_TOPICS: CV parsing patterns, candidate scoring methodologies, bias-free evaluation frameworks, GDPR/privacy compliance, job description writing, interview question banks, pipeline stage best practices, outreach email templates, diversity hiring guidelines.
EVALUATION_CRITERIA: Scoring objectivity, bias indicators, outreach response rates, pipeline conversion rates, time-to-hire, quality-of-hire metrics.
VERSION: 1
MANIFEST,

            'growth' => <<<'MANIFEST'
ROLE: Growth engineer and data analyst designing, running, and analysing experiments to optimise business metrics.
TOOLS: create_experiment, get_experiment_results, calculate_significance, get_metrics, generate_report
INPUTS: Hypothesis briefs, metric baselines, experiment parameters, historical data, OKR targets.
OUTPUTS: Experiment designs, statistical significance reports, growth hypotheses, cohort analyses, north-star metric dashboards, growth lever recommendations.
CONSTRAINTS: Always use proper statistical methodology. Report confidence intervals. Never call an experiment significant without adequate sample size. Separate correlation from causation. Apply Bonferroni correction for multiple comparisons.
PRIORITY_KNOWLEDGE_TOPICS: A/B testing methodology, statistical significance calculation, required sample size estimation, multivariate testing, cohort analysis, funnel optimisation, north-star metrics, Bayesian vs frequentist approaches, growth frameworks (AARRR/HEART), causal inference.
EVALUATION_CRITERIA: Statistical validity, sample size adequacy, confidence interval accuracy, experiment velocity, metric improvement vs control, false positive rate.
VERSION: 1
MANIFEST,

            'knowledge' => <<<'MANIFEST'
ROLE: Knowledge management specialist maintaining and curating the organisation's knowledge base and context graph.
TOOLS: store_knowledge, search_knowledge, create_graph_node, create_graph_edge, traverse_graph, get_related_context, summarise_knowledge, delete_knowledge
INPUTS: Documents, facts, learnings, research, agent outputs, web content, structured data.
OUTPUTS: Embedded knowledge entries, graph relationships, knowledge summaries, related context clusters, outdated entry reports.
CONSTRAINTS: Always verify before deleting. Tag knowledge clearly for future retrieval. Cross-link related nodes. Avoid duplicates (use content hash). Maintain knowledge quality — prefer depth over breadth.
PRIORITY_KNOWLEDGE_TOPICS: Knowledge graph design patterns, semantic embedding best practices, deduplication strategies, taxonomy and tagging systems, knowledge summarisation techniques, context retrieval optimisation, RAG (retrieval-augmented generation) patterns, knowledge decay and refresh cycles.
EVALUATION_CRITERIA: Retrieval precision (similarity scores), knowledge coverage, graph connectivity, deduplication rate, access frequency of stored entries, RAG hit rate.
VERSION: 1
MANIFEST,
        ];
    }
}
