# Microsoft 365 integration for Nextcloud (`integration_m365`)

Two-way synchronisation of **Microsoft 365 / Exchange Online** calendars, contact
folders and **Microsoft To Do** lists with Nextcloud Calendar, Contacts and Tasks,
through the Microsoft Graph API. No EWS, no DavMail.

Status: **work in progress**. Done: app skeleton, Entra ID OAuth, settings UI, the
Graph ↔ iCalendar/vCard mapping (25 conformance fixtures), the two-way sync engine
with calendars, tasks and contacts. Open: live verification, packaging, app store.

The mapping semantics are shared with the Python sibling
[vdirsyncer-graph](https://github.com/mzilinski/vdirsyncer-graph) and pinned by the
same conformance fixtures.

## Data flow

The app talks to `graph.microsoft.com` on behalf of each connected user, reading and
writing only the collections that user enabled. Tokens are stored encrypted in the
Nextcloud database. No data is sent anywhere else.

## How it works

* An admin registers one Entra ID application (admin settings show the redirect URI to
  paste into Azure). Each user connects their own account; tokens are stored encrypted.
* Per enabled collection the app keeps a *pair*: the Graph calendar / contact folder / To
  Do list and a local calendar or address book it creates (`m365-…`). A correlation
  table remembers, per object, the etags last seen on each side and a canonical content
  hash, so echoes (Exchange bumps the changeKey once after every upload) never bounce.
* Every 5 minutes a background job asks both sides for changes (local sync token, Graph
  delta link) and copies real changes across. When both sides changed the same object,
  the pair's policy decides (Microsoft 365 wins by default).
* "Sync now" in the personal settings runs the same sync inside the request for up to
  20 seconds (enough for the usual small delta) and hands anything left over to the
  background job; the page shows when it is done.
* Manual runs: `occ integration_m365:sync --user=<uid> -v` (`--dry-run` to preview,
  `--full` to re-list everything without touching correlations), `occ integration_m365:pairs`.

## Known limitations

* Series exceptions and single occurrences of recurring events are skipped (the master
  carries the rule).
* Contact birthdays are never written to Microsoft (Exchange would create a Birthdays
  calendar event); To Do keeps only the date of start/due; completing a recurring task
  makes To Do spawn the next occurrence as a new task.
* Only the signed-in user's own mailbox (no shared mailboxes, no GAL).

## Development

See [docker/README.md](docker/README.md).

## Licence

AGPL-3.0-or-later. SPDX headers on every file.
