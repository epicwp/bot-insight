=== Bot Insight ===
Contributors: epicwp
Tags: bots, performance, woocommerce, crawlers, analytics
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows how much of your server's PHP time goes to which bots.

== Description ==

Bot Insight measures which bots use your site's PHP time, so you can decide what to do about it.

For every request that reaches WordPress, the plugin notes how long PHP worked on it, which client made it (a person, a verified search or AI crawler, an SEO tool, a script library, an unknown bot or a visitor that only claims to be a known crawler), and what kind of page was asked for (product, category, search, cart, filtered listing, REST, feed and so on). Every five minutes a background job folds these notes into hourly totals in the database.

The dashboard under Tools > Bot Insight shows:

* the share of PHP time per client group and per bot, with request counts and trends;
* which page types and URL patterns the bots spend their time on, including filter and parameter combinations;
* visitors that pretend to be Google, Bing or another crawler but come from other networks;
* factual signals, such as a bot that crawls filtered listings or a sudden rise in uncached requests;
* the plugin's own overhead per request.

The hourly data can be exported as JSON. Everything stays on the site.

Version 1 measures and reports only. It does not block, throttle or change any request.

How a request is measured:

* Recording a request is a short append to a buffer file in a private uploads directory. There is no database write and no remote call during a visitor's request.
* Search and AI crawlers are verified against the IP ranges their operators publish. A copy of these lists ships with the plugin.
* WooCommerce is supported, including High-Performance Order Storage (HPOS). Of the orders, the daily job only counts recent orders per billing country. No order or customer details are stored.

== Privacy ==

Bot Insight is built to measure bots, not people, and stores no personal data.

* No full IP addresses are stored. The visitor's IP address and user agent exist only in a buffer file until the next fold, normally a few minutes. If processing fails they are kept at most 48 hours: older buffer files are deleted unprocessed, and a file that cannot be processed is set aside and deleted after 24 hours. Deactivating the plugin processes what it can and deletes the remaining buffer files. The fold keeps a hash of the IP address made with a random salt that changes every day (the old salt is discarded, so hashes of earlier days cannot be linked or reversed), and the network prefix (/24 for IPv4, /48 for IPv6).
* User agents are reduced to a client family, for example "Googlebot" or "Chrome", plus a flag for outdated browser versions.
* URLs are reduced to a pattern. Of the query string only parameter names are kept, never their values.
* No user accounts, email addresses, order details, form contents or other personal data are stored. Logged-in administrators are left out by default.
* The plugin sets one cookie, `bi_js`, with the value `1`, from a short inline script, so it can tell browsers that run JavaScript from bots that do not. The cookie is strictly necessary for this measurement, lasts for the browser session, and contains no identifier.
* All data stays in the site's own database and uploads directory. Nothing is sent to EpicWP or any other party. Data older than the retention setting (default 400 days) is removed, and Settings has a button that deletes all collected data (Tools > Bot Insight > Settings).

The plugin adds a suggested paragraph to the privacy policy guide under Settings > Privacy.

== External services ==

Bot Insight sends no site data, visitor data or statistics to any service. It makes the outgoing requests below, all from cron or an admin screen and never during a visitor's request.

= GitHub (update check, direct-download build only) =

The build that EpicWP customers download directly checks GitHub for new releases, so WordPress can offer updates for it. The WordPress.org build does not contain this code and updates through WordPress.org.

* Hosts: api.github.com (release information of https://github.com/epicwp/bot-insight); github.com and objects.githubusercontent.com (download of the release zip, only when an update is installed).
* When: about twice a day, during WordPress's update checks, and when an administrator checks for updates.
* What is sent: a standard HTTPS request with WordPress's user agent, which contains the WordPress version and the site's URL, as in WordPress's own update checks. No other data is sent.
* Pre-releases are offered only when the constant `BOT_INSIGHT_BETA` is set to `true`.
* Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

= Crawler and CDN IP ranges (off by default) =

When the site owner turns on "Refresh crawler IP ranges" in the settings (Tools > Bot Insight > Settings), a daily background job downloads, at most once a week, the IP ranges that crawler operators and Cloudflare publish, so the plugin can verify crawlers with current lists. Without this setting the lists shipped with the plugin are used.

* What is sent: a plain HTTPS GET request with the user agent "bot-insight-range-updater". No site data is sent.
* Google, developers.google.com: googlebot.json, special-crawlers.json and user-triggered-fetchers.json under /static/search/apis/ipranges/. Terms: https://policies.google.com/terms, privacy: https://policies.google.com/privacy
* Microsoft Bing, www.bing.com: /toolbox/bingbot.json. Terms: https://www.microsoft.com/servicesagreement, privacy: https://privacy.microsoft.com/privacystatement
* Apple, search.developer.apple.com: /applebot.json. Terms: https://www.apple.com/legal/internet-services/terms/site.html, privacy: https://www.apple.com/legal/privacy/
* OpenAI, openai.com: /gptbot.json, /searchbot.json and /chatgpt-user.json. Terms: https://openai.com/policies/terms-of-use, privacy: https://openai.com/policies/privacy-policy
* Perplexity, www.perplexity.ai: /perplexitybot.json and /perplexity-user.json. Terms: https://www.perplexity.ai/hub/legal/terms-of-service, privacy: https://www.perplexity.ai/hub/legal/privacy-policy
* Cloudflare, api.cloudflare.com: /client/v4/ips (no API key needed). Terms: https://www.cloudflare.com/website-terms/, privacy: https://www.cloudflare.com/privacypolicy/

= DB-IP Lite GeoIP databases (off by default) =

When the site owner turns on "Download the GeoIP databases" in the settings, the plugin downloads the free DB-IP Lite country and ASN databases, so countries and networks of visitors can be looked up on the site itself. Lookups never leave the site.

* Host: download.db-ip.com (files dbip-country-lite and dbip-asn-lite under /free/).
* When: from the daily background job, on the 3rd of each month, and on any day the databases are missing (so the first download follows within a day of turning the setting on).
* What is sent: a plain HTTPS GET request for the database file. No site data is sent.
* Licence: the databases are licensed under Creative Commons Attribution 4.0 (https://creativecommons.org/licenses/by/4.0/). Attribution: IP geolocation by DB-IP (https://db-ip.com).
* Terms and privacy: https://db-ip.com/about/

== Frequently Asked Questions ==

= Does it work with a page cache? =

Yes, but it only sees requests that reach PHP. Pages served by a page cache, a CDN or the web server's own cache never start WordPress and are not counted. That is usually what you want to know: the dashboard shows the PHP time that bots really cost. The `bi_js` inline script works on cached pages as well.

= Does it work behind Cloudflare or another CDN? =

Yes. The plugin reads the visitor's IP address from the CDN's header only when the request comes from that CDN's published IP ranges, so the header cannot be forged by others. Requests that the CDN answers from its cache never reach the site and are not counted.

= How much overhead does it add? =

The target is under 0.5 ms per request at the 95th percentile. Recording is one append to a buffer file at the end of the request; the heavier work (classification, verification, aggregation) runs every five minutes in the background. The dashboard shows the measured overhead of the plugin itself: the CPU time the recorder uses per request, at the 95th percentile.

= Are the site's own cron and background jobs counted? =

No. Your site's own cron and background jobs are not counted: WP-Cron runs, the Action Scheduler background runner and WordPress's loopback requests to its own address (user agent `WordPress/<version>; <this site>`) are left out, so they never show up as bot time. Requests from other WordPress sites are counted as usual.

= Does it block bots? =

No. Version 1 only measures and reports.

= How do I remove all data? =

Tools > Bot Insight > Settings > Delete all data empties every Bot Insight table and removes the buffer files. The settings stay. Deleting the plugin from the Plugins screen removes its tables, options and files.

== Changelog ==

See the releases on GitHub: https://github.com/epicwp/bot-insight/releases
