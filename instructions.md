# instructions.md

## Persistent Agent Instructions for SentinelPHP

These instructions enforce key user and project preferences for all AI coding agents working in this repository. Follow these rules unless explicitly overridden by the user.

---

### 1. Always run tests and commands inside the DDEV environment
- Use `ddev exec ...` for all test, build, and PHP commands.
- Never run PHP, Composer, or test commands directly on the host shell.
- Example: Use `ddev exec bin/phpunit` instead of `php bin/phpunit`.

### 2. Always update project documentation after code or contract changes
- If you change an endpoint, DTO, or contract, update the relevant documentation (including `docs/api-docs.json` if applicable).
- Link to or update endpoint docs, DTO guides, and any related files.

### 3. Follow project-specific conventions and rules
- Adhere to all conventions in `AGENTS.md` and linked documentation.
- When in doubt, prefer linking to existing docs over duplicating content.

---

_Last updated: 2026-04-26_
