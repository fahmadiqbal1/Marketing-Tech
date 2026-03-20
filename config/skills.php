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
        \App\Skills\ImageEnhanceSkill::class,
        \App\Skills\VideoExtractFramesSkill::class,
        \App\Skills\BackgroundRemoveSkill::class,
        \App\Skills\OcrExtractSkill::class,
        \App\Skills\LlmGenerateTextSkill::class,
        \App\Skills\ResumeParseSkill::class,
        \App\Skills\CandidateScoreSkill::class,
        \App\Skills\JobPostPublishSkill::class,
    ],
];
