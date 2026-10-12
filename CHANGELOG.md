# Changelog

## Unreleased
- App skeleton, Entra ID OAuth (authorization code + PKCE), admin and personal settings, collection discovery.
- Graph ↔ iCalendar/vCard mapping ported from graphpim, verified by the shared conformance fixtures.
- Two-way sync engine: local sync tokens + Graph delta, correlation table, echo filter, conflict policy, dry run.
- Calendars, Microsoft To Do lists (as VTODO calendars) and contact folders (with photos).
- `occ integration_m365:sync` / `integration_m365:pairs`, background job every 5 minutes, "Sync now".
- Fixed: `ItemStoreInterface` was not bound in the DI container (sync failed outside unit tests).
- Fixed: Graph error responses (4xx/5xx) crashed the client instead of raising `GraphException`.
- Fixed: a crash while logging a pair failure left the pair locked for 15 minutes.
- Fixed: checkbox and name field in the personal settings ignored clicks (nextcloud/vue 9 `modelValue`).
- Changed: local calendar/address book URIs derive from the remote name (`m365-<remote>-<hash>`).
- Verified live against a Microsoft 365 tenant: calendar, contacts (incl. photos) and To Do (incl. recurring completion), both directions, conflicts, deletes, `--full`.
