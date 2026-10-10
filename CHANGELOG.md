# Changelog

## Unreleased
- App skeleton, Entra ID OAuth (authorization code + PKCE), admin and personal settings, collection discovery.
- Graph ↔ iCalendar/vCard mapping ported from graphpim, verified by the shared conformance fixtures.
- Two-way sync engine: local sync tokens + Graph delta, correlation table, echo filter, conflict policy, dry run.
- Calendars, Microsoft To Do lists (as VTODO calendars) and contact folders (with photos).
- `occ integration_m365:sync` / `integration_m365:pairs`, background job every 5 minutes, "Sync now".
