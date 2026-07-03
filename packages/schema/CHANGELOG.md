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
- `Generator` class for creating JSON Schema from sample data
- `Merger` class for combining multiple schemas
- `Validator` class for validating data against schemas
- Format detection (date-time, uuid, email, uri)
- Partial validation support
- Schema caching for performance

[Unreleased]: https://github.com/sentinel-php/sentinel-schema/commits/main
[1.0.0]: https://github.com/sentinel-php/sentinel-schema/releases/tag/v1.0.0
