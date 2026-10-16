# Changelog

## 1.0.0 – unreleased
### Lifecycle and data hygiene
- Deleting a Nextcloud user now removes their tokens, pairs, correlations, queued jobs and notifications (`UserDeletedEvent`). Before, the background job kept recreating an orphaned calendar for the deleted principal every 5 minutes.
- Disconnecting removes the queued sync job and, with purge, the correlations as well (they were left behind). Without purge the pairs are only disabled and resume on reconnect.
- Connecting a different Microsoft account resets the pairs: remote ids and delta links of the old mailbox are meaningless for the new one.
- Changing client id or tenant in the admin settings invalidates every user's tokens and notifies them to connect again; changing only the secret keeps them valid.
- A bad secret, unknown client id or wrong tenant (`invalid_client`, `unauthorized_client`) is now reported as an admin configuration error (`config_error`) instead of asking every user to reconnect.
- If a user deletes (or trashes) the local calendar or address book of a pair, the pair is disabled with `local_missing` instead of silently recreating the collection and replaying old cursors against it, which would have deleted everything on the Microsoft side. Re-enabling the pair starts fresh and re-pairs existing objects by UID.
- Users flagged for reconnect are skipped by the background job without touching Graph.
- `app:disable` / `app:remove` run an uninstall step that removes the background jobs and open notifications; tables, tokens and pairs stay so that re-enabling resumes.
- New `occ integration_m365:reset --pair ID | --user UID | --all`: forget cursors and correlations of one pair (next run re-pairs by UID), purge one user, or wipe everything before `app:remove`. Local calendars and address books are never touched.
- `last_error` now holds machine-readable codes (`reauth_required`, `config_error`, `local_missing`, `items_failed:N`) so the UI can translate them.

## 0.2.2 – 2026-10-16
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
