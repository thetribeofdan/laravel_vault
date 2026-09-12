# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),  
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- KV v1 and KV v2 response support per token source via `kv_engine_version`.
- `Vault::sync()` for filling absent or stale mapped values and
  `Vault::refreshKey()` for refreshing a single mapped Vault key.
- Configurable cache namespace, negative/failure cache TTLs, source-load locks,
  file fingerprinting, and HTTP pooling/retry settings.
- Package tests for cache batching, KV versions, retry behavior, targeted
  refreshes, failures, and file rewrites.

### Changed
- Cache misses are bulk-read and share one source sweep per resolver operation;
  persistent cache records remain independent per secret key.
- Token sources can be fetched in bounded concurrent pools while retaining
  configured source precedence.
- Token requests now use configurable 2-second connect and 5-second request
  timeouts with bounded retry backoff.
- Failed loads are distinct from genuinely missing keys, and a failed refresh
  retains a known-good cached value.
- Cache keys are now versioned, namespaced, and hash the Vault key. Existing
  package cache entries are refilled once after upgrading.

### Security
- A single cache entry contains only one mapped secret. Cache backends remain
  sensitive infrastructure and should be protected accordingly.


---

## [1.1.0] – 2025-07-12
### Added
- 📦 Package Name Change
- 🎁 Introduced Per-Key Caching for Faster Synchro Times and better secrity

---

## [1.0.3] – 2025-07-12
### Added
- 📦 Introduced version tracking with this changelog file
- 🎁 Added Donation Link

---

## [1.0.2] – 2025-06-30
### Added
- 📄 MIT License file added
- 📝 Documentation and README improved for clarity

---

## [1.0.1] – 2025-06-30
### Fixed
- 🛠 Minor bug fixes in `token` mode handling and response fallback

---

## [1.0.0] – 2025-06-28
### Added
- 🎉 Initial release of `laravel_vault`
- 🔐 Support for `file` and `token` modes
- 📁 Multi-file secret loading (.env, .json)
- 🔄 Multi-token + multi-path Vault integration
- 🗺️ Config key mapping into Laravel `config(...)`
- ⚡ Runtime refresh with `Vault::refresh()`
- 🧠 Auto-caching secrets with Laravel's cache
- 🧩 Seamless fallback from `database` cache to `file` when cache table is missing
