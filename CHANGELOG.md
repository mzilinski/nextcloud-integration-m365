# Changelog

## Unreleased
- Fixed: the reconnect notification linked to a non-existent settings section (blank page); it now opens *Connected accounts*.

## 0.2.1 – 2026-10-16
- When Microsoft rejects the refresh token (password change, revoked sessions, consent withdrawn) the user now gets a Nextcloud notification linking to the personal settings; it is cleared on reconnect. The Microsoft error (e.g. AADSTS50173) is written to the log.
- README: setup guide for any tenant (Entra ID app registration, scopes, consent).

## 0.2.0 – 2026-10-16
- App skeleton, Entra ID OAuth (authorization code + PKCE), admin and personal settings, collection discovery.
- Graph ↔ iCalendar/vCard mapping ported from graphpim, verified by the shared conformance fixtures.
- Two-way sync engine: local sync tokens + Graph delta, correlation table, echo filter, conflict policy, dry run.
- Calendars, Microsoft To Do lists (as VTODO calendars) and contact folders (with photos).
- `occ integration_m365:sync` / `integration_m365:pairs`, background job every 5 minutes.
- Import-only pairs for collections Microsoft 365 reports as not editable (holiday, birthday and subscribed calendars): copied to Nextcloud, nothing is ever written back.
- Large initial imports show their progress ("In progress, N objects remaining") and continue automatically: a truncated run queues the next round immediately instead of waiting for the next 5-minute job.
- Enabled collections that never ran show "Pending" and a notice with a "Sync now" button.
- "Sync now" runs inline with a 20 s time budget and hands the remainder to the background job; the settings page polls the status and reports the outcome.
- Fixed: a run that hit the write cap advanced the sync cursors anyway, losing the remaining changes. Truncated runs now keep the cursors and continue next time.
- Fixed: `ItemStoreInterface` was not bound in the DI container (sync failed outside unit tests).
- Fixed: Graph error responses (4xx/5xx) crashed the client instead of raising `GraphException`.
- Fixed: a crash while logging a pair failure left the pair locked for 15 minutes.
- Fixed: checkbox and name field in the personal settings ignored clicks (nextcloud/vue 9 `modelValue`).
- Changed: local calendar/address book URIs derive from the remote name (`m365-<remote>-<hash>`).
- Verified live against a Microsoft 365 tenant: calendar, contacts (incl. photos) and To Do (incl. recurring completion), both directions, conflicts, deletes, `--full`.
