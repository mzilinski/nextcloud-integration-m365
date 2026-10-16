<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Privacy and data flow

This page describes what the app transmits, stores and deletes, for operators and data
protection officers. The app has no component outside the Nextcloud server; the author
operates no service and receives no data.

## Parties

| Party | Role |
|---|---|
| Nextcloud instance (your server) | runs the app, stores all state |
| Microsoft Entra ID (`login.microsoftonline.com`) | authenticates the user, issues tokens for the app registration your admin created |
| Microsoft Graph (`graph.microsoft.com`) | source and target of calendar, contact and task data |

No other endpoint is contacted. Absolute URLs Microsoft returns (`nextLink`,
`deltaLink`) are only followed on `graph.microsoft.com`.

## Data sent to Microsoft

* At login: the application's client ID and secret, the user's authorization code,
  PKCE verifier and the redirect URI. Microsoft sees which Nextcloud instance the user
  connects from (redirect URI).
* During synchronisation, on behalf of and only for the user who connected: the content
  of events, contacts (including photos) and tasks the user **created or changed in
  Nextcloud** in the collections they enabled; delta and listing requests for those
  collections. Nothing from other users, other collections or other Nextcloud apps is
  read or sent.
* Microsoft's own processing of this data is governed by the organisation's contract
  with Microsoft, not by this app.

## Data received from Microsoft and stored in Nextcloud

| Data | Where | Purpose | Retention |
|---|---|---|---|
| Events, contacts (with photos), tasks of enabled collections | the user's Nextcloud calendars / address books (`oc_calendarobjects`, `oc_cards`) | that is the sync | until the user deletes them; subject to the Calendar app's trash bin |
| Access token (encrypted), refresh token (encrypted), expiry time | user config (`oc_preferences`), app id `integration_m365`, encrypted with the instance's secret (`ICrypto`) | API access without the user being logged in | until disconnect, user deletion or `reset`; access tokens are replaced hourly |
| Display name and user principal name (e-mail) of the Microsoft account | user config, plain | shown in the personal settings | same |
| Per collection: Graph id and name, Nextcloud id, delta link (an opaque Microsoft cursor), Nextcloud sync token, last run, last error, pending count | `oc_m365_collections` | incremental sync | until the pair is removed |
| Per object: UID, Nextcloud URI and etag, Graph id and changeKey, SHA-256 of the canonical content | `oc_m365_items` | echo suppression and conflict detection; the hash does not reveal the content | until the object or pair is removed |
| One-time OAuth state and PKCE verifier | user config | login flow | deleted when the login completes or fails |
| `reauth_required` flag, one notification | user config, `oc_notifications` | tell the user to reconnect | until reconnect |

The app does **not** store Graph responses beyond what is listed, keeps no history of
changes and has no analytics or telemetry.

## Logging

At the default log level the app logs warnings and errors only: user id, pair id, Graph
error code and message, and for a failed object its Nextcloud URI or Graph id. It never
logs tokens, secrets, event titles, bodies, attendee lists or contact details. Exception
stack traces exclude token and secret parameters (`#[\SensitiveParameter]`). At debug
level Nextcloud's own HTTP client may log request URLs, which contain Graph object ids.

## Deletion

* **Disconnect** removes the tokens; with "forget sync state" also pairs and correlations.
* **Deleting the Nextcloud user** removes everything the app stored about them (listener
  on `UserDeletedEvent`); Nextcloud itself deletes the user's calendars and address books.
* `occ integration_m365:reset --user=UID` does the same for a user that still exists;
  `--all` for everybody.
* Disabling or removing the app keeps the database rows (so re-enabling resumes); the
  admin guide shows how to purge them.
* The copies of the data on the Microsoft side are not deleted by any of these actions.

## Legal basis and roles (hints, not advice)

The Nextcloud operator remains the controller for the data in Nextcloud; Microsoft's
role is defined by the organisation's Microsoft 365 agreement. The app lets each user
decide individually which collections to connect, which is usually the basis for
processing personal data of third parties contained in contacts and events. Inform users
that enabling a collection copies its content, including other people's data, between the
two systems.
