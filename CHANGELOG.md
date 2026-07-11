# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-07-11

### Changed
- Restructured user documentation into chapter-based files under `docs/` and shortened the root `README.md` to an index and quick start.
- Updated ingest API documentation to reflect `202` accepted responses (`{ "success": true }`) and `429` rate-limit responses.
- Removed `.env.example` and updated onboarding docs to use committed `.env` defaults.

## [1.0.0] - 2026-07-03

### Added
- Initial SentinelPHP monorepo release.
- Symfony application plus dashboard, API capture, and async processing runtime.
- First stable package set in `packages/`: `core`, `redact`, `encrypt`, `schema`, `drift`, and `dto`.

### Changed
- License baseline standardized to GPL v3 across root project and package set.
