<?php

namespace App\Services;

class PromptTemplateService
{
    /**
     * Replace {variable} placeholders in $template with values from $vars.
     * Unknown placeholders are left as-is.
     */
    public function render(string $template, array $vars): string
    {
        $search  = array_map(fn($k) => '{' . $k . '}', array_keys($vars));
        $replace = array_values($vars);

        return str_replace($search, $replace, $template);
    }
}
