# 1.0.0 (2026-11-13)


### Bug Fixes

* always ship js cookie script and measure encode in self ([d16f417](https://github.com/epicwp/bot-insight/commit/d16f41797f23673d8f63d08194b9b08d076d2ec2))
* bound how long raw buffer data stays on disk ([04bd5a1](https://github.com/epicwp/bot-insight/commit/04bd5a11ee085ac04247ee0db0bbca02cef6a9dd))
* collapse random parameter names and prune the dictionary ([4b4ea5d](https://github.com/epicwp/bot-insight/commit/4b4ea5dfbd7c32415b3a358e51c5c5c5f1b8b41d))
* dashboard label, plurals and accessibility polish ([ab98cc2](https://github.com/epicwp/bot-insight/commit/ab98cc2eb7a69b350a2bbe38f171843b92a76251))
* disclose update check and harden build config ([4691261](https://github.com/epicwp/bot-insight/commit/4691261501f80b2396657aeae0aeac699af43fcc))
* keep the report fold short ([95712b0](https://github.com/epicwp/bot-insight/commit/95712b00ec19f125c132b31010c035a3907ec290))
* load the updater only in admin, cron and cli ([28d218d](https://github.com/epicwp/bot-insight/commit/28d218d75adf85139c3a7b48edea4cd012314e9d))
* make folding safe under concurrency and failures ([f33795f](https://github.com/epicwp/bot-insight/commit/f33795f01569da4050532956db6f8e815952f627))
* normalise ips and suffixes in bot verification ([9b6c179](https://github.com/epicwp/bot-insight/commit/9b6c1793d6b7ef81cf9bfd0a9dd208d954901a6a))
* salt, dns cache key, dictionary and self-heal minors ([bca23e3](https://github.com/epicwp/bot-insight/commit/bca23e35346290ddac6b254cd73d557e6128962d))
* separate impostors and overflow in reports ([10cfaae](https://github.com/epicwp/bot-insight/commit/10cfaae9ec063c5bd3cb8e35a587f9e80f4de818))
* skip the site's own cron and loopback requests ([3841ef5](https://github.com/epicwp/bot-insight/commit/3841ef5592f93d2502a0592e641fef69655fed2a))
* tighten bot patterns and ip range edge cases ([e5e2414](https://github.com/epicwp/bot-insight/commit/e5e2414dbaabf790da7233cf717c2772bd848456))
* treat ios webviews as browsers and tighten java token ([0a47883](https://github.com/epicwp/bot-insight/commit/0a47883e9ba6412e94f43a553666a003ee42ce63))
* unslash request values in the background-work check ([8cb6ccc](https://github.com/epicwp/bot-insight/commit/8cb6ccc109f69d4dfe3faee7db7d3de798a36897))
* validate and cap geo database downloads ([7e610d6](https://github.com/epicwp/bot-insight/commit/7e610d6878bc46cc236a824732d2cea38a0a3604))
* weight sample rows and adapt rate within the day ([e790e89](https://github.com/epicwp/bot-insight/commit/e790e891f166fe16fd72ad6a74c3e6a0f364568b))


### Features

* add admin-only report and export endpoints ([8ff6155](https://github.com/epicwp/bot-insight/commit/8ff615563af80275d6367a8e0ff731b132a2d97e))
* add bundled bot list and ip range matching ([00df131](https://github.com/epicwp/bot-insight/commit/00df1317b9651fa23a9c03e7c585379c64933b06))
* add country and network type lookup ([632bf92](https://github.com/epicwp/bot-insight/commit/632bf927b457afcc95eab277bbcaac336eb5ff75))
* add database schema, installer and uninstall ([ec1983a](https://github.com/epicwp/bot-insight/commit/ec1983ac5a3a3942dee820d32691911a785d5148))
* add dictionary and daily rotating ip salt ([fa966fa](https://github.com/epicwp/bot-insight/commit/fa966fae6e14ffaae17d11b41f9b5c22d0379712))
* add read-only reports and factual signals ([d5b1e8d](https://github.com/epicwp/bot-insight/commit/d5b1e8dd09430ea9af31a50d12ebbce8c2d18c1c))
* add request shape helpers ([9523d49](https://github.com/epicwp/bot-insight/commit/9523d491fca1ece330f639cf8a1ba124e3f7b0c8))
* add settings, opt-ins and delete-all ([efed255](https://github.com/epicwp/bot-insight/commit/efed2559fcc87a10b41b8fa6ccfb85c63ac0dbe9))
* add wp-admin dashboard ([0a22747](https://github.com/epicwp/bot-insight/commit/0a2274784e710c5b783a7841fdc2eb7494ce0980))
* aggregate rows into hourly combinations ([72eccce](https://github.com/epicwp/bot-insight/commit/72eccceda5a1a4c8369c18138d432b833412880a))
* classify clients and flag suspected automation ([f19022f](https://github.com/epicwp/bot-insight/commit/f19022fd2b7fbcf87e193f5bd201c17c4f74bd06))
* enrich buffered records at fold time ([d5fb371](https://github.com/epicwp/bot-insight/commit/d5fb371a702a00455e91cff351cef328dc1d8625))
* fold buffer into hourly, sample and daily tables ([51293ff](https://github.com/epicwp/bot-insight/commit/51293fff124feb014d781ca7232f558fdea4cdf6))
* parse user agents into families ([e96de78](https://github.com/epicwp/bot-insight/commit/e96de7806cf46bc5a8bb87ec68c3a30310e6c1f8))
* record each php request into a file buffer ([3e7edcd](https://github.com/epicwp/bot-insight/commit/3e7edcdeff6711966046f9c05e242e4274858ab6))
* record site events and daily snapshot ([0def78b](https://github.com/epicwp/bot-insight/commit/0def78b2cbb04b8920f88675635b4a6c093c9eeb))
* resolve client ip, source and cdn path ([0729eaf](https://github.com/epicwp/bot-insight/commit/0729eaff63e695962a49a08736896fa6e3276cec))
* resolve route and uncached reason ([31863f6](https://github.com/epicwp/bot-insight/commit/31863f6c4e7ab7254ce85f618764bad222804868))
* verify claimed bots by ip ranges and dns ([d6a0834](https://github.com/epicwp/bot-insight/commit/d6a08343886064eb594afad6189b437d63977abb))


### Performance Improvements

* trim filesystem work on the request path and measure cpu overhead ([d003a40](https://github.com/epicwp/bot-insight/commit/d003a406dc7c4f20cae017b1f79d446ba71f6036))

# Changelog
