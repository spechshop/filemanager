# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2025-12-05

### Added

- Token management system with new `manage-tokens.php` script ([d53fc52](https://github.com/berzersks/filemanager/commit/d53fc52), [ca970f1](https://github.com/berzersks/filemanager/commit/ca970f1))
- Script to download and use PHP 8.5.0 ZTS binary in psalm workflow ([ea16342](https://github.com/berzersks/filemanager/commit/ea16342), [01df7f0](https://github.com/berzersks/filemanager/commit/01df7f0), [8c77125](https://github.com/berzersks/filemanager/commit/8c77125))

### Changed

- Improved token validation in `checkToken.php` with enhanced request handling
- Refactored `run-tests.php` for better test execution workflow
- Updated `phpstan.neon` configuration for static analysis
- Enhanced `server.php` with additional functionality
- Updated `.gitignore` with new exclusion patterns

[Unreleased]: https://github.com/berzersks/filemanager/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/berzersks/filemanager/releases/tag/v0.1.0
