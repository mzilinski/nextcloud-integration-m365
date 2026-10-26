<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Screenshots for the App Store listing

`appinfo/info.xml` links to these files on the `main` branch. They are captures of
the dev instance (`docker/`) at 1280×800, device pixel ratio 2, with a demo user and
demo data; when replacing them keep that size and show:

| File | What to show |
|---|---|
| `personal-settings.png` | *Settings → Connected accounts*: connected account, the three tables with a few enabled collections, one "Import only" row, last-sync column filled. |
| `admin-settings.png` | *Administration → Connected accounts*: redirect URI, client id, tenant, secret expiry hint, "Test connection" result. |
| `calendar.png` | Calendar app with an `M365 …` calendar next to a local one (shows the result, not the settings). |

No tenant names, e-mail addresses or real event titles in the captures.
