---
description: PHP & Symfony Conventions
---

- Use `declare(strict_types=1);` in all PHP files
- Follow PSR-12 coding standards
- Use statements should be grouped and sorted
- Named argument order should match the order of the parameters
- Always remove unnecessary `use` statements
- Use constructor property promotion with `readonly` where appropriate
- Prefer interfaces for service dependencies (e.g., `HttpClientInterface`, `TokenAuthenticatorInterface`)
- Use PHP 8.4+ features: typed properties, enums, match expressions
- Entity classes use Symfony UID for UUIDs, not strings
- All services should be `final` unless explicitly designed for extension
- For testability: inject interfaces, mock interfaces (not concrete classes)
