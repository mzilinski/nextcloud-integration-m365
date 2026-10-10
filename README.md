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

## Development

See [docker/README.md](docker/README.md).

## Licence

AGPL-3.0-or-later. SPDX headers on every file.
