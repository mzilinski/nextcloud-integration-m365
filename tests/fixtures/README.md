# graphpim fixtures — Microsoft Graph ↔ iCalendar/vCard conformance cases

Language-neutral test cases for the mapping between Microsoft Graph PIM objects
(`event`, `contact`, `todoTask`) and iCalendar (VEVENT/VTODO) / vCard 3.0.

They were extracted from the autotests of the Microsoft 365 (Graph) resource in
[kdepim-runtime](https://invent.kde.org/pim/kdepim-runtime) (`resources/graph/autotests/`)
and extended where the Python mapping goes further (nth-weekday recurrences,
Windows time-zone names, HTML bodies, duration-only events). Any implementation —
C++, Python, PHP, Rust — can run them. This copy is verbatim from
[vdirsyncer-graph](https://github.com/mzilinski/vdirsyncer-graph) (`fixtures/`), where
`tests/test_fixtures.py` is the Python harness; `tests/Unit/Mapping/FixtureTest.php` is the
PHP one. CI fails when the two copies differ.

## File schema

One JSON file per case, `tests/fixtures/<kind>/<name>.json`, `kind` ∈ `events`, `contacts`, `todos`:

| key | meaning |
|---|---|
| `description` | what the case pins down |
| `source` | where it comes from (KDE test name or `graphpim`) |
| `direction` | `read` (Graph → item), `write` (item → Graph) or `both` |
| `graph` | the Graph JSON object (input for `read`, informative for `write`) |
| `item` | the iCalendar/vCard text as a list of lines (CRLF-joined by the harness); `null` when the mapping yields no item |
| `photo` | contacts only: `{base64, mime}` of the separately fetched `/photo/$value` |
| `expect_write` | `write`/`both`: a JSON **subset** the written Graph body must contain |
| `expect_absent` | keys the written body must **not** contain (e.g. `birthday`, empty `body`) |

## Comparison rules

* **read**: unfold the produced text, drop `DTSTAMP`, `PRODID`, `VERSION`, `CALSCALE`,
  sort the lines, compare to the fixture's lines treated the same way. Property order
  and folding are therefore irrelevant; parameter order inside a line is not.
  Two tolerances for library differences: the parts of an `RRULE` are compared as a
  set, and `VALUE=DATE-TIME` on an absolute `TRIGGER` is optional.
* **write**: every key in `expect_write` must be present in the produced body with
  an equal value (lists compare element-wise, dicts recursively as subsets).

## Semantics worth knowing (all pinned by cases)

* Event UID = `iCalUId`, fallback Graph `id`; task/contact UID = Graph `id`.
* Graph's all-day `end` is exclusive, like iCalendar `DTEND;VALUE=DATE` — no ±1 day.
  All-day dates are taken verbatim, never converted through a zone.
* Event times come back in UTC (`Prefer: outlook.timezone="UTC"`); To Do ignores
  that header, so task timestamps carry their own zone (IANA **or Windows** name).
* Written bodies are plain text; HTML bodies are read via `bodyPreview`.
  Tasks omit `body` entirely when there is no DESCRIPTION.
* `showAs: free` ↔ `TRANSP:TRANSPARENT`; sensitivity ↔ `CLASS`.
* Recurrence: daily/weekly/absoluteMonthly/absoluteYearly map 1:1;
  relativeMonthly/relativeYearly ↔ `BYDAY` + `BYSETPOS` (or `BYDAY=1MO` form).
  Rules without a Graph equivalent (HOURLY, BYDAY without position) are not written.
  Weekly without BYDAY falls back to the start weekday (Graph needs ≥ 1 day).
* Contact `birthday` is read but never written (Exchange would create a
  Birthdays-calendar event). First e-mail is `PREF`. Addresses need a `street` key.
