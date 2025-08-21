# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),  
and this project adheres to [Semantic Versioning](https://semver.org/).


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
