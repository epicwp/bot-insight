=== Bot Insight ===
Contributors: epicwp
Tags: bots, performance, woocommerce, crawlers, analytics
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows how much of your server's PHP time goes to which bots.

== Description ==

Bot Insight measures which bots use your site's PHP time, so you can decide what to do about it.

== External services ==

The direct-download build of this plugin checks https://github.com/epicwp/bot-insight for new releases. This happens during WordPress's normal update checks. The request is a standard HTTP request with the site's WordPress/PHP user agent; no site data is sent. Service: GitHub, https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

The WordPress.org build makes no such call and updates through WordPress.org.

Bot range refresh (off by default; setting "range_updates"). When the site owner turns it on, a daily background job downloads, at most once a week, the published IP ranges of search and AI crawlers and of Cloudflare, so the plugin can verify that a visitor claiming to be such a crawler really is. No site data is sent; these are plain GET requests with the user agent "bot-insight-range-updater". Sources: Google (https://developers.google.com/search/apis/ipranges/, privacy: https://policies.google.com/privacy), Microsoft Bing (https://www.bing.com/toolbox/bingbot.json, privacy: https://privacy.microsoft.com/privacystatement), Apple (https://search.developer.apple.com/applebot.json, privacy: https://www.apple.com/legal/privacy/), OpenAI (https://openai.com/gptbot.json and related lists, privacy: https://openai.com/policies/privacy-policy), Perplexity (https://www.perplexity.ai/perplexitybot.json, privacy: https://www.perplexity.ai/hub/legal/privacy-policy) and Cloudflare (https://www.cloudflare.com/ips/, privacy: https://www.cloudflare.com/privacypolicy/).

GeoIP databases (off by default; setting "geo_updates"). When the site owner turns it on, the plugin downloads the free DB-IP Lite country and ASN databases (CC BY 4.0) from https://download.db-ip.com/ once a month, so visitor countries and networks can be looked up on the site itself. No site data is sent. Terms and privacy: https://db-ip.com/about/
