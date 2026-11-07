# StealthAudit

Open-source WordPress audit logs and PHP diagnostics for DevOps-managed sites, designed as a must-use plugin.

**Status: development.** The design and implementation plan are documented. There is no installable plugin or production-ready release yet. Compatibility, performance and security controls must be demonstrated by implementation tests before release.

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

The detailed planning documents are currently in Portuguese. Development and installation instructions will be added as the corresponding functionality is implemented and verified. No secrets, local operator configuration or GeoIP database should be committed.

## License status

The project is intended for open-source distribution. The implementation plan proposes a GPL-compatible license, with `GPL-3.0-or-later` as its candidate. The final license and third-party notices will be established after the runtime dependency inventory; there is no packaged release under that candidate license yet.
