# Microsoft 365 integration for Nextcloud (`integration_m365`)

Two-way synchronisation of **Microsoft 365 / Exchange Online** calendars, contact
folders and **Microsoft To Do** lists with Nextcloud Calendar, Contacts and Tasks,
through the Microsoft Graph API. No EWS, no DavMail.

Status: **0.2.0**, in daily use against a real Microsoft 365 tenant. Calendars,
contacts (including photos) and To Do lists sync in both directions; read-only
collections (holidays, birthdays, subscribed calendars) can be imported.

The mapping semantics are shared with the Python sibling
[vdirsyncer-graph](https://github.com/mzilinski/vdirsyncer-graph) and pinned by the
same conformance fixtures.

## Data flow

The app talks to `graph.microsoft.com` on behalf of each connected user, reading and
writing only the collections that user enabled. Tokens are stored encrypted in the
Nextcloud database. No data is sent anywhere else.

## Setup

The app works with any Microsoft 365 tenant and with personal Microsoft accounts. It
is not tied to the author's tenant: every Nextcloud instance uses its own Entra ID app
registration, which the Nextcloud admin creates once.

1. Install and enable the app. Open *Administration settings → Microsoft 365
   integration* and copy the **redirect URI** shown there (keep it exactly as shown,
   including `/index.php/` if present).
2. In the [Entra admin center](https://entra.microsoft.com) (or the Azure portal) go to
   *App registrations → New registration*:
   * Name: anything, e.g. `Nextcloud`.
   * Supported account types: *Accounts in this organizational directory only* for a
     single company tenant, or *any organizational directory and personal Microsoft
     accounts* if users with other accounts should connect.
   * Redirect URI: platform **Web**, paste the URI from step 1.
3. *Certificates & secrets → New client secret*; note the secret **value** (not the ID).
4. *API permissions → Add a permission → Microsoft Graph → Delegated*: `offline_access`,
   `User.Read`, `Calendars.ReadWrite`, `Contacts.ReadWrite`, `Tasks.ReadWrite`. These
   are the only scopes the app requests. In tenants where users may not consent
   themselves, click *Grant admin consent*.
5. Back in Nextcloud enter the **Application (client) ID**, the client secret and the
   tenant: the *Directory (tenant) ID* for a single-tenant registration, otherwise
   `common` (any Microsoft account), `organizations` or `consumers`. Save.
6. Each user opens *Personal settings → Microsoft 365 integration*, clicks *Connect to Microsoft 365*,
   signs in with their own account and ticks the calendars, contact folders and To Do
   lists to sync. The matching Nextcloud collections (`M365 …`) are created on the first
   run.

The sync runs from Nextcloud's background job system, so `cron` (or `webcron`) must be
configured; *Sync now* in the personal settings triggers a run immediately.

## How it works

* An admin registers one Entra ID application and pastes the redirect URI shown in the
  admin settings into Azure (platform "Web"). Copy it from there: depending on the server
  setup it contains `/index.php/`, and Entra ID requires an exact match. Each user connects
  their own account; tokens are stored encrypted.
* Per enabled collection the app keeps a *pair*: the Graph calendar / contact folder / To
  Do list and a local calendar or address book it creates (`m365-…`). A correlation
  table remembers, per object, the etags last seen on each side and a canonical content
  hash, so echoes (Exchange bumps the changeKey once after every upload) never bounce.
* Every 5 minutes a background job asks both sides for changes (local sync token, Graph
  delta link) and copies real changes across. When both sides changed the same object,
  the pair's policy decides (Microsoft 365 wins by default).
* Collections Microsoft 365 reports as read-only (holidays, birthdays, subscribed ICS
  calendars) can be enabled as *import only*: they are copied to Nextcloud, local edits
  are ignored and never sent back.
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
