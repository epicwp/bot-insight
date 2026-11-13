# Bot Insight v1: design

Status: approved with decisions, 2026-11-13 (see "Decisions" at the end). Working name "Bot Insight" (check availability on
WordPress.org before release). Builds in its own new repository; this file moves there.

## Intent

Many WordPress and WooCommerce sites lose most of their PHP capacity to bots without knowing it. On
one WooCommerce shop, 82% of PHP time went to bots in one week: a proxy botnet opening `?add-to-cart=` links,
filter-URL crawling, AI training crawlers and requests that bypassed the CDN. The owners only saw "the
site is slow".

Bot Insight makes this visible inside wp-admin. It is the first step of a product loop:

1. Measure (this version).
2. Advise: detectors that turn the data into findings (later).
3. Apply: one-click fixes in the best available layer: robots.txt, Cloudflare API, .htaccess, nginx
   snippet, early PHP gate (later).
4. Verify: before/after comparison per applied fix (later).

v1 is only step 1, but collects every field that steps 2 to 4 will need, because history cannot be
filled in afterwards.

Audience: Robbert's ~300 existing plugin customers (free download, by email), then WordPress.org.

Success for a site owner after one week: they see what share of their server time goes to which bots,
and which of those matter for findability (Google, Bing, Apple, ChatGPT, Claude, Perplexity).

## Goals

- Measure every request that reaches PHP: cost, shape, client, path to the server, side effects.
- Classify clients into a default set: people, search engine, AI search, AI assistant (user fetch),
  AI training, SEO tool, social preview, monitoring, feed reader, script library, unknown bot.
- Verify claimed search and AI bots (published IP ranges, reverse plus forward DNS).
- Store compact hourly aggregates, a small raw sample, an event timeline and a daily site snapshot.
- A dashboard in wp-admin: key figures, PHP hours per day by class, who is visiting, what bots ask
  for, where unknown bots come from, factual signals.
- Export of hourly totals as a JSON file the owner can send to a developer.

## Non-goals (v1)

- Blocking, challenging or rate limiting anything.
- Writing robots.txt, .htaccess, nginx or Cloudflare rules.
- Advice text or one-click fixes (the dashboard may show factual signals, no buttons).
- Multisite networks (single site only).
- Sending data anywhere automatically.

## Anti-goals

- The plugin itself slows the site down. Budget: under 0.5 ms added per request at p95 on a normal
  host, measured and shown in the dashboard.
- A database write per request.
- Storing personal data: full IP addresses, query values, user identities, order details.
- Hiding a phone-home or tracking in the plugin (WordPress.org rejects it, customers would distrust it).
- Misclassifying Google, Bing or AI search bots as bad (the later fixes depend on this being right).

## Constraints

- GPL-2.0-or-later, WordPress.org plugin guidelines from day one (no obfuscation, no external calls
  without disclosure and opt-in, escaped output, nonces, capabilities).
- PHP 7.4+ and WordPress 6.2+ (the builder may raise this if a needed API requires it).
- No build step needed to run; plain PHP, vanilla JS and CSS for the dashboard, no external CDNs.
- Works with and without WooCommerce, with and without a page cache, on Apache, LiteSpeed and nginx.
- Code, comments and UI text in English.

## How it works (decided)

### Capture

- A regular plugin, hooking `shutdown` (late priority). Timing uses
  `$_SERVER['REQUEST_TIME_FLOAT']`, so the plugin does not need to load early.
- Per request it computes the fields of the data model (see "Fields" below) and appends one compact
  line to a buffer. No database write.
- Requests served by a page cache drop-in never load plugins and are not counted. The dashboard says
  so plainly.

### Buffer and folding

- Buffer (see Builder decisions: files only in v1): append-only files in `wp-content/uploads/bot-insight/` (one file
  per minute, `flock`, folder protected with `index.php` and a deny rule).
- The buffer holds the raw client IP for at most the fold interval, so bots can be verified; it is
  hashed and discarded at fold time.
- Folding every 5 minutes via Action Scheduler when present, else WP-Cron, and also when the dashboard
  opens. Folding writes the hourly table, the sample table and the dictionary, then deletes the buffer.

### Verification

- Published IP ranges for Google, Bing, Apple, OpenAI, Anthropic, Perplexity and Cloudflare are
  bundled as a snapshot in the plugin and refreshed weekly only if the owner opts in (disclosed
  external call).
- For claimed bots without ranges: reverse plus forward DNS at fold time, cached per IP for 24 hours.
  Never during a visitor's request.

### JavaScript signal

- No beacon request. A tiny front-end script sets a first-party cookie (`bi_js=1`, session length,
  no identifier). Later requests that carry it are marked `js_seen`. Bots that never run JavaScript
  never carry it. Zero extra requests.

### Country and network type

- Use a CDN country header when present (`CF-IPCountry`).
- Else an optional, opt-in monthly download of DB-IP Lite country and ASN databases (CC BY 4.0,
  attribution in the dashboard). Without it country and ASN are `unknown`.

### Storage

- `{prefix}botinsight_hourly`: dimensions `hour`, `client_class`, `bot_id`, `route`,
  `param_sig_id`, `lang`, `country`, `asn_type`, `via_cdn`, `verified`, `js_seen`, `status_class`,
  `uncached_reason`; metrics `requests`, `php_ms_sum`, `cpu_ms_sum`, `db_queries_sum`,
  `mem_peak_max`, `slow_2s`, `unique_ips`, `ips_seen_once`, `top_ip_share`, `sessions_created`,
  `cart_changes`, `orders`. Per hour the top 200 dimension combinations are kept, the rest folded
  into `other`.
- `{prefix}botinsight_dict`: `id`, `type` (bot, ua_family, param_sig, url_pattern, asn_org), `value`.
- `{prefix}botinsight_sample`: 1 in N requests, adaptive to about 5,000 rows a day, per-request fields
  without query values or full IP. Kept 14 days.
- `{prefix}botinsight_events`: plugin, theme and core updates, robots.txt changes, page cache or CDN
  changes, manual notes.
- `{prefix}botinsight_snapshot`: one row a day with the site context (below).
- Retention: hourly 400 days, sample 14 days. Uninstall removes all tables, options and files.

### Fields per request

Cost: `php_ms`, `cpu_ms` (getrusage delta), `mem_peak_kb`, `db_queries`, `status`, `uncached_reason`
(query string, POST, logged-in cookie, cart/session cookie, DONOTCACHEPAGE, no page cache).

Shape: `method`, `route` (home, shop, product_cat, product, search, page, post, feed, sitemap, robots,
rest + namespace, ajax + action, wc-ajax + endpoint, cron, admin, login, xmlrpc, 404, other),
`param_names` (sorted names, never values), `page_depth` bucket, `lang` (Polylang, WPML,
TranslatePress prefix), `url_pattern` (ids and slugs collapsed), `referer_type` (none, own site,
search engine, social, AI assistant, other).

Client: `ua_family` (bot name, browser + major, or library), `ua_outdated`, `client_class`,
`verified`, `ip_hash` (HMAC, daily rotating salt), `ip_prefix` (IPv4 /24, IPv6 /48), `country`, `asn`,
`asn_type` (residential, mobile, hosting, CDN, unknown), `header_flags` (Accept-Language, Sec-Fetch-*,
Sec-CH-UA, Accept text/html, any cookie, consent cookie, Woo session cookie, cart cookie, logged in),
`js_seen`.

Path: `via_cdn` (CDN headers and REMOTE_ADDR inside the CDN ranges), `client_ip_source`.

Side effects: `session_created`, `cart_changed`, `order_created`, `payment_callback`.

### Daily snapshot

WordPress, WooCommerce and PHP versions; active plugins and theme; page cache plugin and drop-in;
object cache; server software (`SERVER_SOFTWARE`, .htaccess in use); CDN detected; robots.txt hash and
parsed groups; sitemap URL; language plugin and languages; customer billing countries of the last 90
days (counts only); allowed shipping countries; `sys_getloadavg()` hourly samples; the plugin's own
measured overhead.

### Disguised bots (Claude's call)

Clients with a normal browser user agent are not all people. v1 adds the class `suspected automated`
for browser-UA clients that match objective, measurable signals: no `js_seen`, no Sec-Fetch and no
Accept-Language headers, GET `add-to-cart`, IP seen only once that day, outdated browser major. The
dashboard counts `suspected automated` on the bot side of the headline and always shows which signals
fired. This is measurement, not advice: no thresholds that trigger actions.

### Unique client counts (Claude's call)

The set of `ip_hash` values for the current day is kept (APCu or a buffer file) until the daily salt
rotates, then discarded. It yields exact per-hour and per-day `unique_ips` and `ips_seen_once`; the
daily figures go into a small `{prefix}botinsight_daily` table. Periods longer than a day show the sum
of daily uniques, labelled "client-days".

### Bot list

A versioned `bots.json` in the plugin: name, user-agent patterns, class, owner, verification method
(range URL or DNS suffixes), findability relevance. Updated with plugin releases in v1.

### Dashboard (Tools > Bot Insight)

As in the mockup "Bot Insight admin dashboard" (claude.ai artifact GUQVLJaTNyM4dH2xBbvZC5), minus the
fix buttons: banner with the bot share of PHP time, four key figures, PHP hours per day by class,
visitor table (requests, PHP hours, verified), parameters, countries of unknown bots, factual signals,
privacy note with the measured overhead, period selector (24 h, 7 d, 30 d), export button. Data via
admin-only REST endpoints with nonces; charts in plain SVG/CSS.

### Settings

Sampling rate (auto by default), exclude logged-in administrators (default on), opt-in for range and
GeoIP updates, data retention, delete all data.

## Left to the builder

Class and file layout, table indexes, the exact fold algorithm and cardinality guard, the URL-pattern
collapsing rules, the classifier's matching order, test strategy (PHPUnit with the WordPress test
suite; classifier fixtures may be built from real client-shop access log lines with IPs replaced),
and the overhead measurement method.

## Pilot

Install on two client shops with known traffic first (known traffic, compare with access logs), then
about 10 customer sites, then all 300 by email with an unsubscribe link.

## Direction for later versions (agreed in chat, not built in v1)

- Bot policy: a default set. Allowed: people, search engines (Google, Bing, Apple), AI search and AI
  assistant fetches (ChatGPT, Claude, Perplexity). Off by default: AI training crawlers, SEO tools,
  scrapers, script libraries, unknown and suspected-automated bots. The owner can allow or block per
  bot or per group.
- One policy, translated to the strongest layer the site has, chosen from the snapshot:
  1. robots.txt (always), 2. CDN API such as Cloudflare (owner connects a token), 3. .htaccess on
  Apache or LiteSpeed (one click), 4. nginx: ready snippet plus host-specific integrations,
  5. early PHP gate as universal fallback (`auto_prepend_file` where allowed, else mu-plugin).
  Verified search and AI search bots are the first exception in every layer.
- Simulate before apply: show what a rule would have hit in the last 7 days, including orders and
  verified crawlers. After apply: before/after comparison, automatic rollback when orders, payment
  callbacks or verified crawlers drop. A CDN integration also reads rule hit counts, because blocked
  traffic never reaches PHP.

## Decisions (Robbert, 2026-11-13; answers to the builder check)

1. Releases and updates: semantic-release on GitHub (Conventional Commits decide the version, the
   changelog and a GitHub release with the plugin zip). Two build flavours from one tag: `direct`
   (contains an updater that reads the GitHub releases of the public repository; for the ~300
   customers) and `org` (no updater, for WordPress.org later). CI runs PHPCS (WordPress Coding
   Standards + PHPCompatibilityWP), PHPStan and Plugin Check on the `org` zip from the first commit.
   The updater's call to github.com is disclosed in the readme.
2. The `bi_js=1` cookie is set immediately as strictly necessary (no identifier, session length),
   explained in the privacy text.
3. Steps 2 to 4 are free. How to earn money from it is still open. v1 only collects data, but is
   prepared for v2 (advice): a read-only `Reports` query layer, a `Signal` interface that the
   dashboard's factual signals already implement (v2 detectors implement the same interface), and an
   action `bot_insight_folded` after every fold.

## Builder decisions made while planning (2026-11-13)

- Buffer: files only in v1, one file per minute per PHP process (`buffer-<minute>-<host>-<pid>.php`,
  first line `<?php exit; ?>`), so no lock contention and no APCu pitfall (APCu is not shared with
  WP-CLI cron runs). APCu may come back later if measurements ask for it.
- User agents are classified at fold time, not during the request (the raw UA, like the raw IP,
  lives only in the buffer). Keeps the request path cheap.
- `{prefix}botinsight_daily` (day, client_class, requests, php_ms_sum, unique_ips, ips_seen_once) is
  part of Storage. Days are UTC; the dashboard shows hours in the site's timezone.
- `unique_ips`, `ips_seen_once` and `top_ip_share` on hourly rows are per hour (written when the hour
  closes); "seen once that day" is exact only in the daily table.
- A claimed search or AI bot that fails verification is stored as `unknown_bot` with `verified = no`
  and its claimed `bot_id`, so the dashboard can show "fake Googlebot".
- Route list gets `wc-api` + endpoint; `payment_callback` = a request that fired
  `woocommerce_api_request`.
