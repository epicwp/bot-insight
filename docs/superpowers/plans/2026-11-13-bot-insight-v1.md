# Bot Insight v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A WordPress plugin that measures every request reaching PHP, classifies the client, stores
compact aggregates and shows a wp-admin dashboard of PHP time per bot class, released via
semantic-release in a `direct` (with updater) and an `org` flavour.

**Architecture:** A `shutdown` hook builds one compact record per request and appends it to a
per-process buffer file. A fold job (every 5 minutes, and when the dashboard opens) enriches the
records (UA classification, bot verification, IP hashing, GeoIP), writes hourly aggregates, a
sample, a dictionary and per-day unique counts, then deletes the buffer. A read-only `Reports`
layer feeds an admin-only REST endpoint and a vanilla-JS SVG dashboard. Pure logic (parsing,
classification, aggregation) has no WordPress dependency and is unit-tested; WordPress glue is
integration-tested with the WordPress test suite.

**Tech Stack:** PHP 7.4+ (WordPress 6.2+), Composer (PSR-4 `BotInsight\` → `src/`), PHPUnit 9 +
yoast/phpunit-polyfills + wp-phpunit, PHPStan (szepeviktor/phpstan-wordpress), PHPCS (WPCS 3 +
PHPCompatibilityWP), Plugin Check (wordpress/plugin-check-action), Node + semantic-release, GitHub
Actions, yahnis-elsts/plugin-update-checker (direct flavour only), maxmind-db/reader (GeoIP).

**Spec:** `docs/superpowers/specs/2026-11-12-bot-insight-design.md` (read the "Decisions" and
"Builder decisions" sections at the end first). Background: `.private/research/2026-11-12-data-model-notes.md` (local only)
(the spec wins where they differ). Dashboard look: `docs/mockup/dashboard.dc.html`.

## Global Constraints

- License GPL-2.0-or-later; every PHP file starts with the plugin's license docblock; no obfuscated code.
- PHP 7.4+ and WordPress 6.2+ (`Requires PHP: 7.4`, `Requires at least: 6.2`). No PHP 8-only syntax.
- Code, comments, UI text, commit messages in English. Text domain `bot-insight`.
- Slug `bot-insight`; PHP namespace `BotInsight\`; options/hooks/transients prefixed `bot_insight_`;
  tables `{$wpdb->prefix}botinsight_*`; cookie `bi_js`.
- No external HTTP call unless the owner opted in (range refresh, GeoIP download) or it is the
  `direct` flavour's update check; each is disclosed in readme.txt.
- Escape all output; nonce + `manage_options` on every admin action and REST route.
- Privacy: never persist a full IP, a query value, a user identity or order details. Raw IP and raw
  UA exist only in buffer files and are gone after the fold.
- Performance: under 0.5 ms added per request at p95; no database write and no remote call during a
  visitor's request.
- Commits follow Conventional Commits (`feat:`, `fix:`, `chore:`, `ci:`, `docs:`, `test:`,
  `refactor:`); semantic-release depends on it.
- Never name the client shops from the field findings in code, docs or tests; never commit real IPs
  (use 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32 in fixtures).
- No JS build step: dashboard is plain ES2017 JS + CSS shipped as-is; no external CDNs.

## Review Focus

1. Bot traffic arriving via a reverse proxy (REMOTE_ADDR private, client in `X-Forwarded-For`) with a
   forged `CF-Connecting-IP` header from a non-Cloudflare address: must not be trusted, must count as
   `via_cdn = false`. Test in Task 5.
2. Buffer folder unwritable or disk full: the visitor's request must finish normally and silently;
   the dashboard shows a health warning. Test in Task 10.
3. A fold that runs twice at once (WP-Cron + dashboard open): no double-counted rows. Test in Task 17.
4. A fold that is interrupted half-way (timeout): buffer files are deleted only after their rows are
   committed, and a re-run must not double count. Test in Task 17.
5. Huge or hostile input (8 KB user agent, 500 query params, binary bytes in path or param names):
   bounded, no warnings, nothing stored beyond limits. Tests in Tasks 6 and 8.

---

## File structure

```
bot-insight.php                 Plugin header, constants, autoload, Plugin::boot()
uninstall.php                   Drops tables, options, transients, buffer folder
readme.txt                      WordPress.org readme incl. privacy + external services
composer.json, phpcs.xml.dist, phpstan.neon.dist, phpunit.xml.dist, .gitattributes, .distignore
package.json, .releaserc.json   semantic-release
bin/set-version.php             Writes version into header, readme, Plugin::VERSION
bin/build-zip.sh                Builds dist/bot-insight-{direct,org}.zip
bin/update-ranges.php           Downloads published IP ranges into data/ranges/*.php (dev tool)
bin/install-wp-tests.sh         WordPress test suite installer
.github/workflows/ci.yml, release.yml
data/bots.json                  Bot list (versioned)
data/ranges/*.php               Bundled range snapshot (return array of CIDRs)
data/asn-types.json             ASN org keyword lists for hosting/CDN/mobile
src/Plugin.php                  Wiring of hooks, services
src/Updater.php                 direct flavour only (removed from org zip)
src/Net/IpRanges.php            CIDR matching against named range lists
src/Net/ClientIp.php            Real client IP, source, via_cdn, prefix
src/Request/HeaderFlags.php     Bitmask constants + from server array
src/Request/Shape.php           param names, page depth, referer type
src/Request/UrlPattern.php      Path collapsing + language prefix
src/Request/Route.php           Route + uncached reason from a context array
src/Request/WpContext.php       Collects the context array from WordPress at shutdown
src/Capture/Record.php          Record key constants, encode/decode of one buffer line
src/Capture/Recorder.php        shutdown hook, side-effect hooks, JS cookie script
src/Buffer/FileBuffer.php       Append, list foldable files, read, delete
src/Bots/BotList.php            Loads bots.json, matches a UA to a bot
src/Bots/UaParser.php           ua_family, ua_outdated, library detection
src/Bots/Classifier.php         client_class, suspected-automated signals
src/Bots/Verifier.php           Range + reverse/forward DNS verification with cache
src/Geo/GeoLookup.php           country/ASN from MMDB files, asn_type
src/Geo/GeoUpdater.php          Opt-in monthly DB-IP Lite download
src/Storage/Schema.php          dbDelta tables, version option
src/Storage/Dictionary.php      id <-> value per type
src/Storage/Salt.php            Daily rotating HMAC salt, ip_hash
src/Fold/Enricher.php           Record -> enriched row
src/Fold/Aggregator.php         Enriched rows -> hourly rows with cardinality guard
src/Fold/UniqueTracker.php      Per-hour and per-day unique ip_hash files
src/Fold/Folder.php             Orchestrates a fold run, lock, sampling, hour/day close
src/Fold/Scheduler.php          Action Scheduler or WP-Cron registration
src/Site/Events.php             Event recording hooks
src/Site/Snapshot.php           Daily site snapshot, robots.txt parsing
src/Site/Daily.php              Daily job: snapshot, retention, sample rate, range refresh
src/Report/Reports.php          Read-only queries (the v2 detector API)
src/Report/Signal.php           Interface; src/Report/Signals/*.php implementations
src/Admin/RestController.php    /bot-insight/v1/report, /export
src/Admin/DashboardPage.php     Tools > Bot Insight
src/Admin/SettingsPage.php      Settings + delete-all + manual event notes
assets/dashboard.js, assets/dashboard.css
tests/unit/...                  No WordPress; bootstrap = composer autoload
tests/integration/...           WordPress test suite
```

## Shared definitions (used by many tasks)

**Client classes** (string, `BotInsight\Bots\Classifier::CLASSES`): `people`, `search`, `ai_search`,
`ai_assistant`, `ai_training`, `seo`, `social`, `monitoring`, `feed`, `library`, `unknown_bot`,
`suspected`. Bot side of the headline = everything except `people`.

**Verified** (int): `0` not a claimed bot, `1` verified, `2` failed (impostor), `3` not checked
(no method or DNS budget spent).

**Buffer record** (`BotInsight\Capture\Record`, short keys, JSON per line):
`ts` int, `ms` float php_ms, `cpu` float cpu_ms, `mem` int kb, `dbq` int, `st` int status,
`unc` string uncached reason, `m` string method, `r` string route, `p` string param names
(comma-joined), `dp` string depth bucket, `lg` string lang slug, `u` string url pattern,
`ref` string referer type, `ua` string raw UA (≤ 256 bytes), `ip` string raw IP, `ips` string ip
source, `cdn` int 0/1, `cc` string country from CDN header or `''`, `hf` int header flags,
`js` int 0/1, `se` int session created, `ca` int cart changed, `or` int order created, `pc` int
payment callback, `self` int recorder's own microseconds.

**Enriched row** (array, produced by Task 15, consumed by 16–17): all record keys except `ua`, `ip`,
plus `ua_family` string, `ua_outdated` int, `bot` string bot id or `''`, `class` string, `verified`
int, `signals` int, `ip_hash` string (16 hex), `ip_prefix` string, `country` string (2 letters or
`??`), `asn` int, `asn_type` string (`residential|mobile|hosting|cdn|unknown`), `hour` int (UTC
hour start timestamp), `status_class` string (`2xx|3xx|4xx|5xx`).

---

### Task 1: Repository skeleton and code-quality tooling

**Files:**
- Create: `bot-insight.php`, `src/Plugin.php`, `composer.json`, `phpcs.xml.dist`,
  `phpstan.neon.dist`, `phpunit.xml.dist`, `tests/unit/bootstrap.php`, `tests/unit/PluginHeaderTest.php`,
  `readme.txt`, `LICENSE` (GPL-2.0 text), `.gitattributes`, `.distignore`, `.editorconfig`
- Modify: `.gitignore` (add `vendor/`, `node_modules/`, `dist/`, `.phpunit.result.cache`)

**Interfaces:**
- Produces: `BotInsight\Plugin::VERSION` (string, starts `'0.0.0'`), `BotInsight\Plugin::boot(string $file): void`,
  constants `BOT_INSIGHT_FILE`, `BOT_INSIGHT_DIR`; composer scripts `lint` (phpcs), `analyse`
  (phpstan), `test:unit`, `test:integration`.

- [ ] **Step 1: Write the failing test** `tests/unit/PluginHeaderTest.php`:
  `test_header_version_matches_constant()` reads `bot-insight.php`, extracts `Version:` and asserts it
  equals `Plugin::VERSION`; `test_readme_stable_tag_matches_constant()` same for `Stable tag:` in
  `readme.txt`; `test_header_requirements()` asserts `Requires PHP: 7.4`, `Requires at least: 6.2`,
  `License: GPL-2.0-or-later`, `Text Domain: bot-insight`.
- [ ] **Step 2:** `composer install && composer test:unit` → FAIL (class missing).
- [ ] **Step 3: Implement.** composer.json: `"type": "wordpress-plugin"`, `"license": "GPL-2.0-or-later"`,
  `"require": {"php": ">=7.4"}`, PSR-4 autoload, `config.platform.php = 7.4.33`. Dev deps:
  `phpunit/phpunit ^9.6`, `yoast/phpunit-polyfills ^2`, `wp-phpunit/wp-phpunit ^6.2`,
  `wp-coding-standards/wpcs ^3`, `phpcompatibility/phpcompatibility-wp ^2.1`,
  `szepeviktor/phpstan-wordpress ^1.3`, `phpstan/phpstan ^1.10`, `phpstan/extension-installer`.
  phpcs.xml.dist: rulesets `WordPress-Extra`, `WordPress-Docs`, `PHPCompatibilityWP`,
  `testVersion 7.4-`, `minimum_wp_version 6.2`, prefixes `bot_insight`, `BotInsight`, text domain
  `bot-insight`; exclude `WordPress.Files.FileName` (PSR-4 file names); scan `bot-insight.php`,
  `uninstall.php`, `src/`. phpstan.neon.dist: level 6, paths `src`, `bot-insight.php`.
  phpunit.xml.dist: suites `unit` (tests/unit, bootstrap tests/unit/bootstrap.php) and `integration`
  (tests/integration, own bootstrap via `--configuration phpunit-integration.xml.dist` created in
  Task 11). `bot-insight.php` defines constants, `vendor/autoload.php` (present in dev after `composer install` and in both zips), calls
  `Plugin::boot(__FILE__)`; guards `defined('ABSPATH') || exit;`. `Plugin::boot` is empty for now (do
  not call `load_plugin_textdomain`; WordPress loads translations itself). `.gitattributes`/`.distignore` exclude tests, bin, docs, .github,
  dev config, node files from zips.
- [ ] **Step 4:** `composer test:unit && composer lint && composer analyse` → all PASS / no errors.
- [ ] **Step 5: Commit** `chore: add plugin skeleton and code-quality tooling`

### Task 2: CI workflow

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: composer scripts from Task 1; `bin/build-zip.sh` from Task 3 (the Plugin Check job is
  added in Task 3 once the zip exists).

- [ ] **Step 1: Write** `ci.yml` triggered on `push` and `pull_request`, jobs: `lint` (PHP 8.3,
  `composer lint`), `analyse` (PHP 8.3, `composer analyse`), `unit` (matrix PHP 7.4, 8.1, 8.3;
  `composer test:unit`). Use `shivammathur/setup-php@v2` and `ramsey/composer-install@v3`.
- [ ] **Step 2: Verify** locally with `act -j unit` if available, else push to a branch and confirm
  the run is green (remote: `epicwp/bot-insight`, public).
- [ ] **Step 3: Commit** `ci: run phpcs, phpstan and unit tests`

### Task 3: semantic-release, zip flavours, updater, Plugin Check

**Files:**
- Create: `package.json`, `.releaserc.json`, `bin/set-version.php`, `bin/build-zip.sh`,
  `src/Updater.php`, `.github/workflows/release.yml`, `tests/unit/SetVersionTest.php`,
  `CHANGELOG.md` (empty header)
- Modify: `.github/workflows/ci.yml` (add `plugin-check` job), `composer.json` (require
  `yahnis-elsts/plugin-update-checker ^5.5`), `src/Plugin.php`

**Interfaces:**
- Produces: `php bin/set-version.php <semver>`; `bin/build-zip.sh <direct|org>` → `dist/bot-insight-<flavour>.zip`
  containing one top folder `bot-insight/`; `BotInsight\Updater::register(string $pluginFile): void`;
  flavour marker: `org` zip has no `src/Updater.php` and no PUC in `vendor/`, and `Plugin::boot` calls
  the updater only `if ( class_exists( Updater::class ) )`.

- [ ] **Step 1: Write the failing test** `SetVersionTest::test_rewrites_all_three_places()`: copy
  fixture versions of `bot-insight.php`, `readme.txt`, `src/Plugin.php` to a temp dir, run
  `bin/set-version.php 1.4.2 --root=<tmp>`, assert `Version: 1.4.2`, `Stable tag: 1.4.2` and
  `const VERSION = '1.4.2';`; `test_rejects_non_semver()` expects exit code 1 for `1.4`.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3: Implement** `bin/set-version.php` (regex replace, `--root` default repo root).
  `bin/build-zip.sh`: `rsync` with `.distignore` into `build/bot-insight/`, `composer install --no-dev
  --classmap-authoritative --working-dir=build/bot-insight` (the zip ships `vendor/`). For `org`: delete `src/Updater.php`, run composer
  with `--no-dev` after `composer remove yahnis-elsts/plugin-update-checker --no-update` in the build
  copy. Zip with `cd build && zip -r ../dist/bot-insight-<flavour>.zip bot-insight`.
  `Updater::register`: `PucFactory::buildUpdateChecker('https://github.com/epicwp/bot-insight/', $file, 'bot-insight')`,
  `->getVcsApi()->enableReleaseAssets('/bot-insight-direct\.zip/')`. Repo URL is the constant
  `Updater::REPO`.
  `.releaserc.json`: branches `["main"]`, plugins `commit-analyzer`, `release-notes-generator`,
  `changelog`, `@semantic-release/exec` (`prepareCmd`: `php bin/set-version.php ${nextRelease.version} && bin/build-zip.sh direct && bin/build-zip.sh org`),
  `@semantic-release/git` (assets: `CHANGELOG.md`, `bot-insight.php`, `readme.txt`, `src/Plugin.php`,
  message `chore(release): ${nextRelease.version} [skip ci]`), `@semantic-release/github` (assets:
  both zips). Branches `["main", {"name": "beta", "prerelease": true}]`: pilots run on `1.0.0-beta.N`
  pre-releases, the first merge to `main` releases `1.0.0` (semantic-release does not start below
  1.0.0). The updater only offers pre-releases to sites that define `BOT_INSIGHT_BETA` true.
  `release.yml`: on push to `main` and `beta`, after CI passes (`workflow_run` or `needs` in a single
  workflow), PHP 7.4 for `composer install --no-dev`, Node 22, `npx semantic-release`,
  `GITHUB_TOKEN` with `contents: write`.
  ci.yml `plugin-check` job: `bin/build-zip.sh org`, unzip to `build-check/bot-insight`, run
  `wordpress/plugin-check-action@v1` with `build-dir: build-check/bot-insight`.
- [ ] **Step 4:** `composer test:unit` PASS; `bin/build-zip.sh direct && bin/build-zip.sh org`;
  `unzip -l dist/bot-insight-org.zip | grep -c Updater` → `0`; `unzip -l dist/bot-insight-direct.zip | grep -c plugin-update-checker` → > 0;
  `unzip -l dist/*.zip | grep -E 'tests/|phpunit|node_modules'` → nothing.
- [ ] **Step 5: Commit** `ci: add semantic-release with direct and org zips and plugin check`

### Task 4: IP ranges and the bot list

**Files:**
- Create: `src/Net/IpRanges.php`, `bin/update-ranges.php`, `data/ranges/*.php`, `data/bots.json`,
  `src/Bots/BotList.php`, `tests/unit/Net/IpRangesTest.php`, `tests/unit/Bots/BotListTest.php`

**Interfaces:**
- Produces: `IpRanges::__construct(string $dir)`; `IpRanges::contains(string $ip, string $list): bool`;
  `IpRanges::lists(): string[]`. `BotList::fromFile(string $path): BotList`;
  `BotList::match(string $ua): ?array` returning `['id','name','class','owner','verify'=>['ranges'=>string[]|null,'dns'=>string[]|null],'findability'=>bool]`;
  `BotList::get(string $id): ?array`; `BotList::version(): string`.

- [ ] **Step 1: Write failing tests.** IpRanges: `198.51.100.7` in a fixture list `['198.51.100.0/24']`
  → true; `198.51.101.1` → false; `2001:db8:1::5` in `['2001:db8::/32']` → true; IPv4 vs IPv6 list
  mismatch → false; invalid IP `'abc'` → false, no warning; unknown list → false.
  BotList: `Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)` → id
  `googlebot`, class `search`; `GPTBot/1.2` → `gptbot`, `ai_training`; `OAI-SearchBot` → `ai_search`;
  `ChatGPT-User` → `ai_assistant`; `Mozilla/5.0 ... Chrome/120` → null; match is case-insensitive;
  the more specific pattern wins (`Googlebot-Image` → `googlebot-image` before `googlebot`, by list
  order).
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3: Implement.** Range files are PHP (`<?php return [ '1.2.3.0/24', ... ];`) so opcache
  serves them; `IpRanges` lazy-loads per list and matches with `inet_pton` + prefix mask comparison.
  `bin/update-ranges.php` downloads and converts: `google` (developers.google.com/static/search/apis/ipranges/googlebot.json,
  special-crawlers.json, user-triggered-fetchers.json), `bing` (www.bing.com/toolbox/bingbot.json),
  `apple` (search.developer.apple.com/applebot.json), `openai-gptbot`/`openai-searchbot`/`openai-chatgpt-user`
  (openai.com/gptbot.json, searchbot.json, chatgpt-user.json), `perplexity-bot`/`perplexity-user`
  (www.perplexity.ai/perplexitybot.json, perplexity-user.json), `cloudflare` (www.cloudflare.com/ips-v4,
  ips-v6). Verify each URL when running it; drop a list whose URL no longer works and note it in
  bots.json. Writes a `data/ranges/_meta.php` with fetch date.
  `bots.json`: `{"version":"2026.11.1","bots":[{"id","name","patterns":[...],"class","owner","verify":{"ranges":[...]|null,"dns":[".googlebot.com",".google.com"]|null},"findability":true}]}`.
  Cover at least: Googlebot (+Image, +Video, Google-InspectionTool, GoogleOther, Google-Extended is a
  robots token only, skip), Bingbot, Applebot, DuckDuckBot, YandexBot, Baiduspider, OAI-SearchBot,
  ChatGPT-User, GPTBot, ClaudeBot, Claude-User, Claude-SearchBot, anthropic-ai, PerplexityBot,
  Perplexity-User, meta-externalagent, meta-externalfetcher, facebookexternalhit, Amazonbot,
  Bytespider, CCBot, Google-CloudVertexBot, AhrefsBot, SemrushBot, MJ12bot, DotBot, DataForSeoBot,
  BLEXBot, PetalBot, Twitterbot, LinkedInBot, Slackbot, WhatsApp, Discordbot, TelegramBot,
  Pinterestbot, UptimeRobot, Pingdom, StatusCake, Site24x7, Better Uptime, Jetpack, Feedly,
  Inoreader, NewsBlur. Anthropic: DNS/ranges only if verifiable at fetch time, else `null`.
  `findability: true` for classes `search`, `ai_search`, `ai_assistant`.
- [ ] **Step 4:** tests PASS; `php bin/update-ranges.php` produces non-empty files.
- [ ] **Step 5: Commit** `feat: add bundled bot list and ip range matching`

### Task 5: Client IP, source, CDN path, prefix

**Files:**
- Create: `src/Net/ClientIp.php`, `tests/unit/Net/ClientIpTest.php`

**Interfaces:**
- Consumes: `IpRanges::contains($ip, 'cloudflare')`.
- Produces: `ClientIp::__construct(IpRanges $ranges)`; `ClientIp::resolve(array $server): array`
  → `['ip' => string, 'source' => string, 'via_cdn' => int]`; static `ClientIp::prefix(string $ip): string`
  (IPv4 /24 → `203.0.113.0`, IPv6 /48 → `2001:db8:1::`; invalid → `''`).

Rules (pin these in tests):
1. `proxy_ip` = REMOTE_ADDR. If REMOTE_ADDR is private/loopback/link-local and `HTTP_X_FORWARDED_FOR`
   exists, `proxy_ip` = rightmost XFF entry that is not private (the hop that reached our proxy).
2. If `proxy_ip` is in `cloudflare` ranges: ip = `HTTP_CF_CONNECTING_IP` (source `cf_connecting_ip`),
   `via_cdn = 1`.
3. Else if REMOTE_ADDR was private and XFF exists: ip = `proxy_ip`, source `x_forwarded_for`;
   `via_cdn = 1` only when a `HTTP_FASTLY_CLIENT_IP` or `HTTP_X_SUCURI_CLIENTIP` header is present
   (header-only CDNs, no ranges bundled), else 0.
4. Else ip = REMOTE_ADDR, source `remote_addr`, via_cdn 0.
5. Invalid IP anywhere → fall back to REMOTE_ADDR; still invalid → ip `''`.

- [ ] **Step 1: Write failing tests**, including Review Focus 1:
  `test_forged_cf_header_from_non_cloudflare_is_ignored` (REMOTE_ADDR `10.0.0.5`, XFF
  `198.51.100.9`, `CF-Connecting-IP: 192.0.2.1`, 198.51.100.9 not in cloudflare fixture) → ip
  `198.51.100.9`, source `x_forwarded_for`, via_cdn 0;
  `test_cloudflare_behind_ingress` (REMOTE_ADDR `10.0.0.5`, XFF `192.0.2.1, 173.245.48.10`, CF
  header `192.0.2.1`, fixture cloudflare `173.245.48.0/20`) → `192.0.2.1`, `cf_connecting_ip`, 1;
  direct cloudflare REMOTE_ADDR; plain REMOTE_ADDR; prefix v4/v6/invalid.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: resolve client ip, source and cdn path`

### Task 6: Request shape helpers

**Files:**
- Create: `src/Request/HeaderFlags.php`, `src/Request/Shape.php`, `src/Request/UrlPattern.php`,
  `tests/unit/Request/HeaderFlagsTest.php`, `ShapeTest.php`, `UrlPatternTest.php`

**Interfaces:**
- Produces:
  - `HeaderFlags` constants: `ACCEPT_LANGUAGE=1, SEC_FETCH=2, SEC_CH_UA=4, ACCEPT_HTML=8, ANY_COOKIE=16,
    CONSENT_COOKIE=32, WOO_SESSION=64, CART_COOKIE=128, LOGGED_IN=256, JS_COOKIE=512`;
    `HeaderFlags::fromRequest(array $server, array $cookies): int`. Consent cookie names: `cookieyes-consent`,
    `CookieConsent`, `cmplz_*`, `borlabs-cookie`, `wp_consent_*`, `moove_gdpr_popup`, `cookielawinfo-*`.
    Woo session: `wp_woocommerce_session_*`; cart: `woocommerce_items_in_cart`, `woocommerce_cart_hash`;
    logged in: `wordpress_logged_in_*`.
  - `Shape::paramNames(array $get): string` (lowercased, `[a-z0-9_\-\[\]]` only, ≤ 40 chars each, unique,
    sorted, at most 20 names, joined with `,`; `''` when none).
  - `Shape::pageDepth(int $paged): string` → `1`, `2-5`, `6-20`, `21+` (≤ 1 → `1`).
  - `Shape::refererType(string $referer, string $ownHost): string` → `none|own|search|social|ai|other`.
    AI hosts checked first: `chatgpt.com`, `chat.openai.com`, `perplexity.ai`, `claude.ai`,
    `gemini.google.com`, `copilot.microsoft.com`; search: `google.`, `bing.com`, `duckduckgo.com`,
    `yahoo.`, `ecosia.org`, `yandex.`, `baidu.com`; social: `facebook.com`, `instagram.com`, `t.co`,
    `x.com`, `twitter.com`, `linkedin.com`, `pinterest.`, `reddit.com`, `tiktok.com`, `youtube.com`.
    Host match is suffix-on-label (`www.google.de` matches `google.`; `notgoogle.com` does not).
  - `UrlPattern::collapse(string $path, array $keep): string` and `UrlPattern::lang(string $path, array $slugs): string`.
    Collapse: lowercase path, split on `/`, keep a segment when it is in `$keep` (language slugs,
    permalink bases, `page`, `feed`, `wp-json`, `wp-content`, `wp-admin`, `shop`, `cart`, `checkout`,
    `my-account`, `product`, `product-category`, `product-tag`, `category`, `tag`, `author`) else `*`;
    merge consecutive `*`; at most 6 segments then `/…`; always leading and trailing `/`; `/` stays
    `/`. `lang` returns the first segment when it is in `$slugs`, else `''`.
- [ ] **Step 1: Write failing tests** with exact cases: `/en/product/blue-shirt/` → `/en/product/*/`;
  `/de_at/category/shoes/page/7/` → `/de_at/category/*/page/*/`; `/2024/05/hello/` → `/*/`;
  `paramNames(['orderby'=>'x','add-to-cart'=>'1','Filter_Color'=>'r'])` →
  `add-to-cart,filter_color,orderby`; Review Focus 5: 500 params → 20 names; a name of 4 KB → cut to
  40 chars; binary bytes in path → removed, no warning; refererType cases including
  `https://gemini.google.com/app` → `ai`, `https://www.google.nl/` → `search`, own host → `own`, `''` → `none`.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: add request shape helpers`

### Task 7: Route and uncached reason

**Files:**
- Create: `src/Request/Route.php`, `tests/unit/Request/RouteTest.php`

**Interfaces:**
- Produces: `Route::resolve(array $ctx): string` and `Route::uncachedReason(array $ctx): string`.
  `$ctx` keys (all filled by `WpContext::collect()` in Task 10): `rest_route` string, `ajax_action`
  string, `wc_ajax` string, `wc_api` string, `doing_cron` bool, `is_admin` bool, `pagenow` string,
  `xmlrpc` bool, `is_404`, `is_robots`, `is_feed`, `is_search`, `is_product`, `is_shop`,
  `is_product_taxonomy`, `is_front_page`, `is_home`, `is_page`, `is_singular`, `sitemap` bool,
  `method` string, `has_query` bool, `logged_in_cookie` bool, `cart_cookie` bool, `donotcachepage` bool,
  `page_cache` bool.
- Route order (first match): `rest:<namespace>` (first two path parts of rest_route, e.g.
  `rest:wc/store`, `rest:` alone for index), `wc-ajax:<endpoint>`, `wc-api:<name>`, `ajax:<action>`,
  `cron`, `xmlrpc`, `login` (pagenow `wp-login.php`), `admin`, `robots`, `sitemap`, `feed`, `404`,
  `search`, `product`, `shop`, `product_cat`, `home`, `page`, `post`, `other`. Names after `:` pass
  through `sanitize_key`-equivalent (`[a-z0-9_\-/]`, ≤ 40 chars).
- Uncached reason order: `post` (method ≠ GET/HEAD), `logged_in`, `cart_cookie`, `query_string`,
  `donotcachepage`, `no_page_cache`, `cache_miss`.
- [ ] **Step 1: Write failing tests**: one per route (data provider) plus precedence cases (REST
  request that is also `is_404` → `rest:...`; wc-ajax with POST → route `wc-ajax:add_to_cart`,
  reason `post`; GET `?add-to-cart=1` on page cache site → `query_string`).
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: resolve route and uncached reason`

### Task 8: User agent parser

**Files:**
- Create: `src/Bots/UaParser.php`, `tests/unit/Bots/UaParserTest.php`

**Interfaces:**
- Consumes: `BotList::match()`.
- Produces: `UaParser::__construct(BotList $bots, array $currentMajors)`; `UaParser::parse(string $ua): array`
  → `['family' => string, 'outdated' => int, 'bot' => ?array, 'kind' => 'bot'|'library'|'browser'|'empty'|'other']`.
  `$currentMajors` comes from `bots.json` key `current_majors` (`{"chrome":142,"edge":142,"firefox":145,"safari":26}`
  — set real values when writing the file).
- Rules: bot match first (`family` = bot name). Libraries (`kind=library`, family = canonical name):
  `curl`, `Wget`, `python-requests`, `Python-urllib`, `aiohttp`, `httpx`, `Go-http-client`, `Java`,
  `okhttp`, `axios`, `node-fetch`, `undici`, `GuzzleHttp`, `libwww-perl`, `Scrapy`, `HeadlessChrome`,
  `PhantomJS`, `WordPress` (loopback). Browsers: Edge (`Edg/`), Opera (`OPR/`), Firefox, Chrome,
  Safari (`Version/x` + `Safari`) → family `Chrome 116`. Outdated when major < current − 12
  (Chrome/Edge/Opera/Firefox) or < current − 2 (Safari). Empty UA → family `empty`. Else `other`
  with family `other`. Truncate input to 512 bytes before matching.
- [ ] **Step 1: Write failing tests** for each kind, outdated boundaries (Chrome current−12 not
  outdated, current−13 outdated), HeadlessChrome → library, Review Focus 5: 8 KB UA string → parses
  in < 1 ms and returns `other` without warnings.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: parse user agents into families`

### Task 9: Classifier with suspected-automated signals

**Files:**
- Create: `src/Bots/Classifier.php`, `tests/unit/Bots/ClassifierTest.php`

**Interfaces:**
- Consumes: `UaParser::parse()` result, `HeaderFlags` constants.
- Produces: `Classifier::CLASSES` (see Shared definitions); signal constants `SIG_NO_JS=1,
  SIG_NO_SEC_FETCH=2, SIG_NO_ACCEPT_LANG=4, SIG_GET_ADD_TO_CART=8, SIG_UA_OUTDATED=16`;
  `Classifier::classify(array $parsed, int $headerFlags, int $js, string $method, string $params, int $verified): array`
  → `['class' => string, 'signals' => int]`.
- Rules: bot with `verified === 2` → `unknown_bot`; bot → its class; `library` → `library`;
  `empty`/`other` → `unknown_bot`; `browser` → compute signals (add-to-cart = GET/HEAD and
  `add-to-cart` in params list); 2 or more signals → `suspected`, else `people`. Signals are returned
  for every browser row (also `people`), 0 otherwise.
- [ ] **Step 1: Write failing tests**: first visit of a modern browser (no js, has Sec-Fetch and
  Accept-Language) → `people`, signals `SIG_NO_JS`; old Chrome, no js, no Sec-Fetch → `suspected`;
  GET add-to-cart without js → `suspected`; fake Googlebot (verified 2) → `unknown_bot`; curl →
  `library`.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: classify clients and flag suspected automation`

### Task 10: Buffer and recorder

**Files:**
- Create: `src/Capture/Record.php`, `src/Buffer/FileBuffer.php`, `src/Request/WpContext.php`,
  `src/Capture/Recorder.php`, `tests/unit/Capture/RecordTest.php`, `tests/unit/Buffer/FileBufferTest.php`,
  `tests/unit/Capture/RecorderBenchTest.php` (group `bench`, excluded by default)
- Modify: `src/Plugin.php`

**Interfaces:**
- Consumes: Tasks 5–7.
- Produces: `Record::encode(array $r): string` (one JSON line, `\n` terminated, ua cut to 256 bytes),
  `Record::decode(string $line): ?array`. `FileBuffer::__construct(string $dir)`;
  `FileBuffer::append(string $line, int $now): bool` (never throws, suppresses warnings, returns
  false on failure; health is checked by the REST endpoint with `is_writable`); `FileBuffer::foldable(int $now): string[]` (files whose minute <
  current minute, sorted); `FileBuffer::read(string $file): \Generator<array>`;
  `FileBuffer::delete(string $file): void`; `FileBuffer::ensureDir(): bool` (creates dir, `index.php`,
  `.htaccess` with `Require all denied` + `Deny from all`). File name
  `buffer-<YmdHi UTC>-<crc32(gethostname()) hex>-<getmypid()>.php`; first line written on create:
  `<?php exit; ?>`. Option `bot_insight_buffer_dir` (autoload yes) holds the resolved dir
  (`wp_upload_dir()['basedir'] . '/bot-insight'`).
  `WpContext::collect(): array` (the `$ctx` of Task 7 plus `paged` int, `path` string).
  `Recorder::register(): void` hooks `shutdown` priority 9999 and side effects:
  `woocommerce_set_cart_cookies` (session created when the request had no Woo session cookie),
  `woocommerce_add_to_cart`, `woocommerce_cart_item_removed`, `woocommerce_cart_item_set_quantity`
  (cart changed), `woocommerce_checkout_order_created` and `woocommerce_new_order` (order created),
  `woocommerce_api_request` (payment callback). Enqueues on the front end a registered empty handle
  `bot-insight-js` with inline script `document.cookie="bi_js=1;path=/;SameSite=Lax"+(location.protocol==="https:"?";Secure":"")`
  — only when the request has no `bi_js` cookie.
- `Plugin::settings(): array` (option `bot_insight_settings` merged with the defaults listed in
  Task 22, cached per request) is created here; Task 22 adds the page that edits it.
- Recorder rules: skip when setting `exclude_admins` is on and `current_user_can('manage_options')`;
  skip WP-CLI (`defined('WP_CLI')`); `ms` = `(microtime(true) - REQUEST_TIME_FLOAT) * 1000`; cpu
  from `getrusage()` minus a value stored at plugin load (`Plugin::boot` stores it, cheap);
  `self` = microseconds spent inside the shutdown callback (measure before encode, after append).
  Wrap the whole callback in `try/catch (\Throwable)`; never output anything.
- [ ] **Step 1: Write failing tests.** Record round-trip; decode of garbage → null.
  FileBuffer (temp dir): append twice in the same minute → one file, two lines + header;
  `foldable($now)` excludes the current minute; read skips the header line and broken lines;
  Review Focus 2: unwritable dir (`chmod 0555`) → `append` returns false, no warning, no exception.
  Bench: 1,000 × (build record from fixture server array + encode + append) average < 0.15 ms.
- [ ] **Step 2:** FAIL. **Step 3:** implement; wire `Recorder::register()` in `Plugin::boot` on
  `plugins_loaded`. **Step 4:** `composer test:unit` PASS; `vendor/bin/phpunit --group bench` PASS.
- [ ] **Step 5: Commit** `feat: record each php request into a file buffer`

### Task 11: Schema, installer, uninstall, integration harness

**Files:**
- Create: `src/Storage/Schema.php`, `uninstall.php`, `phpunit-integration.xml.dist`,
  `tests/integration/bootstrap.php`, `bin/install-wp-tests.sh`, `tests/integration/SchemaTest.php`,
  `tests/integration/RecorderTest.php`
- Modify: `src/Plugin.php`, `.github/workflows/ci.yml` (integration job with MySQL 8 service,
  matrix PHP 7.4 + WP 6.2, PHP 8.3 + WP latest)

**Interfaces:**
- Produces: `Schema::DB_VERSION` int; `Schema::install(): void` (dbDelta, sets option
  `bot_insight_db_version`); `Schema::tables(): array<string,string>` short name → full table name;
  `Schema::drop(): void`. Activation hook calls `Schema::install()`, `FileBuffer::ensureDir()`,
  stores `bot_insight_buffer_dir`, and generates option `bot_insight_secret` (wp_generate_password 32).
  `plugins_loaded` runs `install()` when the stored version is lower.
- Tables (all InnoDB, `$wpdb->get_charset_collate()`):
  - `botinsight_hourly`: `id` bigint PK, `hour` int unsigned, `dims` binary(16) (md5 of the dimension
    tuple), `client_class` varchar(16), `bot_id` int unsigned, `route` varchar(64), `param_sig_id` int
    unsigned, `lang` varchar(12), `country` char(2), `asn_type` varchar(12), `via_cdn` tinyint,
    `verified` tinyint, `js_seen` tinyint, `status_class` char(3), `uncached_reason` varchar(16),
    metrics `requests` int, `php_ms_sum` bigint, `cpu_ms_sum` bigint, `db_queries_sum` bigint,
    `mem_peak_max` int, `slow_2s` int, `unique_ips` int, `ips_seen_once` int, `top_ip_share` smallint
    (basis points), `sessions_created` int, `cart_changes` int, `orders` int; UNIQUE (`hour`,`dims`),
    KEY (`hour`,`client_class`).
  - `botinsight_daily`: `day` date, `client_class` varchar(16), `requests` int, `php_ms_sum` bigint,
    `unique_ips` int, `ips_seen_once` int; PK (`day`,`client_class`).
  - `botinsight_dict`: `id` int PK auto, `type` varchar(12), `value` varchar(191); UNIQUE (`type`,`value`).
  - `botinsight_sample`: `id` PK, `ts` int, all enriched-row fields except `ip`/`ua` (ids for
    `bot`, `ua_family`, `params`, `url_pattern` via dict), `self_us` int; KEY (`ts`).
  - `botinsight_events`: `id` PK, `ts` int, `type` varchar(24), `subject` varchar(191), `detail` text; KEY (`ts`).
  - `botinsight_snapshot`: `day` date PK, `data` longtext (JSON).
- `uninstall.php`: `Schema::drop()`, delete options `bot_insight_%`, transients `_transient_bot_insight_%`
  and `_transient_timeout_bot_insight_%`, recursive delete of the buffer dir, unschedule hooks
  (`as_unschedule_all_actions` when present, `wp_clear_scheduled_hook`).
- [ ] **Step 1: Write failing integration tests**: `SchemaTest::test_install_creates_six_tables`,
  `test_install_is_idempotent`, `test_uninstall_removes_everything` (include uninstall.php with
  `WP_UNINSTALL_PLUGIN` defined, assert no tables, no `bot_insight_%` options, no dir).
  `RecorderTest::test_frontend_request_appends_one_record` (go_to home, run the shutdown callback
  directly, read buffer, assert route `home`, method `GET`, no `ip` persisted anywhere in options);
  `test_admin_excluded_by_default`.
- [ ] **Step 2:** `bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest` (or the ddev
  DB) then `composer test:integration` → FAIL.
- [ ] **Step 3:** implement. **Step 4:** PASS (unit + integration), `composer lint analyse` clean.
- [ ] **Step 5: Commit** `feat: add database schema, installer and uninstall`

### Task 12: Dictionary and daily salt

**Files:**
- Create: `src/Storage/Dictionary.php`, `src/Storage/Salt.php`, `tests/integration/DictionaryTest.php`,
  `tests/unit/Storage/SaltTest.php`

**Interfaces:**
- Produces: `Dictionary::id(string $type, string $value): int` (types `bot`, `ua_family`, `param_sig`,
  `url_pattern`, `asn_org`; value cut to 191 chars; in-memory cache; `INSERT IGNORE` then select);
  `Dictionary::values(string $type, int[] $ids): array<int,string>`.
  `Salt::__construct(callable $getOption, callable $setOption)`; `Salt::forDay(string $ymd): string`
  (random 32 bytes hex, option `bot_insight_salt` = `['day'=>..., 'salt'=>...]`, new day → new salt,
  old discarded); `Salt::hash(string $ip, string $ymd): string` = first 16 hex of
  `hash_hmac('sha256', $ip, salt)`.
- [ ] **Step 1: Tests**: same value twice → same id; different types → independent ids; salt stable
  within a day, different next day, previous day not retrievable; hash is 16 hex chars.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: add dictionary and daily rotating ip salt`

### Task 13: Verifier

**Files:**
- Create: `src/Bots/Verifier.php`, `tests/unit/Bots/VerifierTest.php`

**Interfaces:**
- Consumes: `IpRanges`, bot array from `BotList`.
- Produces: `Verifier::__construct(IpRanges $ranges, callable $reverse, callable $forward, callable $cacheGet, callable $cacheSet, int $dnsBudget = 50)`;
  `Verifier::verify(?array $bot, string $ip): int` (0/1/2/3 per Shared definitions).
  In production `$reverse` = `gethostbyaddr`, `$forward` = `gethostbynamel`, cache = transients
  `bot_insight_dns_<substr(md5(secret.ip),0,20)>` for `DAY_IN_SECONDS`.
- Rules: no bot → 0. Bot with `ranges` → 1 if IP in any listed range, else 2. Bot with `dns` →
  cached result if present; else if budget left: reverse lookup host must end with one of the
  suffixes and forward lookup must contain the IP → 1, else 2; budget spent → 3 (not cached). No
  method → 3. Budget is per fold run (new instance per fold).
- [ ] **Step 1: Tests** with fake callables: range hit/miss, DNS pass, DNS suffix mismatch
  (`googlebot.com.evil.net`) → 2, forward mismatch → 2, budget 0 → 3, cache hit skips lookups.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: verify claimed bots by ip ranges and dns`

### Task 14: GeoIP lookup and opt-in updater

**Files:**
- Create: `src/Geo/GeoLookup.php`, `src/Geo/GeoUpdater.php`, `data/asn-types.json`,
  `tests/unit/Geo/GeoLookupTest.php`, `tests/fixtures/geo/` (tiny test MMDBs built with
  the maxmind test-data writer, or skip MMDB tests and inject a reader double)
- Modify: `composer.json` (require `maxmind-db/reader ^1.11`)

**Interfaces:**
- Produces: `GeoLookup::__construct(?string $countryDb, ?string $asnDb, array $asnTypes)`;
  `GeoLookup::lookup(string $ip, string $cdnCountry): array` → `['country' => 'NL'|'??', 'asn' => int, 'asn_org' => string, 'asn_type' => string]`.
  CDN header country wins when it is two letters and not `XX`/`T1`. `asn_type` from org name
  keywords in `asn-types.json` (`{"cdn":["cloudflare","fastly","akamai"],"hosting":["amazon","google cloud","microsoft","digitalocean","hetzner","ovh","linode","akamai connected cloud","contabo","vultr","alibaba","tencent","oracle","scaleway","leaseweb","choopa"],"mobile":["mobile","wireless","cellular"]}`);
  known ASN with no keyword hit → `residential`; no ASN → `unknown`.
  `GeoUpdater::run(): bool` downloads `https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.mmdb.gz`
  and `dbip-asn-lite-YYYY-MM.mmdb.gz` via `wp_remote_get` (timeout 60, stream to temp file), gunzips
  into `uploads/bot-insight/geo/`, only when setting `geo_updates` is true; previous month as
  fallback when current 404s.
- [ ] **Step 1: Tests**: CDN header wins; `XX` ignored; no DB files → `??`/0/`unknown`; keyword
  mapping (`Hetzner Online GmbH` → `hosting`, `Cloudflare, Inc.` → `cdn`, `KPN B.V.` → `residential`).
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: add country and network type lookup`

### Task 15: Enricher

**Files:**
- Create: `src/Fold/Enricher.php`, `tests/unit/Fold/EnricherTest.php`

**Interfaces:**
- Consumes: `UaParser`, `Classifier`, `Verifier`, `Salt`, `GeoLookup`, `ClientIp::prefix`.
- Produces: `Enricher::__construct(UaParser, Classifier, Verifier, Salt, GeoLookup)`;
  `Enricher::enrich(array $record): array` → enriched row (Shared definitions). `hour` =
  `ts - ts % 3600`; salt day = `gmdate('Ymd', ts)`; `status_class` = first digit + `xx`.
  The returned row has no `ip` and no `ua` key.
- [ ] **Step 1: Tests**: Googlebot record from a range IP → class `search`, verified 1; same UA
  from a non-range IP → `unknown_bot`, verified 2, bot `googlebot`; browser record → `people` or
  `suspected`; `array_key_exists('ip', $row) === false` and `'ua'` likewise; `ip_prefix` of
  `203.0.113.77` = `203.0.113.0`.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: enrich buffered records at fold time`

### Task 16: Aggregator with cardinality guard

**Files:**
- Create: `src/Fold/Aggregator.php`, `tests/unit/Fold/AggregatorTest.php`

**Interfaces:**
- Consumes: enriched rows; dictionary ids passed in as resolver callable.
- Produces: `Aggregator::DIMENSIONS` (ordered list: `client_class, bot_id, route, param_sig_id, lang,
  country, asn_type, via_cdn, verified, js_seen, status_class, uncached_reason`);
  `Aggregator::aggregate(iterable $rows, callable $dictId, array $existingSlots): array`
  → list of hourly rows `['hour','dims','<dimensions>...','<metrics>...']` where `dims` = raw md5 of
  the imploded dimension tuple. `$existingSlots` = `[hour => ['count' => int, 'keys' => [dims => true]]]`
  as read from the table.
- Cardinality guard (pin in tests): per hour at most `Aggregator::MAX_PER_HOUR = 200` distinct `dims`.
  Combos already in the table always update. New combos for an hour are ranked by `php_ms_sum`
  desc and take the free slots; the rest are merged into an overflow combo that keeps
  `client_class`, `verified`, `via_cdn` and sets `bot_id` 0, `route` `other`, `param_sig_id` 0,
  `lang` `''`, `country` `??`, `asn_type` `unknown`, `js_seen` 0, `status_class` `xxx`,
  `uncached_reason` `other`. Overflow combos may exceed 200 by at most 12 classes × 3 × 2 rows; that
  is accepted. Metrics: sums, `mem_peak_max` = max, `slow_2s` = count of `ms >= 2000`,
  `sessions_created`/`cart_changes`/`orders` = sums of `se`/`ca`/`or`. Unique metrics are left 0
  here (Task 17 fills them).
- [ ] **Step 1: Tests**: two identical-dimension rows → one hourly row with summed metrics; 250
  distinct combos in an empty hour → 200 kept (the top 200 by php time) + overflow rows, total
  `requests` preserved; existing combo still updates when the hour is full.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: aggregate rows into hourly combinations`

### Task 17: Folder, unique tracker, scheduler

**Files:**
- Create: `src/Fold/UniqueTracker.php`, `src/Fold/Folder.php`, `src/Fold/Scheduler.php`,
  `tests/integration/FolderTest.php`, `tests/unit/Fold/UniqueTrackerTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Consumes: Tasks 10–16.
- Produces: `UniqueTracker::__construct(string $dir)`; `add(int $hour, string $dims, string $class, string $ipHash): void`
  (appends `dims\tclass\tip_hash` to `uniq-<YmdH>.log` and `class\tip_hash` to `uniq-<Ymd>.log`);
  `closeHour(int $hour): array<string dims, array{unique_ips:int, ips_seen_once:int, top_ip_share:int}>`
  (deletes the hour file; `top_ip_share` = requests of the 10 busiest ip_hashes / all requests ×
  10000); `closeDay(string $ymd): array<string class, array{unique_ips:int, ips_seen_once:int}>`.
  `Folder::run(int $now, int $maxSeconds = 20): array` → stats `['files'=>int,'records'=>int,'skipped_lock'=>bool]`;
  action `bot_insight_folded` fired with the stats (the v2 hook). `Scheduler::register(): void`:
  Action Scheduler recurring `bot_insight_fold` every 300 s group `bot-insight` when
  `as_schedule_recurring_action` exists, else WP-Cron schedule `bot_insight_five_minutes`;
  daily `bot_insight_daily` at 00:10 UTC.
- Fold algorithm (per run):
  1. Lock: `add_option('bot_insight_fold_lock', $now, '', 'no')`; when it exists and is younger than
     240 s → return `skipped_lock`; older → take over (update).
  2. For each foldable file (oldest first) until `$maxSeconds`: read + enrich all records; build
     hourly rows; in one transaction: upsert hourly rows (`INSERT ... ON DUPLICATE KEY UPDATE` sums /
     GREATEST for mem), insert sample rows (each record with probability `1 / sample_rate`, option
     `bot_insight_sample_rate`, default 1); commit; then `UniqueTracker::add` for every row; then
     delete the file. A crash before delete re-folds the file: guard with option
     `bot_insight_folded_files` (list of file names committed in this run; skip names in it, cleared
     when the file is gone).
  3. Close every hour `< floor(now/3600)*3600 - 60` that has a uniq file: write the per-dims unique
     metrics with `UPDATE ... WHERE hour = %d AND dims = %s`.
  4. Close every day `< today UTC` with a uniq day file: write `botinsight_daily` rows (requests and
     php_ms from the hourly table, uniques from the tracker).
  5. Release the lock (`delete_option`).
- [ ] **Step 1: Write failing tests.** Unique tracker: 3 hashes, one seen twice → unique 3, seen
  once 2. Folder (integration, real buffer files from fixture records with documentation-range IPs):
  fold writes hourly rows whose `requests` sum equals the record count and deletes the files;
  Review Focus 3: second `Folder::run` while the lock is fresh → `skipped_lock` and no rows change;
  Review Focus 4: simulate a crash after commit, before delete (file name in
  `bot_insight_folded_files`, file still present) → next run deletes it without adding rows;
  no `ip`/raw UA in any table (`SELECT` all text columns, assert no `198.51.100.` and no `Mozilla`).
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: fold buffer into hourly, sample and daily tables`

### Task 18: Events, snapshot and the daily job

**Files:**
- Create: `src/Site/Events.php`, `src/Site/Snapshot.php`, `src/Site/Daily.php`,
  `tests/unit/Site/RobotsParserTest.php`, `tests/integration/DailyTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Produces: `Events::add(string $type, string $subject, string $detail = ''): void`; types
  `plugin_update`, `theme_update`, `core_update`, `plugin_activated`, `plugin_deactivated`,
  `theme_switched`, `robots_changed`, `page_cache_changed`, `cdn_changed`, `note`. Hooks:
  `upgrader_process_complete`, `activated_plugin`, `deactivated_plugin`, `switch_theme`.
  `Snapshot::collect(): array` with keys `wp`, `wc`, `php`, `plugins` (slug => version), `theme`,
  `page_cache` (`WP_CACHE` && `advanced-cache.php` exists, plus known plugin slug: wp-rocket,
  litespeed-cache, w3-total-cache, wp-super-cache, wp-fastest-cache, sg-cachepress), `object_cache`,
  `server` (`SERVER_SOFTWARE`), `htaccess` bool, `cdn` (cloudflare when any of the day's hourly rows
  has via_cdn 1, or the snapshot request carries `CF-Ray`), `robots` (`hash`, `groups`),
  `sitemap` URL, `lang_plugin`, `languages` (slugs), `billing_countries_90d` (country => count),
  `shipping_countries`, `load` (hourly samples), `overhead_p95_us` (p95 of `self_us` in yesterday's
  sample), `bots_version`, `ranges_date`. `Snapshot::parseRobots(string $txt): array` → list of
  groups `['agents' => string[], 'allow' => string[], 'disallow' => string[]]`.
  Billing countries: HPOS on → `SELECT a.country, COUNT(*) FROM {wc_order_addresses} a JOIN {wc_orders} o ON o.id = a.order_id WHERE a.address_type = 'billing' AND o.type = 'shop_order' AND o.date_created_gmt > %s GROUP BY a.country`;
  HPOS off → same over `posts` + `postmeta` `_billing_country`. Countries and counts only.
  `Daily::run(int $now): void`: write snapshot for yesterday; emit `robots_changed` /
  `page_cache_changed` / `cdn_changed` when it differs from the previous snapshot; update option
  `bot_insight_langs` (autoload) with language slugs and permalink bases used by `UrlPattern`;
  retention (`hourly`, `daily`, `snapshot` older than setting `retention_days` default 400; `sample`
  older than 14 days); sample rate = `max(1, ceil(yesterday_requests / 5000))` unless the setting
  fixes it; range refresh when setting `range_updates` is on and `_meta` older than 7 days (fetch
  into `uploads/bot-insight/ranges/`, which `IpRanges` prefers over bundled files when newer);
  `GeoUpdater::run()` on the 3rd of the month when `geo_updates` is on.
  Hourly: `bot_insight_fold` also appends `sys_getloadavg()[0]` to option `bot_insight_load` once per hour.
- [ ] **Step 1: Tests**: robots parser on the field case (`User-agent: *` + `Disallow: /*?*`,
  `User-agent: GPTBot` + `Allow: /`) → two groups with the right lists; Daily run with seeded rows
  older than 400 / 14 days deletes them; sample rate from 52,000 requests → 11; snapshot contains
  no email/name keys.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: record site events and daily snapshot`

### Task 19: Reports and signals

**Files:**
- Create: `src/Report/Reports.php`, `src/Report/Period.php`, `src/Report/Signal.php`,
  `src/Report/Signals/OriginBypass.php`, `GetAddToCart.php`, `RobotsPerBotAllow.php`, `NoPageCache.php`,
  `QueryStringBypass.php`, `BotSessions.php`, `tests/integration/ReportsTest.php`

**Interfaces:**
- Produces: `Period::fromKey(string $key, int $now): Period` (`24h`, `7d`, `30d`; else `7d`) with
  `start`, `end` (hour timestamps). `Reports` (read-only, `$wpdb->prepare` everywhere):
  `totals(Period): array{requests:int, php_hours:float, bot_share:float, bot_php_hours:float, verified_search_requests:int, unique_client_days:int}`;
  `perDay(Period): list<array{day:string, by_class: array<string,float php_hours>}>` (day in site tz);
  `visitors(Period, int $limit = 25): list<array{bot:string, class:string, requests:int, php_hours:float, verified:array{yes:int,no:int,unchecked:int}, findability:bool}>`
  (browser rows grouped as `People` and `Suspected automated`);
  `params(Period, int $limit = 15): list<array{params:string, requests:int, php_hours:float, bot_share:float}>`;
  `unknownCountries(Period, int $limit = 10): list<array{country:string, requests:int, php_hours:float}>`
  (classes `unknown_bot`, `suspected`, `library`);
  `signalsBreakdown(Period): array<string signal, int>` (from sample, scaled by sample rate);
  `overhead(): array{p95_us:int, days:int}`; `latestSnapshot(): ?array`; `events(Period): list<array>`;
  `exportHourly(Period): list<array{hour:string, class:string, bot:string, requests:int, php_ms:int, verified:int}>`.
  `Signal` interface: `id(): string`, `evaluate(Reports $r, Period $p): ?array` returning
  `['id','severity'=>'info'|'notice','title','facts'=>array<string,int|float|string>]` or null.
  `Signals::all(): Signal[]` registry with filter `bot_insight_signals` (v2 adds detectors here).
  Signal definitions: OriginBypass — snapshot cdn set and > 1% of requests have via_cdn 0;
  GetAddToCart — GET requests with `add-to-cart` in params from non-people classes > 0;
  RobotsPerBotAllow — a robots group naming a bot of class ai_training or seo contains `Allow: /`
  while `*` disallows something; NoPageCache — snapshot page_cache false; QueryStringBypass — share of
  PHP time with uncached_reason `query_string` > 20%; BotSessions — sessions_created by non-people > 0.
  Titles are factual sentences, no advice, e.g. "12,400 requests reached PHP without passing through Cloudflare".
- [ ] **Step 1: Tests** with seeded hourly/sample/snapshot rows: totals and bot_share; perDay
  buckets by site timezone (`Europe/Amsterdam`); visitors ordering by php_hours; each signal fires on
  its seeded case and returns null on a clean site.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: add read-only reports and factual signals`

### Task 20: REST endpoints

**Files:**
- Create: `src/Admin/RestController.php`, `tests/integration/RestControllerTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Consumes: `Reports`, `Signals::all()`, `Folder::run()`.
- Produces: namespace `bot-insight/v1`. `GET /report?period=7d` → `{period, totals, per_day, visitors,
  params, countries, signals, signal_breakdown, overhead, health:{buffer_writable:bool, last_fold:int,
  page_cache:bool}, notes:{page_cache_unseen:true}}`; runs `Folder::run(time(), 10)` first.
  `GET /export?period=30d` → JSON `{plugin_version, generated_at, period, rows: exportHourly}` with
  header `Content-Disposition: attachment; filename="bot-insight-<period>-<Ymd>.json"`.
  `permission_callback` = `current_user_can('manage_options')`; nonce via the standard
  `X-WP-Nonce: wp_rest` header; `period` arg with `enum` validation.
- [ ] **Step 1: Tests**: anonymous → 401; subscriber → 403; admin → 200 with all keys; invalid
  period → 400; export has the attachment header.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: add admin-only report and export endpoints`

### Task 21: Dashboard page

**Files:**
- Create: `src/Admin/DashboardPage.php`, `assets/dashboard.js`, `assets/dashboard.css`,
  `tests/integration/DashboardPageTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Consumes: `/bot-insight/v1/report`, `/export`.
- Produces: page `tools.php?page=bot-insight` (`add_management_page`, capability `manage_options`);
  assets enqueued only on that screen with `wp_localize_script` / `wp_add_inline_script` passing
  `restUrl`, `nonce`, i18n strings (via `wp_set_script_translations`, dependency `wp-i18n`,
  `wp-api-fetch`).
- Layout follows `docs/mockup/dashboard.dc.html` minus every fix/apply button: banner (bot share of
  PHP time, sentence form), four key figures (PHP hours, bot share, verified search requests,
  unique client-days), PHP hours per day stacked by class (inline SVG bars, class colours from CSS
  custom properties, legend), "Who is visiting" table (bot/class, requests, PHP hours, verified
  yes/no/unchecked, findability badge), parameters table, unknown-bot countries, factual signals
  list (with signal names spelled out for `suspected`), privacy note with measured overhead, period
  selector (24 h / 7 d / 30 d), export button (`apiFetch` with `parse:false` → blob download),
  health warning when the buffer is not writable, permanent note "Requests answered by a page cache
  or CDN never reach PHP and are not counted." Text through `textContent` only (no `innerHTML` with
  data). Respect `prefers-color-scheme` and WP admin colours; works at 782 px admin breakpoint.
- [ ] **Step 1: Test**: as admin, `DashboardPage::render()` output contains the root element
  `id="bot-insight-app"`; script handle enqueued only on the page hook; subscriber gets no menu item.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS; manual check in a ddev site with
  fixture data (seed via `wp eval-file tests/fixtures/seed.php` — create that script: 7 days of
  hourly rows across classes) in light and dark mode; screenshot for Robbert.
- [ ] **Step 5: Commit** `feat: add wp-admin dashboard`

### Task 22: Settings page

**Files:**
- Create: `src/Admin/SettingsPage.php`, `tests/integration/SettingsPageTest.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Produces: option `bot_insight_settings` (autoload) with defaults
  `['sample_rate' => 'auto', 'exclude_admins' => true, 'range_updates' => false, 'geo_updates' => false, 'retention_days' => 400]`;
  (`Plugin::settings()` exists since Task 10). Settings API section on a
  second tab of the Bot Insight page. Opt-in fields explain the external call (host, frequency, what
  is sent: nothing but the HTTP request) and DB-IP's CC BY 4.0 attribution. Admin-post actions
  `bot_insight_delete_all` (nonce + capability + confirm checkbox: truncates all tables, deletes
  buffer/tracker files, keeps settings) and `bot_insight_add_note` (adds a `note` event).
  `retention_days` sanitized to 30–730; `sample_rate` `auto` or int 1–1000.
- [ ] **Step 1: Tests**: sanitize callback clamps values; delete_all without nonce → `wp_die`;
  with nonce as admin → tables empty; note event stored and escaped on output.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** `feat: add settings, opt-ins and delete-all`

### Task 23: Readme, privacy text, release readiness

**Files:**
- Modify: `readme.txt`, `src/Plugin.php` (privacy policy content via `wp_add_privacy_policy_content`,
  HPOS compatibility declaration via `FeaturesUtil::declare_compatibility('custom_order_tables', BOT_INSIGHT_FILE, true)`)
- Create: `docs/pilot-checklist.md`

**Interfaces:**
- Consumes: everything.

- [ ] **Step 1:** readme.txt sections: Description, Privacy (no full IPs, no query values, daily
  salt, `bi_js` cookie is strictly necessary, holds no identifier, session length), "External
  services" (GitHub update check in the direct download only; opt-in range refresh with each host;
  opt-in DB-IP Lite with link and licence), FAQ (page cache, CDN, overhead), Changelog points to
  GitHub releases.
- [ ] **Step 2:** `composer lint && composer analyse && composer test:unit && composer test:integration`
  → all green; `bin/build-zip.sh org` and run Plugin Check locally (`wp plugin check bot-insight` in
  the ddev site) → no errors.
- [ ] **Step 3:** Overhead check on a ddev site with WooCommerce: `ab -n 2000 -c 4` on the shop page
  with and without the plugin active (page cache off); record p95 delta and the plugin's own
  `overhead_p95_us`; both under 0.5 ms. Write the numbers into `docs/pilot-checklist.md` together
  with the pilot steps from the spec (two client shops, compare with access logs; then ~10 sites;
  then all customers).
- [ ] **Step 4: Commit** `docs: add privacy text, external services and pilot checklist`
- [ ] **Step 5:** Open a PR from the work branch to `beta` (pre-release for the pilot); merging to
  `main` later makes the first public release.
