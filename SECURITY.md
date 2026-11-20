# Security Policy

## Supported versions

`angeo/module-ai-bots` follows SemVer. Only the latest tag receives security
fixes.

## Reporting a vulnerability

Email **info@angeo.dev** with the subject line `[security] module-ai-bots`.

Please include the module version, Magento and PHP versions, and steps to
reproduce. I aim to acknowledge within 72 hours and ship a fix within 14 days
for confirmed high-severity issues. Please do not open a public GitHub issue
for security problems.

## Security model

- **The bot list is part of the release.** `etc/bots.json` is never
  downloaded. Changing it needs a new version (or a `di.xml` change by the
  store itself).
- **No network on storefront requests.** `BotIdentifierInterface` and
  `BotVerifierInterface` read only stored data. A request cannot make the
  store fetch anything.
- **Outgoing requests** happen only in cron and the CLI, only to the IP list
  URLs and Web Bot Auth origins in `etc/bots.json` (the CLI option
  `--fetch-keys` also to the origin named by the pasted request), through
  `Model/Http/SafeFetcher`:
  - HTTPS only, TLS verification on, no credentials in URLs;
  - every resolved address must be public; loopback, RFC 1918, link-local
    (cloud metadata), CGNAT, multicast, reserved and IPv4-mapped forms are
    refused; the checked address is pinned with `CURLOPT_RESOLVE`;
  - no automatic redirects; IP lists may follow 2 same-host redirects, key
    directories none;
  - HTTP 200 only, 10 s timeout, 2 MB limit (a larger body is refused, not
    cut).
- **Stored data is public data:** vendor IP ranges and vendor public keys,
  in the `flag` table and the cache. No visitor data. Private keys found in
  a key directory make the directory unusable.
- **Signatures:** a signature that cannot be checked exactly (unknown
  component parameter, other algorithm) is `unverified`, never `verified`.
- **Client IP** comes from Magento's `RemoteAddress`. `X-Forwarded-For` is
  never read directly.
