# Remotion Skill for Claude Code

## Description
Programmatic video creation with React, integrated as a Claude Code skill. Enables Claude to generate, edit, and render videos using Remotion workflows.

## Installation
1. Clone the Remotion skill repository:
   https://github.com/remotion-dev/skills/tree/main/skills/remotion
2. Copy the contents of the `remotion` skill folder into your `.claude/skills/remotion` directory.
3. Ensure your Claude Code or MCP agent is configured to load skills from `.claude/skills/`.
4. Install Remotion and any required dependencies in your project:
   ```sh
   npm install remotion
   ```
5. (Optional) Review the [Remotion documentation](https://www.remotion.dev/docs/) for advanced usage.

## Usage
- Use the Remotion skill to generate walkthrough videos, automate video editing, or create programmatic video assets from code and data.
- Example prompt: `Create a 30-second onboarding video using Remotion with animated text and a product screenshot.`

## References
- [Remotion Skill Repo](https://github.com/remotion-dev/skills/tree/main/skills/remotion)
- [Remotion Docs](https://www.remotion.dev/docs/)
- [Awesome Agent Skills](https://github.com/voltagent/awesome-agent-skills)

---
This skill is now available in your Claude Code setup. For global use, keep it in `.claude/skills/` or `~/.claude/skills/`.
