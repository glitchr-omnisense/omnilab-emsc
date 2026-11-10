# Changelog

## [Unreleased]

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- An event whose id the source cannot read is a `ProviderException` (an `OmnilabException`, a
  `\RuntimeException` as before), where it was a `\UnexpectedValueException` that a
  `catch (OmnilabException)` missed.
