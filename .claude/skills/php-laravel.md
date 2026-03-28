# PHP/Laravel Skills for Claude Code

## Description
A set of skills and guidelines for working with Laravel PHP projects in Claude Code. Ensures all code generation, debugging, and automation is PHP/Laravel-specific. Prevents creation of files in other languages (Python, JS, etc.).

## Usage Guidelines
- All code, scripts, and automation must be in PHP (Laravel framework).
- Claude/Copilot should not generate or create files in Python, JavaScript, or other languages.
- When using skills (Remotion, Superpowers, Frontend Design), always specify PHP/Laravel context in your prompts.
- Example prompt: “Debug this Laravel controller using the Superpowers skill. Output only PHP code.”

## PHP/Laravel-Specific Skills
- [laravel-phpstan](https://github.com/nunomaduro/phpstan-laravel): Static analysis for Laravel code.
- [laravel-ide-helper](https://github.com/barryvdh/laravel-ide-helper): IDE autocompletion for Laravel projects.
- [laravel-debugbar](https://github.com/barryvdh/laravel-debugbar): Debugging and profiling for Laravel apps.
- [laravel-telescope](https://github.com/laravel/telescope): Debug assistant for Laravel.

## Integration
- Place this file in `.claude/skills/php-laravel.md`.
- Add any new PHP/Laravel skills to `.claude/skills/` as needed.
- Document any custom workflow or helper scripts in PHP only.

---
This skill ensures your Claude Code environment is PHP/Laravel-only. For global use, keep it in `.claude/skills/` or `~/.claude/skills/`.
