# AGENTS.md

## Purpose

This file provides essential instructions and conventions for AI coding agents working in the SentinelPHP codebase. It summarizes build, test, and verification commands, key architectural patterns, and project-specific conventions. For details, always refer to the linked documentation.

---

## Quick Reference

### Build & Test Commands
- **Run all tests:**
  - `ddev exec bin/phpunit`
- **Run with coverage:**
  - `ddev exec bin/phpunit --coverage-html var/coverage`
- **Static analysis:**
  - `ddev exec vendor/bin/phpstan analyse`
- **Load tests:**
  - `make k6-smoke`, `make k6-load`, `make k6-stress`, `make k6-spike`
  - See [tests/load/README.md](tests/load/README.md) for details
- **Production build:**
  - `make prod-deploy`

### Verification Before Completion
- Always run both:
  - `ddev exec bin/phpunit`
  - `ddev exec vendor/bin/phpstan analyse`
- Fix all errors/warnings before marking a task complete ([see rule](.windsurf/rules/verification.md))

---

## Key Conventions
- Use `ddev` for all dev/test commands (never run PHP or Composer directly)
- Use `declare(strict_types=1);` in all PHP files
- Follow PSR-12 and group/sort `use` statements
- Prefer interfaces for service dependencies
- All services should be `final` unless designed for extension
- Use PHP 8.4+ features (typed properties, enums, match, readonly)
- Entity classes use Symfony UID for UUIDs
- For testability: inject and mock interfaces, not concrete classes

---

## Architecture & Structure
- Symfony + Swoole async proxy (see [README.md](README.md))
- Service injection via interfaces ([config/services.yaml](config/services.yaml))
- Redis for token/status caching, PostgreSQL for entities
- Async logging via Symfony Messenger
- Swoole-specific: use `App\Http\SwooleHttpClient` in prod, `App\Http\GuzzleHttpClientAdapter` in tests ([.windsurf/rules/swoole.md](.windsurf/rules/swoole.md))
- Never use blocking I/O in request handlers

---

## Documentation Links
- [Project README](README.md)
- [Testing standards](.windsurf/rules/testing.md)
- [PHP/Symfony conventions](.windsurf/rules/php-symfony.md)
- [Service architecture](.windsurf/rules/services.md)
- [Command execution](.windsurf/rules/command-execution.md)
- [Swoole guidelines](.windsurf/rules/swoole.md)
- [Verification rules](.windsurf/rules/verification.md)
- [Load testing](tests/load/README.md)
- [DTO generation](docs/dto-generation.md)
- [Dashboard guide](docs/dashboard-guide.md)

---

## Common Pitfalls
- Forgetting to use `ddev` for all commands (required for dev parity)
- Not running both tests and static analysis before completion
- Not following PSR-12 or missing `declare(strict_types=1);`
- Using blocking I/O in Swoole request handlers
- Not updating documentation after contract/code changes

---

## When in Doubt
- Prefer linking to existing docs over duplicating content
- Ask for clarification if a convention or workflow is unclear
- Update this file if you discover new conventions or pitfalls

---

_Last updated: 2026-04-26_
