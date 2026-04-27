---
description: Testing Standards
---

- Run tests via `ddev exec bin/phpunit` or `ddev test`
- Unit tests go in `tests/Unit/` mirroring `src/` structure
- Integration tests go in `tests/Integration/`
- Use PHPUnit 12 attributes (`#[Test]`, `#[DataProvider]`)
- Mock external dependencies; never hit real APIs in unit tests
- PHPStan level 10 must pass: `ddev exec vendor/bin/phpstan analyse`
