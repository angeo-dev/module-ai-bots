# Angeo AI Bots for Magento 2

[![CI](https://github.com/angeo-dev/module-ai-bots/actions/workflows/ci.yml/badge.svg)](https://github.com/angeo-dev/module-ai-bots/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-ai-bots)](https://packagist.org/packages/angeo/module-ai-bots)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%E2%80%93%208.5-777bb4)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%20%E2%80%93%202.4.9-f26322)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**One list of AI crawlers and agents, and a way to check that a request really comes from the bot it names.**

Anyone can send `User-Agent: GPTBot`. This module tells you whether the
request came from OpenAI. It checks the IP lists that vendors publish, and
[Web Bot Auth](https://datatracker.ietf.org/doc/html/draft-ietf-webbotauth-httpsig-protocol)
signatures (HTTP Message Signatures, RFC 9421).

It is the shared base of the Angeo modules:

- [`angeo/module-robots-txt-aeo`](https://github.com/angeo-dev/module-robots-txt-aeo) writes robots.txt rules for these bots;
- [`angeo/module-agent-activity`](https://github.com/angeo-dev/module-agent-activity) records their visits and calls;
- [`angeo/module-aeo-audit`](https://github.com/angeo-dev/module-aeo-audit) reads that traffic for its "AI crawler activity" signal.

They depend on this module, not on each other. On its own, the module adds
no storefront output and no admin screens: it gives you the CLI below and the
PHP interfaces for other modules.

## Install

```bash
composer require angeo/module-ai-bots
bin/magento module:enable Angeo_AiBots
bin/magento setup:upgrade
bin/magento angeo:bots:refresh
```

The last command downloads the IP lists and signing keys once. After that,
cron does it every day at 04:17.

## CLI

| Command | What it does |
|---|---|
| `angeo:bots:list [--category=…] [--format=json]` | The list, with how each bot can be checked |
| `angeo:bots:refresh` | Download IP lists and Web Bot Auth keys now |
| `angeo:bots:status` | What was downloaded, and when |
| `angeo:bots:verify-ip gptbot 20.171.207.10` | Is this address in the vendor's list? |
| `angeo:bots:verify-request --url=… -H "User-Agent: …" -H "Signature-Agent: …" …` | Who one logged request claims to be, and whether the claim holds. `--fetch-keys` downloads the signer's keys now |

Example:

```text
$ bin/magento angeo:bots:verify-ip gptbot 8.8.8.8
GPTBot: INVALID
Address is not in the OpenAI list for GPTBot: the user agent is probably fake.
```

## Verification results

| State | Meaning |
|---|---|
| `verified` | The address is in the vendor's list, or the signature is valid |
| `invalid` | The check ran and failed. Treat the request as spoofed |
| `unverified` | Could not be checked now: list or key not downloaded yet, another signature algorithm, a covered component this module cannot rebuild |
| `behind_proxy` | Magento sees a private address. Set up trusted proxy headers (see below) |
| `unsupported` | The vendor publishes no way to check this bot |

`unverified` is not `invalid`, as the Web Bot Auth draft requires.

A **shared** IP list (Anthropic, some Google lists) covers several bots of
one vendor. A match proves the vendor, not the exact bot.

### Client IP behind a proxy or CDN

The module takes the client address from Magento (`RemoteAddress`). It never
reads `X-Forwarded-For` by itself, because the client can set that header.
Behind Cloudflare, Fastly or a load balancer, tell Magento which header to
trust, for example in `app/etc/di.xml`:

```xml
<type name="Magento\Framework\HTTP\PhpEnvironment\RemoteAddress">
    <arguments>
        <argument name="alternativeHeaders" xsi:type="array">
            <item name="cf" xsi:type="string">HTTP_CF_CONNECTING_IP</item>
        </argument>
    </arguments>
</type>
```

Trust such a header only when your server accepts traffic from that proxy
alone; otherwise anyone can set it. Until then, results are `behind_proxy`,
never a false `invalid`.

## Web Bot Auth

Follows `draft-ietf-webbotauth-httpsig-protocol-00` (1 September 2026):

- `Signature-Agent` in dictionary form (`sig1="https://chatgpt.com"`); the
  legacy bare string is accepted;
- types `directory` (an origin; keys at
  `/.well-known/http-message-signatures-directory`) and `jwks_uri`. Other
  types are ignored;
- the signature must be tagged `web-bot-auth`, cover the Signature-Agent
  member and `@authority` or `@target-uri`, carry `created`, `expires` and
  `keyid`, and be valid for at most 24 hours;
- `keyid` must be the JWK thumbprint of an Ed25519 key in the directory.
  Other algorithms give `unverified`;
- a bot in the list must sign with one of its listed origins.

Tested against the published vectors: RFC 9421 appendix B.2.6 (Ed25519),
RFC 8037 appendix A.3 (thumbprint), and the keyid of the draft's appendix E.2.

## The list

`etc/bots.json` ships with the module and changes only with a release. The
module never downloads it, so nobody who controls a web server can change
what your store believes about bots. JSON Schema: `etc/bots.schema.json`.

Every entry has `checked_on`, the date it was last checked against the
vendor's documentation. CI fails when an entry is older than six months.

| ID | Name | Operator | Category | Check |
|---|---|---|---|---|
| `gptbot` | GPTBot | OpenAI | training | IP list |
| `oai_searchbot` | OAI-SearchBot | OpenAI | search | IP list |
| `chatgpt_user` | ChatGPT-User | OpenAI | user_fetch | IP list, Web Bot Auth |
| `chatgpt_agent` | ChatGPT agent | OpenAI | agent | Web Bot Auth |
| `oai_adsbot` | OAI-AdsBot | OpenAI | ads | — |
| `claudebot` | ClaudeBot | Anthropic | training | IP list (shared) |
| `claude_searchbot` | Claude-SearchBot | Anthropic | search | IP list (shared) |
| `claude_user` | Claude-User | Anthropic | user_fetch | IP list (shared) |
| `anthropic_ai` | anthropic-ai (deprecated) | Anthropic | training | — |
| `perplexitybot` | PerplexityBot | Perplexity | search | IP list |
| `perplexity_user` | Perplexity-User | Perplexity | user_fetch | IP list |
| `google_extended` | Google-Extended | Google | training | robots.txt token only |
| `googlebot` | Googlebot | Google | search_engine | IP list (shared) |
| `googleother` | GoogleOther | Google | training | IP list (shared) |
| `google_cloudvertexbot` | Google-CloudVertexBot | Google | training | — |
| `google_agent` | Google-Agent | Google | agent | IP list, Web Bot Auth |
| `google_notebook` | Google-NotebookLM | Google | user_fetch | IP list (shared) |
| `applebot` | Applebot | Apple | search | IP list |
| `applebot_extended` | Applebot-Extended | Apple | training | robots.txt token only |
| `amazonbot` | Amazonbot | Amazon | training | — |
| `amzn_searchbot` | Amzn-SearchBot | Amazon | search | — |
| `amzn_user` | Amzn-User | Amazon | user_fetch | — |
| `meta_external_agent` | Meta-ExternalAgent | Meta | training | — |
| `meta_external_fetcher` | meta-externalfetcher | Meta | user_fetch | — |
| `mistralai_user` | MistralAI-User | Mistral AI | user_fetch | — |
| `duckassistbot` | DuckAssistBot | DuckDuckGo | user_fetch | — |
| `grok_agent` | GrokAgent | xAI | agent | — |
| `cohere_ai` | cohere-ai | Cohere | training | — |
| `ccbot` | CCBot | Common Crawl | training | — |
| `bytespider` | Bytespider | ByteDance | training | — |
| `diffbot` | Diffbot | Diffbot | training | — |
| `kimibot` | KimiBot | Moonshot AI | training | — |
| `bravebot` | Bravebot | Brave | search | — |
| `youbot` | YouBot | You.com | search | — |
| `petalbot` | PetalBot | Huawei | search | — |
| `linerbot` | LinerBot | Liner | user_fetch | — |
| `exa` | Exa | Exa | search | Web Bot Auth |
| `bingbot` | Bingbot | Microsoft | search_engine | IP list |

Categories: `training` (collects content for model training), `search` (AI
search or answer index), `user_fetch` (fetches a page because a person
asked), `agent` (browses or acts for a person), `ads`, `search_engine`.

The file also holds the MCP client names, the AI referrer hosts and the
`utm_source` values used by `angeo/module-agent-activity`.

Missing a bot? Open an issue with a link to the vendor's documentation. To
add one in your own store without a release, use the `additionalBots`
argument of `Angeo\AiBots\Model\BotRegistry` in `di.xml` (example in the
class docblock).

## For module developers

All interfaces are `@api` and follow semantic versioning.

| Interface | Use |
|---|---|
| `Api\BotRegistryInterface` | `all()`, `get($id)`, `match($userAgent)`, `matchSignatureAgent($host)`, `matchMcpClient($name)`, `matchReferrer($host)`, `matchUtmSource($value)` |
| `Api\BotIdentifierInterface` | `identify($request)`: who the request claims to be. Never uses the network |
| `Api\BotVerifierInterface` | `verify($identity, $request)`: is the claim true. Uses only stored lists and keys, never the network |
| `Api\BotTrafficReaderInterface` | `summary($storeId, $from, $to)`: recorded traffic. Empty by default; `angeo/module-agent-activity` provides the data |

```php
$request  = $requestFactory->fromHttp($httpRequest);   // Model\IncomingRequestFactory
$identity = $identifier->identify($request);
$result   = $verifier->verify($identity, $request);

if ($result->isInvalid()) {
    // the request claims to be a bot it is not
}
```

## Security

- **No data leaves the store.** The only outgoing requests are the daily
  downloads of vendor IP lists and vendor public keys for the bots in the
  list. Nothing about your store or your visitors is sent.
- **No network on storefront requests.** Identification and verification
  read stored data only. A signed request cannot make your store call an
  address of the sender's choice.
- **Downloads are SSRF-safe:** HTTPS only, certificates checked, public
  addresses only (checked address pinned against DNS rebinding), no
  automatic redirects (key directories: none at all), only HTTP 200, 10 s
  timeout, 2 MB cap.
- **Stores only public data:** vendor IP ranges and public keys, in the
  Magento flag table. No IP addresses of visitors.

See [SECURITY.md](SECURITY.md).

## Requirements

- Magento Open Source / Adobe Commerce 2.4.6 – 2.4.9, or Mage-OS
- PHP 8.1 – 8.5 with `ext-curl`; `ext-sodium` for Web Bot Auth (bundled with PHP)

## License

MIT — see [LICENSE](LICENSE). Copyright (c) 2026 Ievgenii Gryshkun ([angeo.dev](https://angeo.dev)).
