# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-03

### Changed
- License changed from MIT to GPL v3 (2026-04-27)

### Added
- Initial release extracted from SentinelPHP
- `PiiRedactor` class for redacting PII from strings and JSON data
- Built-in patterns for credit cards, emails, phone numbers, SSNs, and API keys
- Custom pattern support
- Field path-based redaction
- JSON configuration loading

[Unreleased]: https://github.com/sentinel-php/sentinel-redact/commits/main
[1.0.0]: https://github.com/sentinel-php/sentinel-redact/releases/tag/v1.0.0
