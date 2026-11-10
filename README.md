# Quince Robots

**Decide which crawlers and AI agents may enter a PrestaShop 9.2 shop, and see which
of them actually come.**

Quince Robots keeps a registry of crawlers and AI agents, writes the shop's decisions
about them into `robots.txt`, and counts which of them visit. For the few bots that
ignore `robots.txt`, it can refuse the request outright. The other Quince modules ask
it who a visitor is instead of guessing for themselves.

> Quince Robots is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **A registry of 162 bots**, grouped the way the decision is actually made: search
  engines, answer engines, model training, SEO tools, monitoring and other. Each entry
  carries a recommendation, and a decision can cover a whole group at once (allow all,
  block all).
- **Rules in `robots.txt`.** PrestaShop owns that file and rewrites it whole, so the
  module hooks into the moment the core writes it and appends its own block. Manual
  edits to the file are lost at the next generation; the module's block is not.
  Search engines stay with the PrestaShop SEO settings and are not touched.
- **File state at a glance.** A screen shows whether the file on disk matches the
  decisions, and writes it again with one click.
- **Refusal, off by default.** For blocked bots known to ignore `robots.txt`, the shop
  can refuse the request, and only once reverse DNS confirms the bot is who it claims
  to be. A blocked bot that respects the file needs nothing more than the file.
- **Request rate ceiling, off by default.** A limit of requests per minute from one
  address, with a period the address stays refused, for traffic that does not
  introduce itself.
- **A visit counter without personal data.** One row per bot per day, one added per
  visit. No IP addresses are stored. The retention period is set in months.
- **Unknown automata.** Traffic that announces itself as a machine but matches no
  entry is listed, and can be added to the registry by hand, so a new bot can be
  blocked before it reaches any published list.
- **A test screen** that fetches the shop's own `robots.txt` over HTTP and resolves it
  the way a crawler would, under RFC 9309, including the part most implementations
  get wrong: a crawler reads one `User-agent` group and ignores every other, `*`
  included.
- **One answer for every Quince module.** Other modules ask through the
  `actionQuinceClassifyVisitor` hook who the visitor is, and decide for themselves
  what follows.
- **Daily housekeeping** of the counter and the rate limit rows, run when someone
  opens the back office, so there is no cron job to set up.
- **Multilingual.** English base with a full Polish translation, on PrestaShop's
  standard translation system.

## What it does not do

- It is not a firewall and not DDoS protection. It runs in PHP, after PrestaShop
  boots, so it cannot defend against traffic meant to saturate the link.
- It does not do CAPTCHAs.
- It does not decide what a bot's visit means for another module. It answers "who is
  this"; every other module decides for itself what follows.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |

The module's screens live inside the Quince Engine back office shell. The module
declares it as a dependency, so PrestaShop refuses to install Quince Robots without
it. Recognising bots, `robots.txt` and the visit counter do not use the engine.

`robots.txt` is one file per installation, in its root folder. With several shops on
one installation, the decisions apply to all of them.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_robots.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_robots/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_robots` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Robots and click **Install**.

**From Quince Engine** *(coming soon)*

The module list in Quince Engine will install the module straight from its release,
without downloading anything by hand.

**With Composer** *(coming soon)*

Installing with `composer require quinceproject/robots` will work once the package is
published on Packagist.

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_robots/releases).

Uninstalling the module removes its settings and its tables. Its block leaves
`robots.txt` the next time PrestaShop generates the file (**Shop Parameters →
Traffic & SEO → Generate robots.txt file**), and every bot is allowed again.

## Configuration

Open **Quince project → Robots and AI** in the back office menu, or click
**Configure** next to the module in the Module Manager.

1. **Decisions.** Allow or block each bot or a whole group, then save. The module
   writes its block into `robots.txt`.
2. **File.** Check that the file on disk matches the decisions.
3. **Test.** See which `User-agent` group each bot lands in and whether the file lets
   it in.
4. **Traffic.** See which bots came in the last 30 days, and add unknown ones.
5. **Settings.** Refusal of blocked bots, the request rate ceiling, the visit counter
   and its retention, and housekeeping.

## Where the bot list comes from

We do not maintain it ourselves. The source is
[`ai-robots-txt/ai.robots.txt`](https://github.com/ai-robots-txt/ai.robots.txt) (MIT,
see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)). Quince adds the three fields it
lacks: category, verification domain and a recommendation.

The full list ships with the module, so the shop has every entry from the first run
without fetching anything. **Check for new bots** in the settings will fetch the
refreshed list from the Quince index, a single file on the project's domain; the shop
never queries GitHub directly. The index is not published yet, so until it is the
button reports that the list could not be fetched, and the module keeps working from
its built-in list.

Refreshing the list only ever updates descriptions and the recommendation. Decisions
made in the shop, and entries added there by hand, are never touched.

## Architecture

- **`BotRegistry`** and **`DefaultBots`** hold the registry and its built-in entries.
- **`RobotsBlock`** and **`RobotsFile`** write the module's block on the
  `actionAdminMetaAfterWriteRobotsFile` hook; **`RobotsTest`** reads the file back
  over HTTP.
- **`Classifier`**, **`Verifier`**, **`RateLimit`** and **`TrafficLog`** recognise,
  verify, limit and count visitors on `actionDispatcherBefore`.
- **Symfony controller and Twig** for the screens, inside the Quince Engine shell.
- Data lives in the module's own tables; settings are stored in PrestaShop
  configuration.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_robots.git
cd quince_robots
composer install
```

The folder must be called `quince_robots`, the same as the module. A clone of this
repository already has that name.

To build an installable archive, zip the `quince_robots/` folder **with its `vendor/`
directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

The bot names and descriptions from ai.robots.txt are under the MIT licence, listed in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
