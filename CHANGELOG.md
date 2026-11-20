# Changelog

All notable changes to `angeo/module-ai-bots` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-26

First release. One list of AI bots for all Angeo modules, and checks that a
request really comes from the bot it names.

### Added

- **`etc/bots.json`**: 38 AI crawlers and agents from 21 operators, with
  category, robots.txt token, user-agent tokens, IP list, Web Bot Auth
  origins, robots.txt and Crawl-delay behaviour, documentation link and the
  date each entry was checked. Merges the three lists that
  `module-robots-txt-aeo` 4.1, `module-aeo-audit` 4.3 and
  `module-agent-activity` 1.2 kept; ids are those of `module-robots-txt-aeo`,
  so its saved settings stay valid. JSON Schema in `etc/bots.schema.json`.
- Also in the list: MCP client names, AI referrer hosts, `utm_source`
  values, and test tools to leave out of findings.
- **Identification** from headers (`BotIdentifierInterface`): user agent
  first, then a known Web Bot Auth signing host, then an unlisted signed
  agent, then "unknown".
- **IP list check** against vendor lists, with `behind_proxy` when Magento
  sees a private address and a note when a list is shared by several bots.
- **Web Bot Auth** per `draft-ietf-webbotauth-httpsig-protocol-00`:
  dictionary and legacy `Signature-Agent`, `directory` and `jwks_uri`
  types, `web-bot-auth` tag, coverage, time window, JWK thumbprint keyid,
  Ed25519. Own RFC 8941 structured-field parser, so component parameters
  such as `"signature-agent";key="sig1"` work.
- **Daily refresh** (cron 04:17 and `angeo:bots:refresh`) of IP lists and
  key directories, SSRF-safe; a failed download keeps the previous copy.
- **CLI**: `angeo:bots:list`, `angeo:bots:refresh`, `angeo:bots:status`,
  `angeo:bots:verify-ip`, `angeo:bots:verify-request`.
- **`BotTrafficReaderInterface`** with an empty default, so
  `module-aeo-audit` can read traffic without depending on
  `module-agent-activity`.

### Corrected against vendor documentation (26 October 2026)

- Amazonbot is `training`: Amazon states its data "may be used to train
  Amazon AI models". `module-aeo-audit` had it as search.
- Added Amzn-SearchBot (search) and Amzn-User (user fetch), documented by
  Amazon next to Amazonbot.
- Applebot IP list: `https://search.developer.apple.com/applebot.json`.
