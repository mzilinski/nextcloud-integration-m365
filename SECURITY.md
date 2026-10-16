<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Security policy

## Reporting a vulnerability

Please do **not** open a public issue for security problems. Report them privately:

* GitHub: [Report a vulnerability](https://github.com/mzilinski/nextcloud-integration-m365/security/advisories/new) (private security advisory), or
* e-mail: malte@zilinski.eu

Include the app version, Nextcloud version, steps to reproduce and, if you have one, a
suggested fix. You will get an acknowledgement within a few days and a fix or a
statement within 30 days for confirmed issues. Credit is given in the changelog unless
you prefer otherwise.

## Scope

Everything in this repository: PHP backend, Vue frontend, release workflow and
packaging. Issues in Nextcloud server, the Calendar/Contacts apps or Microsoft Graph
belong to those projects.

## Supported versions

The latest release on the App Store receives security fixes. Older releases are not
maintained; upgrade.

## Design notes relevant to reviewers

* OAuth 2.0 authorization code flow with PKCE (S256) and a single-use state bound to
  the user; tokens are encrypted with Nextcloud's `ICrypto` and stored as lazy user config.
* The client secret is a sensitive app config value; the admin settings never return it.
* All state-changing routes require CSRF tokens; admin settings require password
  confirmation; the OAuth redirect and "Sync now" are rate-limited per user.
* Every database query is scoped to the current user id; collection ids coming from the
  client are resolved through `(user_id, type, remote_id)`.
* Graph requests are only sent to `https://graph.microsoft.com`, including stored
  `nextLink`/`deltaLink` values.
* No `v-html`, no HTML built from Graph data; the UI renders Graph strings as text.
