# Changelog

## [Unreleased]

### Fixed

- An event whose id the source cannot read is a `ProviderException` (an `OmnilabException`, a
  `\RuntimeException` as before), where it was a `\UnexpectedValueException` that a
  `catch (OmnilabException)` missed.
