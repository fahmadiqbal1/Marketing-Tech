<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registered Skill Classes
    |--------------------------------------------------------------------------
    | These classes are synced into the skills_registry table on deploy.
    | Run `php artisan skills:sync` after adding new skills.
    */
    'registered' => [
        // ── Existing skills (unchanged) ─────────────────────────────────────
        \App\Skills\ImageEnhanceSkill::class,
        \App\Skills\VideoExtractFramesSkill::class,
        \App\Skills\BackgroundRemoveSkill::class,
        \App\Skills\OcrExtractSkill::class,
        \App\Skills\LlmGenerateTextSkill::class,
        \App\Skills\ResumeParseSkill::class,
        \App\Skills\CandidateScoreSkill::class,
        \App\Skills\JobPostPublishSkill::class,

        // ── Marketing OS skills (Stage 2) ───────────────────────────────────
        // Names are platform-agnostic and validated by SkillExecutorService::syncRegistry()
        \App\Skills\Marketing\CopywritingSkill::class,
        \App\Skills\Marketing\PageCroSkill::class,
        \App\Skills\Marketing\SeoAuditSkill::class,
        \App\Skills\Marketing\ContentStrategySkill::class,
        \App\Skills\Marketing\PaidAdsSkill::class,
        \App\Skills\Marketing\AnalyticsTrackingSkill::class,
        \App\Skills\Marketing\CreativeContentSkill::class,
    ],
];
