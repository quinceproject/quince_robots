# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.6] - 2026-11-16

### Changed

- New module logo: the letter Q in the project gradient.
- Files in `src/` stop when opened directly instead of through PrestaShop.

## [0.1.5] - 2026-11-15

### Security

- **A bot name may contain only letters, digits, spaces and `. _ - /`.** A name with a
  line break could add its own lines to robots.txt, for example one that blocks the
  whole shop. Line breaks are also removed when the file is written.
- **The traffic log cannot be flooded with invented user agents.** Above 500 different
  unrecognised agents a day they are counted together in one row, and old entries are
  deleted in full.
- The rate limit runs before the DNS check of a bot, so a flood cannot keep PHP
  processes waiting for slow DNS.

## [0.1.4] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions. Before,
  any logged-in employee, whatever their profile, could change which bots may enter the
  shop and rewrite robots.txt. The screens now belong to the module's own menu tab
  (`AdminQuinceRobots`): grant it to a profile under **Advanced Parameters → Team →
  Permissions**. SuperAdmin needs nothing. A profile that had access through the hidden
  `AdminQuince` tab needs access to this tab now.

## [0.1.3] - 2026-11-10

### Changed

- An empty value in a table shows a hyphen instead of a long dash.

## [0.1.2] - 2026-11-10

### Changed

- Polish translation completed: the texts added since the previous translation are now translated.
- Texts no longer use long dashes.

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
