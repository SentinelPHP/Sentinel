# Contributing to Sentinel Packages

Thank you for your interest in contributing to Sentinel!

## Development Setup

1. Clone the monorepo:
   ```bash
   git clone https://github.com/sentinel-php/SentinelPHP.git
   cd SentinelPHP
   ```

2. Install dependencies for a specific package:
   ```bash
   cd packages/schema
   composer install
   ```

3. Run tests:
   ```bash
   vendor/bin/phpunit
   ```

## Package Structure

Each package follows this structure:

```
packages/<name>/
├── src/           # Source code
├── tests/         # PHPUnit tests
├── composer.json  # Package dependencies
├── README.md      # Package documentation
├── CHANGELOG.md   # Version history
└── phpunit.xml    # Test configuration (optional)
```

## Coding Standards

- Follow PSR-12 coding style
- Use strict types (`declare(strict_types=1)`)
- Add type hints to all parameters and return types
- Write PHPDoc blocks for public methods
- Keep classes final unless extension is intended

## Testing

- Write tests for all new functionality
- Maintain existing test coverage
- Use descriptive test method names
- Run the full test suite before submitting:
  ```bash
  vendor/bin/phpunit
  ```

## Pull Request Process

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Make your changes
4. Add/update tests as needed
5. Update CHANGELOG.md
6. Submit a pull request

## Commit Messages

Use clear, descriptive commit messages:

```
[package] Short description

Longer description if needed.
```

Examples:
- `[schema] Add support for date format detection`
- `[redact] Fix phone number pattern for international formats`
- `[drift] Improve severity classification for nested objects`

## Reporting Issues

- Use GitHub Issues
- Include PHP version and package version
- Provide a minimal reproduction case
- Include relevant error messages

## Questions?

Open a discussion on GitHub or reach out to the maintainers.
