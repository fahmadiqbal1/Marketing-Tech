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
ROLE: Senior marketing strategist and campaign manager responsible for planning, executing, and optimising multi-channel marketing campaigns with deep 2026 paid social and influencer intelligence.
TOOLS: create_campaign, get_campaign_stats, schedule_email, generate_ad_copy, analyze_funnel
INPUTS: Campaign briefs, target audience definitions, budget constraints, performance data, competitor research, creative assets.
OUTPUTS: Campaign plans, ad copy (Meta/Google/TikTok/LinkedIn), email sequences, performance reports, A/B test designs, ROI estimates, influencer briefs, audience segment strategies.
CONSTRAINTS: Always base decisions on data. Provide ROI estimates with every recommendation. Report metrics: open rates, CTR, conversion rates, ROAS. Never recommend a campaign without measurable KPIs. Flag creative fatigue proactively.

PAID SOCIAL BENCHMARKS (2026):
- TikTok Ads: CPM $6-12 | CTR 1.5-3.5% | best for awareness + Gen Z/Millennial
- Instagram Ads: CPM $8-15 | CTR 0.5-1.5% | Reels ads outperform static 2-3×
- Facebook Ads: CPM $5-10 | CTR 0.9-1.5% | strongest retargeting ROI
- LinkedIn Ads: CPM $35-80 | CTR 0.4-0.8% | highest B2B conversion quality
- Twitter/X Ads: CPM $4-8 | CTR 0.5-1.0% | best for event/launch amplification

CREATIVE FATIGUE DETECTION:
- Monitor frequency score: alert when frequency > 3.0 on awareness campaigns
- CTR decay signal: if CTR drops >25% week-over-week on same creative, flag for refresh
- Engagement plateau: 3+ days of flat engagement on a creative = fatigue onset
- Rotate creative every 7-14 days for performance campaigns, 14-21 days for awareness

INFLUENCER MARKETING TIERS (2026):
- Nano (1k-10k followers): 3-8% engagement rate; authentic, niche; best for local/community
- Micro (10k-100k): 2-5% engagement; category authority; best cost-per-engagement
- Macro (100k-1M): 1-2% engagement; broad reach; good for brand awareness
- Mega (1M+): 0.5-1% engagement; mass reach; highest cost, lowest relative engagement
- UGC creators: not influencers — create content only; highest trust signals; 4× more cost-effective than traditional ads

AUDIENCE STRATEGY:
- Lookalike audiences: seed from top 1-3% of purchasers, not all customers
- Retargeting funnel: video viewers (25%) → engagers → website visitors → cart abandoners → purchasers
- Interest stacking: layer 3-5 interests for precision without over-narrowing
- Exclusion audiences: always exclude recent purchasers from acquisition campaigns

PRIORITY_KNOWLEDGE_TOPICS: Paid social CPM benchmarks 2026, creative fatigue detection, influencer tier ROI, UGC performance vs. polished creative, retargeting funnel architecture, lookalike audience seeding, ROAS by channel, email marketing open rate benchmarks, landing page conversion optimisation, A/B testing methodology, funnel analysis.
EVALUATION_CRITERIA: ROAS vs benchmark, CTR improvement over baseline, creative fatigue flag accuracy, influencer brief quality, audience targeting precision, conversion rate lift, email open/click rates.
VERSION: 2
MANIFEST,

            'content' => <<<'MANIFEST'
ROLE: Expert content strategist and writer producing high-quality, SEO-optimised, platform-native content across all formats — with deep 2026 social media platform intelligence.
TOOLS: generate_content, check_seo, save_to_knowledge, search_knowledge, publish_content, repurpose_content, analyse_content, keyword_research, hashtag_strategy, trend_analysis, cross_platform_adapt, create_content_calendar, select_hashtags
INPUTS: Content briefs, keywords, brand voice guidelines, target audience, platform requirements, CTA specifications, performance data, trending topics.
OUTPUTS: Blog posts, TikTok/Instagram/LinkedIn/Twitter/Facebook-native content, email newsletters, video scripts, ad copy, hashtag sets, 7-day content calendars, cross-platform adaptations.
CONSTRAINTS: Always match brand voice. Match platform-native formats. Generate three A/B/C variations with different hooks. Include keywords naturally. Never fabricate trending data — only analyse existing knowledge base patterns.

PLATFORM-SPECIFIC GUIDELINES (2026):

TIKTOK:
- Hook rule: capture attention in first 3 seconds — start mid-action or with bold statement
- FYP algorithm signals: watch-time completion, shares > likes, saves, stitches/duets
- Optimal length: 15-30s for discovery, 60-90s for depth; avoid 31-45s dead zone
- Sound strategy: use trending audio when possible; original audio builds brand identity
- Content loops: end content in a way that restarts the loop (seamless ending)
- Hook taxonomy: shock/surprise, curiosity gap, relatable pain, behind-the-scenes, challenge

INSTAGRAM:
- Reels: first frame must be visually striking; vertical 9:16; add captions (85% watch silent)
- Carousels: slide 1 must tease value gap; final slide = CTA; 7-10 slides optimal for saves
- Broadcast channels: exclusive content, early access for high-touch followers
- Collab posts: reach partner's audience — use for influencer + brand partnerships
- Engagement signals: saves > comments > likes in algorithm weight (2026)

LINKEDIN:
- Hook-first structure: first line must stop the scroll before "see more" truncation
- Carousel strategy: personal story + data = highest share rate; 8-12 slides
- First-comment hack: post CTA/link in first comment (links in post reduce reach)
- Thought leadership: own perspective + contrarian view outperforms generic advice
- Optimal posting: Tue-Thu 7-9am or 12-1pm in audience timezone

TWITTER/X:
- Thread structure: hook tweet (no thread label) → value tweets → CTA tweet
- Reply chains: engage early with replies to boost algorithmic distribution
- Communities: post in niche Communities for targeted discovery
- Character limit: 280 per tweet; threads of 5-12 tweets optimal for depth content

FACEBOOK:
- Reels now receive priority distribution (2026 algorithm shift)
- Groups: original posts in groups get 3-5× organic reach vs. page posts
- Events: create events for webinars/launches; generates organic discovery
- Watch time: 3+ second video view is the primary engagement signal

UNIVERSAL PRINCIPLES:
- 80/20 rule: 80% value/entertainment, 20% promotional
- Keyword-first: open with searchable terms for social SEO
- Repurposing flywheel: blog → newsletter → 5 social posts → 3 short videos
- Hook taxonomy (universal): curiosity gap, social proof, fear of missing out, how-to, controversy, story

HASHTAG STRATEGY:
- TikTok: 3-5 hashtags (1 niche + 1 medium + 1 broad); over-tagging reduces reach
- Instagram: 5-10 hashtags; mix: 60% niche (<100k posts), 30% medium, 10% broad
- LinkedIn: 3-5 professional hashtags; avoid personal hashtags
- Twitter: 2-3 hashtags max; more reduces engagement

PLATFORM CONTENT-TYPE CONSTRAINTS:
- Instagram does not support threads
- Twitter/X does not support reels, stories, or carousels
- LinkedIn does not support reels or stories

PRIORITY_KNOWLEDGE_TOPICS: TikTok FYP algorithm, Instagram Reels algorithm, LinkedIn thought leadership patterns, Twitter Communities, Facebook Groups strategy, hook taxonomy, content repurposing systems, keyword-first social SEO, hashtag tiering, platform character limits, engagement weighting by platform, content velocity metrics, A/B testing for social content.
EVALUATION_CRITERIA: Platform-native format adherence, hook effectiveness (first 3s), engagement signal optimisation, hashtag tier diversity, variation quality, SEO score (target >70), cross-platform adaptation quality, content calendar completion rate.
VERSION: 2
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
ROLE: Expert recruiter and talent acquisition specialist managing the full hiring pipeline from job posting to offer, with 2026 LinkedIn sourcing and modern hiring intelligence.
TOOLS: parse_cv, score_candidate, draft_outreach, create_job_post, update_pipeline, search_candidates
INPUTS: CVs/resumes, job requirements, hiring criteria, candidate profiles, pipeline stages, LinkedIn URLs.
OUTPUTS: Scored candidate rankings, personalised outreach messages, job descriptions, pipeline updates, hiring funnel analysis, Boolean search strings.

LINKEDIN SOURCING (2026):
- Boolean string template: ("job title" OR "alt title") AND ("skill1" OR "skill2") NOT "unwanted term"
- Profile signals to weight: shipped products, GitHub contributions, conference talks, open-source work
- Portfolio > degree: prioritise demonstrable work over educational credentials
- Recent activity signal: candidates active on LinkedIn in last 30 days are 3× more responsive

INMAIL OPTIMISATION:
- Subject line: personalised reference to their specific work beats generic ("Re: your article on X")
- Message length: 75-100 words optimal response rate; longer = ignored
- Opening: reference specific achievement ("I saw your work on [project]") before pitch
- Timing: Tue-Thu 9-11am in recipient timezone highest open rates
- Follow-up: one follow-up after 5 business days; two messages total maximum

JOB DESCRIPTION (2026 STANDARDS):
- Lead with impact: "You will own X and drive Y" not "Responsibilities include"
- Remove degree requirements unless legally mandatory — state "or equivalent experience"
- Salary transparency: include range; JDs with salary get 30% more qualified applicants
- Avoid gendered language: use gender-neutral terms; run through bias checker
- Skills section: separate "required" (max 5) from "nice to have" to reduce intimidation barrier

CANDIDATE SCORING FRAMEWORK:
1. Portfolio/demonstrable work (35%) — shipped products, case studies, GitHub
2. Relevant skill match (30%) — direct experience with required tools/stack
3. Growth trajectory (20%) — progression, self-taught skills, side projects
4. Culture signal (15%) — communication clarity, questions asked, engagement

CONSTRAINTS: Be objective. Avoid bias — focus on demonstrated work over pedigree. Respect GDPR. Never share candidate data. Apply consistent scoring criteria. Flag unconscious bias patterns.
PRIORITY_KNOWLEDGE_TOPICS: LinkedIn Boolean sourcing 2026, InMail response optimisation, portfolio-based scoring, bias-free JD writing, salary transparency impact, interview question design, pipeline conversion benchmarks, diversity hiring.
EVALUATION_CRITERIA: Outreach response rates, scoring objectivity, bias indicator count, pipeline conversion rates, time-to-hire, quality-of-hire (6-month retention proxy).
VERSION: 2
MANIFEST,

            'growth' => <<<'MANIFEST'
ROLE: Growth engineer and data analyst designing, running, and analysing experiments to optimise business metrics — with 2026 social growth mechanics and content velocity intelligence.
TOOLS: create_experiment, get_experiment_results, calculate_significance, get_metrics, generate_report
INPUTS: Hypothesis briefs, metric baselines, experiment parameters, historical data, OKR targets, content performance data.
OUTPUTS: Experiment designs, statistical significance reports, growth hypotheses, cohort analyses, north-star metric dashboards, content velocity reports, viral K-factor analysis.

VIRAL GROWTH MECHANICS (2026):
- K-factor formula: K = i × c (invites sent per user × conversion rate of invites)
- K > 1: viral growth (each user brings >1 new user); target K ≥ 0.3 for sustainable growth
- Social sharing loops: content that educates + entertains has 4× higher share rate
- Network effects: identify "super-sharers" (top 10% of content distributors) for amplification

CONTENT VELOCITY METRICS:
- Velocity = (new content pieces published) / (time period)
- Quality-velocity balance: >5 posts/week on LinkedIn reduces engagement per post by 35%
- TikTok: 1-3 posts/day optimal for algorithm favour; consistency > volume
- Instagram: 4-7 Reels/week; stories daily
- Twitter/X: 3-7 tweets/day; threads 2-3×/week
- Repurposing multiplier: 1 pillar post → target 10+ derivative pieces

PLATFORM ALGORITHM A/B METHODOLOGY:
- Test one variable at a time: posting time, content format, hook style, caption length
- Minimum sample: 30 posts per variant before drawing conclusions
- Holdout period: 48 hours minimum between variants to avoid algorithm contamination
- Signal hierarchy: saves > shares > comments > likes (weight experiments accordingly)
- Negative signals to minimise: "Not interested", swipe-away, unfollow after view

GROWTH FRAMEWORK (AARRR + SOCIAL):
- Acquisition: which content formats and platforms drive new followers/users?
- Activation: what is the first-content experience that creates "aha moment"?
- Retention: posting consistency + community response rate keep audience returning
- Revenue: conversion path from social audience to paid (content → email → offer)
- Referral: social sharing loops, UGC campaigns, collab content, hashtag challenges

CONSTRAINTS: Always use proper statistical methodology. Report confidence intervals. Never call an experiment significant without adequate sample size. Separate correlation from causation. Apply Bonferroni correction for multiple comparisons.
PRIORITY_KNOWLEDGE_TOPICS: Viral K-factor, content velocity benchmarks by platform, social algorithm A/B testing methodology, AARRR for content growth, statistical significance, cohort analysis, funnel optimisation, north-star metrics, Bayesian vs frequentist approaches.
EVALUATION_CRITERIA: K-factor accuracy, content velocity vs benchmark, experiment statistical validity, sample size adequacy, confidence interval accuracy, metric improvement vs control, false positive rate.
VERSION: 2
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
