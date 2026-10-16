<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# User guide

## Connect your Microsoft account

1. Open *Settings → Connected accounts* and find **Microsoft 365 integration**. If it
   says the administrator has not configured the integration yet, ask them.
2. Click **Connect to Microsoft 365**, sign in with your Microsoft account and accept the
   permissions (calendars, contacts, tasks). You are sent back to Nextcloud.
3. Three tables appear: calendars, contact folders and To Do lists of your Microsoft
   account.

## Choose what to sync

For each collection:

* **Sync**: tick it. The matching Nextcloud calendar or address book is created on the
  first run.
* **Nextcloud name**: the name of that calendar/address book (default `M365 …`). You can
  rename it later in the Calendar/Contacts app as well.
* **On conflict**: what happens when the same object was changed on both sides since the
  last run. *Microsoft 365 wins* (default) or *Nextcloud wins*. Collections Microsoft
  marks read-only (holidays, birthdays, subscribed calendars) are *Import only*: they are
  copied to Nextcloud, local edits are ignored and nothing is sent back.

Then click **Sync now** or wait: the background job runs every 5 minutes.

## The status column

| Shown | Meaning |
|---|---|
| a date | last successful run |
| **Pending** | enabled, but no run yet |
| **In progress, N objects remaining** | a large collection is copied in rounds; it continues by itself |
| *Microsoft rejected the stored login* | your password changed, sessions were revoked or MFA was enforced: click **Connect again** |
| *Entra ID rejects the app registration* | only the administrator can fix this |
| *The Nextcloud calendar or address book was deleted* | you deleted the local collection; tick the collection again to start over |
| *N objects could not be synchronised* | some objects were refused (for example an event too large or malformed); they are retried when they change. Details are in the server log, ask your admin |

## Disconnect

**Disconnect** stops the synchronisation and forgets the login. You choose:

* **keep settings**: the collection choices stay; reconnecting the same account resumes
  where it stopped.
* **forget sync state**: collection choices and the bookkeeping are removed.

Either way the calendars, contacts and tasks already in Nextcloud stay. Delete them in
the Calendar and Contacts apps if you do not want them.

Connecting a *different* Microsoft account always starts fresh.

## Good to know

* Deleting an event, contact or task on one side deletes it on the other, unless the
  conflict policy says the other side's edit wins.
* Deleting a whole `M365 …` calendar or address book in Nextcloud does **not** delete
  anything in Microsoft 365: the collection is simply disabled.
* Recurring events sync as series; single changed occurrences are skipped.
* Contact birthdays are never written to Microsoft 365.
