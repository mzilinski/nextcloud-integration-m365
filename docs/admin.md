<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Administrator guide

## Requirements

* Nextcloud 32–35 with the Calendar and/or Contacts/Tasks apps users want to sync into.
* PHP 8.1–8.4 (whatever your Nextcloud version requires).
* A working **background job** setup (`cron` or `webcron`, *Administration settings →
  Basic settings*). The sync runs every 5 minutes from there; without it only "Sync now"
  works.
* Outbound HTTPS from the Nextcloud server to `login.microsoftonline.com` and
  `graph.microsoft.com`.
* The right to create an app registration in the Microsoft Entra ID tenant (or a
  personal Microsoft account; see *Account types* below).

## Install

From the App Store (*Apps → Integration → Microsoft 365 integration*) or from the
release tarball:

```bash
tar -xzf integration_m365-v1.0.0.tar.gz -C /path/to/nextcloud/custom_apps/
sudo -u www-data php occ app:enable integration_m365
```

Never leave a backup copy such as `integration_m365.old` inside `apps/` or
`custom_apps/`: `occ upgrade` iterates every directory there, fails on the invalid app id
and leaves the instance in maintenance mode.

## Register the application in Microsoft Entra ID

1. In Nextcloud open *Administration settings → Connected accounts → Microsoft 365
   integration* and copy the **redirect URI** (copy button). Keep it exactly as shown;
   depending on your web server setup it contains `/index.php/`, and Entra ID requires an
   exact match.
2. In the [Entra admin center](https://entra.microsoft.com) (or the Azure portal) go to
   *Applications → App registrations → New registration*:
   * **Name**: anything, e.g. `Nextcloud`.
   * **Supported account types**: see *Account types* below.
   * **Redirect URI**: platform **Web**, paste the URI from step 1.
3. *Certificates & secrets → New client secret*. Note the secret **value** immediately
   (it is shown once); the *secret ID* next to it is not what you need. Choose the
   longest lifetime your policy allows (24 months at most) and put the expiry date into
   the Nextcloud admin settings so you are warned before it runs out.
4. *API permissions → Add a permission → Microsoft Graph → Delegated permissions* and add

   | Permission | Why |
   |---|---|
   | `offline_access` | a refresh token, so the background job can work without the user being logged in |
   | `User.Read` | display name and e-mail address of the connected account (shown in the personal settings) |
   | `Calendars.ReadWrite` | list calendars, read and write events, delta queries |
   | `Contacts.ReadWrite` | list contact folders, read and write contacts and their photos |
   | `Tasks.ReadWrite` | list To Do lists, read and write tasks |

   These are the only scopes the app ever requests. If your tenant does not allow
   users to consent themselves, click **Grant admin consent** here; otherwise every user
   sees Microsoft's consent screen once.
5. Back in Nextcloud enter the **Application (client) ID** (*Overview* page of the
   registration), the client secret value and the **tenant**, then *Save* and
   *Test connection*.

### Account types and the tenant setting

| Who should be able to connect | "Supported account types" in Entra ID | Tenant field in Nextcloud |
|---|---|---|
| Users of your organisation only | *Accounts in this organizational directory only* | the **Directory (tenant) ID** from the registration's *Overview* page |
| Users of any organisation | *Accounts in any organizational directory* | `organizations` |
| Any Microsoft account including personal ones | *… and personal Microsoft accounts* | `common` |
| Personal accounts only | *Personal Microsoft accounts only* | `consumers` |

*Test connection* can verify client ID, secret and tenant only for a tenant ID (it uses a
client-credentials token request, which the multi-tenant endpoints do not offer). With
`common`, `organizations` or `consumers` the registration is verified by the first user
login.

### Private Nextcloud instances and the "unverified publisher" notice

You do not need a Microsoft 365 subscription or a company tenant to register the
application. Signing in to the [Entra admin center](https://entra.microsoft.com) with any
Microsoft account creates a free directory in which app registrations are allowed.
Choose *any organizational directory and personal Microsoft accounts* and the tenant
`common`; the registration then works for every user of your instance.

Users are never asked to do anything in a tenant themselves. They sign in, see
Microsoft's consent screen ("Nextcloud wants to read and write your calendars, contacts
and tasks") and accept. What differs is who may accept:

| The user's account | What happens |
|---|---|
| Personal Microsoft account (outlook.com, hotmail, live) | The user consents; done. The consent screen says *unverified* next to the app name, which personal accounts may ignore. |
| Work or school account in an organisation that lets users consent | The user consents; done. |
| Work or school account in an organisation that restricts consent to verified publishers or low-impact permissions (the Entra default recommended by Microsoft) | Microsoft shows *Approval required*; the user's IT has to grant the application once (*Enterprise applications → Admin consent*). Neither the user nor the Nextcloud admin can change that. The app reports it as AADSTS65001 / 90094. |

Becoming a *verified publisher* (which removes the restriction for most organisations)
requires a Microsoft Partner Center account with a verified domain and business details;
that is out of reach for most private operators. The app deliberately does not ship a
registration of its own: Entra requires every redirect URI to be registered in advance,
so a shared registration would need a relay service between Microsoft and your instance,
which contradicts the promise that nothing leaves your server. If that trade-off ever
becomes worth it, it will be an explicit opt-in, never the default.

### Rotating the client secret

Create a new secret in Entra ID, paste it into the Nextcloud admin settings, save. Users
stay connected: refresh tokens are bound to the application, not to the secret. Delete
the old secret in Entra ID afterwards.

Changing the **client ID or tenant** is different: tokens were issued for the old
registration and become useless. The app drops them, keeps every user's collection
settings and sends each connected user a notification to connect again.

## What users see

See the [user guide](user.md). Users find the app under *Personal settings → Connected
accounts*. Local calendars and address books are created on the first run with the names
users chose (`M365 …` by default) and URIs `m365-<name>-<hash>`.

## `occ` commands

```bash
occ integration_m365:pairs [--user=UID]          # list configured collection pairs with status
occ integration_m365:sync  [--user=UID] [--pair=ID] [--dry-run] [--full] [-v]
occ integration_m365:reset --pair=ID | --user=UID | --all
```

* `sync --dry-run` reports what a run would do without writing. `--full` forgets the sync
  cursors (Nextcloud sync token, Graph delta link) and re-lists both sides; correlations
  are kept, so nothing is duplicated.
* `reset --pair=ID` additionally forgets the correlations of one pair. The next run lists
  both sides in full and re-pairs objects by UID; use it when a pair is wedged.
* `reset --user=UID` removes tokens, pairs and correlations of one user, `reset --all` of
  everybody (run it before `app:remove` if you want a clean database). Local calendars and
  address books are never touched by any of these.

## Troubleshooting

The settings pages translate the common Entra ID errors; the AADSTS number is kept in
the message. The Nextcloud log (`occ log:tail`, app `integration_m365`) has the full
Microsoft response.

| Symptom / code | Cause | Fix |
|---|---|---|
| **AADSTS50011** redirect URI mismatch | the URI in Entra ID differs from the one Nextcloud sends (often `/index.php/`, `http` vs `https`, trailing slash) | copy the URI from the admin settings again, platform *Web* |
| **AADSTS7000215** invalid client secret | the secret *ID* was pasted instead of the *value*, or a typo | create a new secret, paste its value |
| **AADSTS7000222** secret expired | client secrets live 24 months at most | create a new secret, save it; users stay connected |
| **AADSTS700016** application not found | wrong client ID, or a single-tenant registration used with tenant `common`/another tenant | check client ID; use the directory ID as tenant |
| **AADSTS90002** tenant not found | typo in the tenant field | use the directory ID or one of `common`, `organizations`, `consumers` |
| **AADSTS65001 / 90094** consent required | users may not consent themselves | *Grant admin consent* on the API permissions page |
| **AADSTS50020 / 50194 / 50059** account type | personal account on a single-tenant registration or vice versa | adjust *Supported account types* and the tenant field |
| **AADSTS53003** blocked by Conditional Access | tenant policy (device compliance, location) | the organisation's identity admins must allow the app |
| **AADSTS50173 / 50076 / 50078** login revoked | password change, revoked sessions, new MFA requirement | the user connects again (they get a notification) |
| `access_denied` | the user cancelled the login or declined consent | connect again |
| Collections show **Pending** and never sync | background jobs are not running | configure cron; `occ background-job:list`, `occ status` |
| **config_error** on every pair | Entra ID rejects the registration (`invalid_client`) | fix client ID / secret / tenant, *Test connection* |
| **local_missing** on a pair | the user deleted the Nextcloud calendar/address book (or moved it to the trash bin) | the pair was disabled; enable it again to start fresh |
| "Graph asks to retry after N s" | tenant-wide throttling | nothing to do, the next run retries; reduce the number of users syncing huge collections at once |
| A pair repeats the same error every run | see the log line with `pair` and `exception` | `occ integration_m365:sync --pair=ID -v` reproduces it; `reset --pair=ID` if the cursors are wedged |

## Scaling notes

* The background job runs every **sync interval** (admin setting, 60–3600 s, default
  300). Nextcloud's cron has to run at least that often: the stock `cron.php` schedule and
  the official Docker image's `cron.sh` fire every 5 minutes, so a shorter interval needs a
  `* * * * *` crontab entry. Every run costs one Graph delta request per enabled collection
  and user, so on big instances keep the default.
* One run spends at most 240 s and visits users least recently synced first; large
  instances simply take a few runs to get round once. Each collection writes at most 500
  objects per run.
* Graph throttling (429/503) is retried with the `Retry-After` Microsoft sends; longer
  waits are left to the next run.
* Per user and run there is one Graph delta request per collection plus one request per
  changed object (two or three for writes, one `$batch` per 20 changed contacts for
  photos).

## Uninstall

`occ app:disable` (and `app:remove`) remove the background jobs and open notifications.
Tokens, pairs and correlations stay in the database so that re-enabling resumes; run
`occ integration_m365:reset --all` first if you want them gone. The `m365-…` calendars
and address books belong to the users and are not deleted; users can remove them in the
Calendar and Contacts apps.

Tables: `oc_m365_collections`, `oc_m365_items`. App config keys: `client_id`,
`client_secret` (sensitive), `tenant`, `secret_expires`, `sync_interval`. User config keys (all under the
app id, tokens encrypted with the instance secret): `access_token`, `refresh_token`,
`token_expires_at`, `user_name`, `user_principal`, `reauth_required`, and during a login
`oauth_state`, `oauth_verifier`.
