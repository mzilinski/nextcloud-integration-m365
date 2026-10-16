# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [1.0.1] – 2026-10-16
### Added
- Admin setting **Sync interval** (60–3600 s, default 300) for the background job; Nextcloud's cron must run at least that often (documented in the admin guide).

## [1.0.0] – 2026-10-16

### Documentation
- README split into a short overview plus `docs/admin.md` (Entra ID registration step by step, permissions and why, account types, secret rotation, `occ` commands, troubleshooting by AADSTS code, scaling, uninstall), `docs/user.md` and `docs/privacy.md` (data flow, storage, retention, logging and deletion for data protection officers). Wrong menu paths fixed: the settings live under *Connected accounts*.
- `SECURITY.md` (private reporting, scope, design notes) and `CONTRIBUTING.md` (setup, checks, conventions, fixture rules, release steps).
- Known limitations extended (own mailbox only, 1 MB cap, failed objects retried on change, write-cap rounds).
- Changelog switched to the Keep a Changelog format.

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

### App Store listing
- App icon (neutral two-way sync glyph, `img/app.svg` / `img/app-dark.svg`), used for the settings sections and the notification instead of the generic core icon.
- `info.xml`: screenshots (placeholders in `screenshots/`, to be replaced by real captures), user and admin documentation links, repository, requirements (cron, Entra ID registration) in the description.

### Admin and user experience
- Admin settings: "Test connection" verifies client ID, secret and tenant against Entra ID without a user (client-credentials grant; multi-tenant settings can only be verified by a user login), copy button for the redirect URI, optional client-secret expiry date with a warning 30 days ahead, link to the admin guide, and a notice when a registration change asked users to reconnect.
- Entra ID errors during login and in the connection test are mapped to actionable messages (redirect URI mismatch, missing consent, wrong client ID or tenant, wrong or expired secret, account type not allowed, Conditional Access, revoked login) with the AADSTS number kept for reference; the error no longer stays in the address bar.
- Personal settings: disconnect asks whether to keep the collection settings or forget the synchronisation state; "Sync now" reports failures (reconnect needed, admin configuration, server error) instead of pretending the job was queued; error codes in the tables are shown as translated text; dates follow the Nextcloud locale.
- Fixed: buttons used the nextcloud/vue 8 `type` prop and therefore all rendered as secondary buttons; `variant` is the nextcloud/vue 9 prop.
- Fixed: the redirect after connecting pointed at a non-existent anchor.
- Accessibility: checkboxes, name fields and conflict selects carry labels naming the collection, tables are labelled by their heading, column headers have a scope, decorative icons are hidden from screen readers.

### Translations
- German translation (`de` informal, `de_DE` formal) for every string in the settings pages, notifications and server-side messages; maintained in `tools/l10n-de.py` until the app is on Transifex (`.tx/config` and `.l10nignore` are in place). A unit test checks that every source string is translated and that placeholders survive.
- Server-side messages that reach the user (login errors, "not configured", "not connected", the default "Contacts" folder name) now go through the Nextcloud translation system.

### Tests
- Unit tests for OAuthService (PKCE/state, refresh, invalid_grant → one notification, invalid_client → admin error, redirect, registration test), GraphClient (paging, host allow-list, delta expiry, throttling and back-off, retry rules per method, 401 refresh, error mapping, `$batch` sub-request retries), LifecycleService, UserDeletedListener, Notifier, EntraErrors, UserSyncService (locking, reauth skip, missing local collection, `--full`), background jobs and both controllers; SyncEngine now also covers both-deleted, delete-versus-change in both directions and policies, identical concurrent edits, the write cap, per-item failures, deferred photos and To Do lists. 133 tests.
- `time()` and `sleep()` go through `ITimeFactory` / an overridable pause so that tests do not wait.

### Compatibility and CI
- Test matrix now covers what `info.xml` claims: PHP 8.1 with OCP stable32, 8.2 with stable33, 8.3 and 8.4 with stable35 (lint, psalm, PHPUnit on every entry).
- New install job: the packaged app is installed into a real Nextcloud 32 (PHP 8.1) and 35 (PHP 8.4) server, enabled (migrations), its commands, settings pages, background job and uninstall step are exercised and the server log must stay clean.
- Conformance fixtures are diffed against the vdirsyncer-graph repository on every run.
- ESLint (`@nextcloud/eslint-config`) and stylelint (`@nextcloud/stylelint-config`) for the frontend; sources reformatted accordingly. `.nvmrc` pins Node 22.
- Release workflow: runs the full test workflow first, checks tag, `info.xml`, `package.json` and CHANGELOG against each other, installs the signed build into a server and runs `integrity:check-app`, fails loudly when signing secrets are missing and warns when the App Store token is absent. Server checkout pinned to a tag.
- Code made acceptable to the older psalm that resolves on PHP 8.1; `psr/http-message` declared as the dev dependency it is.

### Robustness
- Fixed: a throttled or failed photo fetch inside a `$batch` was taken as "contact has no photo"; the content hash changed and the local contact was rewritten without its picture. Photos that could not be fetched now defer the contact to the next run; throttled sub-requests are retried with their `Retry-After`.
- Graph client: retries 429/503/504 for every request and 500/502 plus network errors for idempotent ones, honours `Retry-After` in seconds and HTTP-date form, backs off exponentially with full jitter, and gives up immediately when Graph asks for more than 20 s (the next run tries again). Connect timeout of 10 s.
- The pair lock is taken with one conditional `UPDATE`: cron and "Sync now" can no longer both pass a read-then-write check. `--full` resets the cursors under the lock.
- Background job is now time sensitive (instances with a maintenance window only synced during that window), spends at most 240 s per run and visits users least recently synced first, so nobody starves when a run is cut short. One failing user no longer aborts the others.
- A pair skipped because another run holds its lock no longer makes the "Sync now" job re-queue itself every tick.
- Deferred (documented limitation): a run cut short by the write cap re-lists the whole remote collection next time; initial imports of very large collections therefore cost more Graph pages than strictly necessary.

### Security
- `nextLink`/`deltaLink` (stored and replayed) and every other absolute URL are only followed on `https://graph.microsoft.com`; anything else is refused before a bearer token is attached.
- Authorization code, token responses, token request parameters and raw upload bodies carry `#[\SensitiveParameter]` so they never appear in logged stack traces.
- Collection updates validate the lengths of remote id and names (HTTP 400 instead of a database error) and report Graph/token failures of the read-only probe as 502/400 instead of 500.
- Calendars and address books are only used when they belong to the user's principal and are not in the trash bin.

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
