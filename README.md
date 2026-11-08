# StealthAudit

Open-source WordPress audit logs and PHP diagnostics for DevOps-managed sites, designed as a must-use plugin.

**Status: development.** The must-use loader, operator configuration, bounded InnoDB storage, audit collectors, bounded PHP diagnostics, panel-only MFA backend, the panel route with its authorized REST API and query layer, the ASCII Terminal operator interface (local assets, every MFA state, audit and error views), and a reproducible development/test environment are implemented. Phase 6 is complete: a reproducible browser end-to-end suite (`npm run test:e2e`) drives the panel in Chrome behind a local TLS proxy and a test HTTP cache, from WordPress login through enrollment, filters, expiry, recovery and factor replacement, and proves the QR with an independent decoder and TOTP implementation. Its stated limits remain: no physical phone scan of the QR yet, the subdirectory install is simulated with a symlink, and the cache is a library implementation rather than a production CDN. There is no production-ready release yet.

See [local setup](docs/testing/local-setup.md), [phase 1 verification](docs/testing/phase-1-verification.md), [phase 2 storage verification](docs/testing/phase-2-storage.md), [phase 3 audit coverage](docs/testing/phase-3-audit.md), [phase 4 diagnostics](docs/testing/phase-4-diagnostics.md), [phase 5 panel security](docs/testing/phase-5-security.md), [phase 6a HTTP transport](docs/testing/phase-6-http.md), [phase 6b interface](docs/testing/phase-6-ui.md) and [phase 6c browser end-to-end](docs/testing/phase-6-e2e.md) for test results, commands and remaining limitations. Updater completion alone never proves a successful bulk or core update; unresolved observations remain unknown. PHP capture respects suppression and yields nonfatal handling to a pre-existing handler because PHP does not expose that handler's severity mask. These results validate the implemented phases, not the complete planned plugin.

## Planned behavior

- Record summaries of authentication, user, administration and content actions observed through WordPress APIs.
- Show PHP/WordPress errors within the configured diagnostic level and the collector's documented coverage.
- Keep audit events for 365 days and error records for 30 days, subject to explicit capacity limits and scheduled cleanup.
- Provide a local endpoint with an ASCII Terminal interface, explicit operator authorization and mandatory Authenticator-compatible TOTP for the StealthAudit panel.
- Reuse the WordPress session for identity without changing the site's WordPress login flow.
- Display a trustworthy source IP when available and optionally estimate location from an operator-supplied local GeoIP database. No audited IP is sent to a geolocation API.

The initial target is WordPress single-site on shared hosting, with optional DevOps infrastructure. A subdomain-based panel is a future extension; the first version uses an endpoint on the site's domain.

## Security boundaries

The project aims to avoid accidental or casual deactivation through the administration interface. It does not protect the collector against an administrator who can deliberately execute PHP, install arbitrary plugins or control the host. A must-use plugin may appear in WordPress's Must-Use section.

Collection failures must preserve normal site operation where possible; access validation failures must deny access to the panel. Capture is not exhaustive, and missing records do not prove that no actions or errors occurred. Secrets, complete request bodies and full content snapshots are outside the collection scope.

## Project documents

- [Approved functional design](docs/specs/2026-11-07-stealthaudit-design.md)
- [Structured design review](docs/specs/2026-11-07-stealthaudit-review.md)
- [ASCII Terminal frontend direction](docs/specs/2026-11-07-frontend-ascii-terminal.md)
- [Implementation plan](docs/plans/2026-11-07-implementation-plan.md)
- [Documentation and verified API catalog](docs/plans/2026-11-07-documentation-discovery.md)

The detailed planning documents and local setup are currently in Portuguese. Production installation instructions will be added as the corresponding functionality is implemented and verified. No secrets, local operator configuration or GeoIP database should be committed.

## License

Project code is licensed under [GPL-3.0-or-later](LICENSE). Bundled dependencies retain their own licenses; see [third-party notices](THIRD-PARTY-NOTICES.md). GeoIP database licensing is separate, and no database is included.
