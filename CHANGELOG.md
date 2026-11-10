# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-11-10

### Fixed

- The uninstall confirmation said the module's rules leave robots.txt together with
  the module. They stay until PrestaShop generates the file again, and the message
  now says so.

## [0.1.0] - 2026-11-10

First public release.

### Added

- Registry of 162 crawlers and AI agents, grouped by what they do with the shop's
  content: search, answer engines, model training, SEO tools, monitoring and other.
  Names and descriptions come from ai.robots.txt (MIT).
- Own block in `robots.txt`, written through the PrestaShop generator so it survives
  every regeneration of the file.
- Visitor classification, offered to the other Quince modules through the
  `actionQuinceClassifyVisitor` hook.
- Refusal of blocked bots known to ignore `robots.txt`, only after forward-confirmed
  reverse DNS. Off by default.
- Request rate ceiling per address, with a cool-off period. Off by default.
- Daily visit counter per bot, storing no IP addresses.
- Screens: decisions, `robots.txt` state, traffic, settings, and a test that fetches
  the shop's own file over HTTP and resolves it under RFC 9309.
- Entries added by hand from the traffic screen.
- Daily housekeeping of the counter and of the rate limit rows.
- Polish translation.
