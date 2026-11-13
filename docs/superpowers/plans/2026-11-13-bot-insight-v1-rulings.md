# Bot Insight v1: execution rulings and open items

Decisions taken during subagent-driven execution of `2026-11-13-bot-insight-v1.md` on Robbert's behalf, in order, each with what it costs if wrong. Then parked findings and deferred minors from the per-task and final reviews.

## Rulings

- Ruling: T1 defines `test:integration` script now; it fails until T11 — acceptable — costs nothing.
- Ruling: T2/T3 remote CI verification deferred to the PR at the end (no push of feat/v1 mid-plan); implementers validate YAML syntax locally (actionlint if present, else a YAML parse) — if wrong, CI fails on the PR and gets fixed there.
- Ruling: T18 may extend IpRanges with an optional second (override) dir: `__construct(string $dir, ?string $overrideDir = null)`; override file used when its _meta date is newer — small interface change, costs a T4 test update.
- Ruling: T11 sample table columns = enriched-row fields minus ip/ua, with dict ids for bot, ua_family, params, url_pattern, plus self_us — implementer chooses types; T17 consumes whatever T11 defines (T17 brief gets T11's column list).
- Ruling: T21 manual visual check is done by the controller after the task, not the implementer; T23 ab benchmark attempted on ddev, if ddev site unavailable record the bench-group numbers and mark the pilot check as Robbert's.
- Task 3: Ruling: readme external-services disclosure (reviewer Important; plan puts readme text in T23) — add a minimal "External services" section now since the constraint binds every task and the Updater docblock claims it; T23 extends it — cost: tiny.
- Ruling: phpcs — exclude WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid globally; plan uses camelCase methods throughout — cost if wrong: rename later, Plugin Check does not enforce it.
- Task 8: Ruling: iOS WebViews (no Version/) -> browser family "iOS WebView", outdated 0 — else T9 maps other->unknown_bot and counts real in-app visitors as bots — cost: small.
- Task 10: Ruling (carry to T11): activation + Schema::install() on upgrade seed options `bot_insight_settings` (defaults) and `bot_insight_langs` (['slugs'=>[],'keep'=>[]]) with autoload yes — else get_option misses cost a SELECT per request — cost: none.
- Task 10: Ruling (carry to T11): buffer dir gets a random suffix `bot-insight-<wp_generate_password(12,false)>` under uploads, stored in bot_insight_buffer_dir — defense in depth on nginx where .htaccess is ignored — cost: uninstall must glob bot-insight-*.
- Task 10: Ruling (carry to T11): integration test asserts a plain front-end request adds no extra DB queries from the recorder.
- Task 13: Ruling: promote IPv6/mapped normalisation + suffix leading-dot + int cache cast to Important — false impostor (2) cached a day is a spec anti-goal — cost: small.
- Ruling (T16): Aggregator::aggregate returns array{rows: list<hourly row>, dims: list<string> (final dims per input row, in input order, after overflow)} instead of a bare list — T17 needs the per-row dims for UniqueTracker::add — cost: brief text differs.
- Task 17: Ruling: run lock = MySQL GET_LOCK (auto-release on connection death) replacing add_option lock + 240s takeover — reviewer showed takeover races double count — cost: requires same DB connection, fine in WP.
- Task 21: Ruling: impostor label = plain bot name + badge (JSON keeps impostor:true) — avoids duplicate text — cost: tiny.
- Final review: With fixes (8 Important). Fix wave dispatched with .superpowers/sdd/2026-11-13-bot-insight-v1/final-fix-findings.md. Rulings: skip own cron/loopback/AS-runner requests; self = CPU time; sample weight column DB v2.
- Ruling: residual readme heading regression is load-bearing (WordPress.org external-services disclosure) — smallest fix: resume the fix-wave implementer for a one-line docs restore + "excluding the file write" wording; verified by controller diff read instead of another review round — cost: tiny.
- Parked — background-work skip spoofable (UA WordPress/<host>, public wp-cron.php, AS runner action) — Ruling: matches the controller ruling; hardening (require loopback/private/SERVER_ADDR client IP) deferred to before .org — cost if wrong: a targeted bot could hide from measurement.
- Parked — deactivation final fold with now+60 can close the current UTC day early at 23:59 — Ruling: rare, harmless — cost: slight unique-count error on that day.
- Parked — pruneDictionary NOT IN subquery on unindexed hourly.param_sig_id can hold the fold lock long on big sites after upgrade — Ruling: watch in pilot; add index later — cost: skipped folds for a while once.
- Parked — Plugin Check 29 UnescapedDBParameter warnings (table names) — Ruling: fix with %i before .org, not before beta.
- Parked — overhead budget: own CPU p95 unverified on a real Linux host — Ruling: go/no-go gate in pilot checklist.

## Deferred minors (triaged as can-wait by the final review unless fixed in the fix wave)

- Task 3: minor (deferred): .distignore entries unanchored -> anchor in fix round (cheap, included)
- Task 3: minor (deferred): release.yml head_branch interpolated in shell -> move to env (included in fix round)
- Task 3: minor (deferred): no test for set-version missing-pattern exit path
- Task 3: minor (deferred): org build uses composer update against stale lock (fine while no other runtime deps; maxmind arrives T14 — recheck at final review)
- Task 3: minor (deferred): readme should name actual hosts contacted by PUC (api.github.com, objects/codeload) — T23
- Task 4: minor (deferred): array_filter copy on each range load (negligible); bi_parse only reads `prefixes` key.
- Task 5: minor (deferred): ClientIp::prefix calls inet_pton unsilenced on arbitrary input; no test for array XFF/CF values
- Task 6: minor (deferred): paramNames preg before 40-char cut; own_host with port never matches (caller must pass bare host — T10); no reddit.co test
- Task 7: minor (deferred): leading slash can survive in sanitised rest/ajax names (rest:/x)
- Task 8: minor (deferred): 5ms speed assertion may flake on slow CI
- Task 8: fix round 1/5 (3 addressed in code; re-reviewer wanted an extra generic WKWebView test — my fix message only required Instagram/FBAN/LinkedInApp tests; logged as minor (deferred); commits e96de78..0a47883)
- Task 9: minor (deferred): no test for impostor with ai_* class / two signals without no_js
- Task 10: minor (deferred): fatal-error requests probably not recorded (WP fatal handler exits before later shutdown callbacks) — document; consider own register_shutdown_function later.
- Task 10: minor (deferred): pid reuse with partial line drops 2 records; Woo session cookie name ignores woocommerce_cookie filter.
- Task 10: minor (deferred): assert end(KEYS)===self; no test for enqueueJs behaviour (T11 integration can add)
- Task 11: minor (deferred): uninstall stored dir not constrained to uploads (marker-guarded); uninstall requires vendor/autoload unguarded; maybeUpgrade race can create 2 buffer dirs
- Task 12: minor (deferred): Dictionary caches id 0 on insert failure; Salt stored day in future -> unstable hashes until then
- Task 14: minor (deferred): one test assertion on .tmp path asserts nothing (glob check covers)
- Task 15: minor (deferred): Enricher reads record keys directly (callers must pass decoded records)
- Task 16: minor (deferred): no generator/tie-break/overflow-collision tests; numeric binary key comment
- Task 17: minor (deferred): wpdb reconnect mid-transaction can break atomicity (guard IS_USED_LOCK=CONNECTION_ID before COMMIT); undeletable folding twin blocks cutoff; claim() rename failure bypasses quarantine; hour closed in two parts overcounts ips_seen_once
- Task 18: minor (deferred): do_robots() sets Content-Type header in web context (header_remove after capture); partial range refresh stamps meta & drops prior ok lists; geo download not idempotent on the 3rd; ABSPATH vs get_home_path for robots/htaccess on subdir installs; blank-line parser test missing
- Task 19: minor (deferred): overflow flag should also require param_sig_id=0; GetAddToCart GET-only estimate from sample
- Task 20: minor (deferred): REST fold budget soft due to blocking DNS (consider lower budget/DNS cap for REST); health test asserts types only; export date test can flake at midnight
- Task 21: minor (deferred): make-pot JS translator comments untested; tooltip dismiss vs hover
- Task 22: minor (deferred): duplicate note silently dropped but "Note added" shown; TRUNCATE result unchecked; glob metachar in dir
- Task 23: minor (deferred): pilot checklist should make overhead_p95_us an explicit go/no-go gate; 27 Plugin Check UnescapedDBParameter warnings (use %i) before .org; plan doc lists old Cloudflare URLs

## Open concerns

- Task 23: OPEN CONCERN for Robbert: own overhead p95 2.2ms at -c4 (262us at -c1) vs 0.5ms budget — unexplained.
- Controller visual check (ddev, 360px + 1366px): renders, no console errors. Findings: findability badge text truncated in visitors column; GetAddToCart signal (249, sample-based) inconsistent with params table (153k add-to-cart) — check sample-rate scaling / seed artefact.
