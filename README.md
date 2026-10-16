<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Microsoft 365 integration for Nextcloud (`integration_m365`)

Two-way synchronisation of **Microsoft 365 / Exchange Online** calendars, contact
folders and **Microsoft To Do** lists with Nextcloud Calendar, Contacts and Tasks,
through the Microsoft Graph API. No EWS, no DavMail, no third-party relay.

[![Tests](https://github.com/mzilinski/nextcloud-integration-m365/actions/workflows/test.yml/badge.svg)](https://github.com/mzilinski/nextcloud-integration-m365/actions/workflows/test.yml)
[App Store](https://apps.nextcloud.com/apps/integration_m365)

* Works with any Microsoft 365 tenant and with personal Microsoft accounts; every
  Nextcloud instance uses its own Entra ID app registration.
* Each user connects their own account and picks the calendars, contact folders and
  To Do lists to sync. Read-only collections (holidays, birthdays, subscribed
  calendars) can be imported.
* Changes are detected incrementally on both sides (Nextcloud sync tokens, Graph
  delta queries); conflicts follow a per-collection policy.
* Contacts travel with their photos; To Do tasks keep recurrence, reminders and
  completion.

Supported: Nextcloud 32–35, PHP 8.1–8.4 (Nextcloud 35 itself needs PHP 8.3+).

## Documentation

| | |
|---|---|
| [docs/admin.md](docs/admin.md) | Installation, Entra ID app registration (step by step), required permissions and why, consent, secret rotation, cron, troubleshooting by AADSTS code, `occ` commands, resetting a pair, uninstalling |
| [docs/user.md](docs/user.md) | Connecting an account, choosing collections, conflict policies, what the status column means, disconnecting |
| [docs/privacy.md](docs/privacy.md) | Data flow, what is stored where and for how long, deletion — written for data protection officers |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |
| [SECURITY.md](SECURITY.md) | Reporting vulnerabilities |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Development setup, checks, how the sync core is tested |

## How it works

* The admin registers one Entra ID application and pastes the redirect URI shown in
  the admin settings into it. Each user signs in with their own Microsoft account
  (authorization code flow with PKCE); refresh tokens are stored encrypted.
* Per enabled collection the app keeps a *pair*: the Graph calendar / contact folder /
  To Do list and a local calendar or address book it creates (`m365-…`). A correlation
  table remembers, per object, the etags last seen on each side and a canonical
  content hash, so echoes (Exchange bumps the changeKey once after every upload)
  never bounce.
* Every 5 minutes a background job asks both sides for changes and copies real changes
  across. When both sides changed the same object, the pair's policy decides
  (Microsoft 365 wins by default). "Sync now" runs the same sync inside the request
  for up to 20 seconds and hands the rest to the background job.
* The mapping between Graph JSON and iCalendar/vCard is shared with the Python
  sibling [vdirsyncer-graph](https://github.com/mzilinski/vdirsyncer-graph) and pinned
  by the same conformance fixtures (`tests/fixtures`).

## Known limitations

* Series exceptions and single occurrences of recurring events are skipped; the
  master event carries the rule.
* Contact birthdays are never written to Microsoft (Exchange would create a Birthdays
  calendar event). To Do keeps only the date of start/due; completing a recurring task
  makes To Do spawn the next occurrence as a new task.
* Only the signed-in user's own mailbox: no shared mailboxes, no shared calendars of
  other people, no global address list.
* Objects larger than 1 MB are skipped. An object Graph or Nextcloud refuses to store
  is logged and retried when it changes next (or after `occ integration_m365:sync --full`).
* A run that hits the write cap (500 objects per collection and run) re-lists the
  collection in the next round; initial imports of very large collections therefore
  take several rounds and more Graph requests than strictly necessary.

## Licence

AGPL-3.0-or-later. SPDX headers on every file.
