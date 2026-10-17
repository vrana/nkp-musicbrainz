# nkp-musicbrainz

Imports music records (MARC21, leader/06 = `j`) of the National Library of the Czech Republic, pairs them with a local MusicBrainz mirror and lets a person review proposed additions on a web page; "process" submits them as the bot account vrana_bot. Read [README.md](README.md) first.

The repository is public (its code is required for the bot approval): no credentials, no references to other projects of the author. Local settings go to `config.local.php` (git-ignored).

## Rules

- Standalone: plain PHP 8.4 without dependencies or frameworks, own autoloader in `bootstrap.php`.
- MusicBrainz terminology everywhere (release, release group, medium, recording), never "album" or "edition".
- Never download a dump when its cache file exists; only `import ... --refresh` does.
- Never fetch obalkyknih.cz from the page: the records link it unconditionally, `ObalkyKnih` and `checkObalkyKnih()` are kept but not called (it would be a request per record shown).
- Don't add indexes or anything else to the MusicBrainz mirror schema; use the indexes MusicBrainz has (e.g. `artist_idx_lower_unaccent_name_comment` via `lower(musicbrainz_unaccent(name))`).
- CSRF: state-changing requests require `Sec-Fetch-Site: same-origin`, no tokens.
- `config.php` defaults to `'mode' => 'preview'`; nothing reaches musicbrainz.org as an edit unless the user sets `submit`.
- Increase `Pairer::VERSION` whenever pairing or proposals change, so the next `pair` regenerates them. Decisions are keyed by a hash of the proposal (cnb, kind, target, proposed values) and survive regeneration, a changed proposed value is a new proposal.
- Manual pairings (`manual_pairing`) always win over the automatic pairing and are kept when pairing runs again.

## Commands

```bash
php bin/nkp-musicbrainz import https://aleph.nkp.cz/data/cnb.xml.gz <cache.xml.gz>  # about 3 minutes
php bin/nkp-musicbrainz authorities  # NK authority -> MusicBrainz artist from Wikidata (QLever)
php bin/nkp-musicbrainz pair [--force]  # about 3.5 minutes for all records
php tests/run.php  # parser checks on real records in tests/fixtures/records.xml
php -S 0.0.0.0:8088 -t public  # the user's server; WSL forwards localhost to Windows only from 0.0.0.0
```

For own HTTP checks run a second server on another port (e.g. `php -S 127.0.0.1:8089 -t public`), send POSTs with `-H 'Sec-Fetch-Site: same-origin'` and stop it afterwards. Windows Chrome (headless screenshots) reaches only servers on `0.0.0.0`.

## Layout

- `src/Marc/` – streaming MARCXML reader and `Record::parse()` (fields, tracks from 505 `$t`/`$a`, issue numbers only from 028 ind1 = 0, record URL from 998).
- `src/Pairing/` – `Pairer` (set-based SQL over temp tables: barcode, catalogue number with agreeing label or title, title and artist; manual pairing; labels by name/alias, candidate releases and country), `Credit` (release artist credit from 245 `$c`), `Authorities`.
- `src/Proposal/Builder.php` – proposals with display values and ready edit payloads (`_edits`) or a release creation chain (`_create`).
- `src/MusicBrainz/` – `Client` (curl, cookie jar, 1.1 s between requests, `/ws/js/edit/create|preview`, WS/2 lookups and search), `Processor` (live re-check before each edit, chained release creation, limits), `EditNote`, `Seed` (release editor seeding), `Suggest` (MusicBrainz search for the "Pair with" datalist).
- `src/Review.php`, `public/index.php`, `templates/page.php`, `public/style.css` – the review page.
- `sql/schema.sql` – state schema, run on every connection, so it must stay idempotent (`IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`).

## MusicBrainz facts learned the hard way

- Scripts can't log in (MetaBrainz OAuth). The cookie jar starts with the bot's `remember_login` cookie from a browser, its value stays percent-encoded (`%09`), curl keeps it rotated.
- `/ws/js/edit/create` returns response codes and created entities, no edit IDs. `/ws/js/edit/preview` returns rendered HTML, stored in `decision.preview`. A new release's labels and medium can't be previewed before the release exists.
- Release events in a release edit replace the whole list.
- Release editor seeding reads only POST bodies (the query string only `release-group`, `artist`, `label`); `?skip_confirmation=1` makes the confirmation of cross-site POSTs submit itself (MBS-13225).
- jsonb doesn't keep object key order, order displayed fields explicitly.
- A vinyl is one medium, sides go to track numbers (A1, B1).

## Style

Tabs, `declare(strict_types=1)`, two blank lines between methods, comments trailing on the line, lowercase and without a full stop, comments explain why rather than what. Match the surrounding code.
