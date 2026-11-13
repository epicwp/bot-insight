# Bot Insight v1: pilot checklist

## Measured overhead (local, 2026-11-13)

Setup: ddev, PHP 8.3, nginx-fpm, MariaDB 10.11, WordPress 7.1.4, WooCommerce with 12 sample
products, no page cache, Apple Silicon laptop. Bot Insight installed from the `org` zip.
Load: `ab -n 2000 -c 4 http://bot-insight-dev.ddev.site/shop/`, after a 200-request warm-up per run.

Response times from `ab` (ms; `ab` reports whole milliseconds):

| Run                  | p50 | p95 | p99 | Requests/s |
| -------------------- | --- | --- | --- | ---------- |
| Plugin inactive, 1   | 74  | 101 | 137 | 50.7       |
| Plugin inactive, 2   | 73  | 100 | 173 | 50.7       |
| Plugin active, 1     | 80  | 107 | 133 | 47.4       |
| Plugin active, 2     | 75  | 98  | 117 | 50.6       |

p95 delta: +6 ms and -2 ms (mean +2 ms). The run-to-run spread on the same setup is larger than
the 0.5 ms budget, so `ab` on a laptop cannot confirm or reject the budget; it only shows that
there is no large regression.

The plugin's own measurement at the time (`self_us`, then the wall time of collect, build and encode
in the shutdown hook, without the file append), from the sample table after a fold:

| Load                          | Requests | p50    | p95      | p99      |
| ----------------------------- | -------- | ------ | -------- | -------- |
| `ab -c 4` (all runs above)    | 4,406    | 229 µs | 2,225 µs | 3,635 µs |
| `ab -n 500 -c 1`              | 500      | 190 µs | 262 µs   | 434 µs   |

`overhead_p95_us` of the daily snapshot for that day: 2,225 µs (the `-c 4` load).

Sequential requests were within the budget (p95 0.26 ms). Under four parallel requests, about 11%
of the requests took more than 1 ms in the recorder, with the same PHP and CPU time per request as
the rest, which pointed to wall-clock preemption on a busy Docker host. Since then `self_us` is the
recorder's CPU time (user plus system, from `getrusage`), so preemption no longer counts, and the
request path does less filesystem work (no directory check, one `fopen` per append, range lists
parsed once per process).

Budget: under 0.5 ms per request at p95 (design spec). Laptop numbers are noisy; the plugin's
own measurement is the number to watch on pilot sites (dashboard footer, "Measured overhead",
CPU time).

## Go/no-go gate

Before step 2 (about 10 sites): on **both** pilot shops of step 1, `overhead_p95_us` of the daily
snapshot (CPU time) must be **at most 500 µs** on every full day after the first. Read it in the
dashboard footer or with
`wp db query "SELECT day, JSON_EXTRACT(data, '$.overhead_p95_us') FROM wp_botinsight_snapshot ORDER BY day DESC LIMIT 7"`
(adjust the table prefix). If either shop is above 500 µs: no-go. Profile
`Recorder::onShutdown()` on that shop first and repeat step 1 with the fix.

## Pilot steps

1. **Two client shops with known traffic.** Install the `direct` zip. After 2 to 3 days compare the
   dashboard with the server's access logs for the same hours (see the checklist below).
2. **About 10 customer sites**, spread over hosting types (shared Apache/LiteSpeed, nginx VPS,
   behind Cloudflare, with and without a page cache). Run the checklist once after a week.
3. **All customers by email** (about 300), with an unsubscribe link, once steps 1 and 2 are clean.

## Checklist per pilot site

- [ ] Plugin activates without notices; Tools > Bot Insight opens; Site Health shows no new issues.
- [ ] Within 10 minutes the dashboard shows data (the fold runs every five minutes through Action
      Scheduler or WP-Cron). If not: check that WP-Cron or a system cron runs.
- [ ] Cron runs as the PHP user. A system cron (`wp cron event run --due-now` or a `curl` of
      `wp-cron.php`) must run as the same user as PHP-FPM or the web server, or the fold cannot
      read, rename and delete the buffer files the web requests wrote. Check that the buffer
      directory empties after a fold and that the dashboard shows no fold error.
- [ ] Request totals per hour are within about 10% of the access log's PHP requests for the same
      hour (exclude static files and requests served by the page cache or CDN).
- [ ] The top bots match the access log's top user agents; a few Googlebot and Bingbot hits are
      shown as verified; a forged Googlebot UA from a non-Google network is listed as an impostor.
- [ ] Behind Cloudflare: client IPs come from `CF-Connecting-IP` (countries and networks look real,
      not all Cloudflare).
- [ ] Measured overhead in the dashboard footer is under 0.5 ms (p95).
- [ ] No database write per visitor request: the buffer directory under uploads
      (`bot-insight-<random>`) gets one file per minute per PHP process and is emptied by the fold.
- [ ] Checkout, cart, payment callbacks and the REST API work as before (place a test order where
      possible).
- [ ] The `bi_js` cookie is set in a browser and the privacy policy guide (Settings > Privacy) shows
      the Bot Insight paragraph.
- [ ] No external requests unless an opt-in was switched on (check with a request-logging plugin or
      the host's outbound log if available).
- [ ] Export (JSON) downloads and contains no full IPs and no query values.
- [ ] Note anything odd per site: hosting, cache, CDN, PHP version, multilingual plugin.

Known limitation: WordPress installed in a subdirectory (site address and WordPress address
differ, or the site lives under `/shop/`) is not tested in v1. URL patterns and the loopback
check use the home URL's host only; check the route and URL-pattern columns by hand on such a
site before trusting them.

## Rollback

- Deactivate the plugin: recording stops at once; the fold and daily jobs are unscheduled.
- Delete the plugin from the Plugins screen to drop its tables, options and buffer directory.
- To keep the plugin but drop the data: Tools > Bot Insight > Settings > Delete all data.
- To go back to an earlier version: upload the previous release zip from GitHub
  (Plugins > Add New > Upload, "Replace current with uploaded").

## Updates and beta channel

- Pilot sites run the `direct` zip. It checks the public GitHub releases of
  `epicwp/bot-insight` about twice a day and updates through the normal WordPress update screen.
- Pre-releases (`1.0.0-beta.N`, published from the `beta` branch) are only offered to sites that
  define `define( 'BOT_INSIGHT_BETA', true );` in `wp-config.php`. Set it on pilot sites in steps 1
  and 2; leave it out for the general rollout in step 3.
- The `org` zip (WordPress.org) has no updater; it updates through WordPress.org.
