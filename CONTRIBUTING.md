<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Contributing

Thanks for helping. Bug reports and pull requests are welcome on GitHub.

## Development setup

A ready-made Nextcloud with the app bind-mounted lives in `docker/`; see
[docker/README.md](docker/README.md). PHP tooling runs inside that container (so it uses
the PHP version Nextcloud uses), the frontend builds on the host:

```bash
docker compose -f docker/compose.yml run --rm composer install
npm ci
```

## Checks (all must be green before a pull request)

```bash
# inside the app container (docker compose -f docker/compose.yml exec -w /var/www/html/custom_apps/integration_m365 app …)
composer run lint        # php -l
composer run cs:check    # nextcloud/coding-standard (cs:fix to apply)
composer run psalm       # static analysis, errorLevel 4, against the OCP stubs
composer run test:unit   # PHPUnit, no server needed
# on the host
npm run lint             # ESLint with @nextcloud/eslint-config
npm run stylelint
npm run build
```

CI runs the same on PHP 8.1–8.4 against the OCP packages of Nextcloud 32, 33 and 35,
installs the packaged app into a real Nextcloud 32 and 35, and diffs the conformance
fixtures against vdirsyncer-graph.

## Code conventions

* Tabs, `declare(strict_types=1)`, an SPDX header on every file, AGPL-3.0-or-later.
* Terse comments that explain *why*; the *what* should be readable from the code.
* All OCP access to CalDAV/CardDAV goes through `lib/Service/Local`; everything that
  talks to Microsoft through `lib/Service/GraphClient.php` and `lib/Service/Remote`. The
  sync decision table lives in `lib/Sync/SyncEngine.php` and is tested with in-memory
  fakes (`tests/Unit/Sync/Fakes.php`).
* User-visible strings go through `t()` / `IL10N`. After adding or changing one, run
  `python3 tools/l10n-de.py` and add the German translation there; `L10nTest` fails
  otherwise.
* `last_error` holds codes from `lib/Sync/SyncError.php`; the UI translates them
  (`src/errors.js`).

## The mapping and its fixtures

`tests/fixtures` is a verbatim copy of `fixtures/` in
[vdirsyncer-graph](https://github.com/mzilinski/vdirsyncer-graph). Never change the
mapping semantics (`lib/Service/Mapping`) without changing the fixtures in **both**
repositories; CI fails when they drift. New mapping behaviour needs a new fixture.

## Releasing (maintainer)

1. Bump `<version>` in `appinfo/info.xml` and `version` in `package.json` (`npm install`
   updates the lock), turn the *Unreleased* section of `CHANGELOG.md` into the version
   with a date, commit.
2. Tag `vX.Y.Z`, push, create the GitHub release. The release workflow runs the tests,
   builds, signs with the App Store certificate (secrets `APP_PRIVATE_KEY`,
   `APP_CERTIFICATE`), verifies the signature with a real server, attaches the tarball
   and pushes it to the App Store (`APPSTORE_TOKEN`).
3. `workflow_dispatch` with the tag re-runs the pipeline for an existing release.
