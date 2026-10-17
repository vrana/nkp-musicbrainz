# nkp-musicbrainz

Reviews music records of the [National Library of the Czech Republic](https://www.nkp.cz/) (NK ČR) and submits data missing on [MusicBrainz](https://musicbrainz.org/) as the bot account [vrana_bot](https://musicbrainz.org/user/vrana_bot). Every edit is chosen by a person on the review page.

The library publishes its catalogues as [open data under CC0](https://www.nkp.cz/en/about-us/professional-activities/open-data) in MARC21 XML, e.g. the Czech National Bibliography [`cnb.xml.gz`](https://aleph.nkp.cz/data/cnb.xml.gz). The tool takes its musical sound recordings (leader position 6 = `j`), pairs them with a MusicBrainz mirror and proposes:

| Proposal | Edit |
|---|---|
| Barcode | adds the barcode to a release paired by catalogue number or title, only a valid GTIN and only if MusicBrainz has no barcode (not even "no barcode") |
| Release event | adds the year and country of publication to a release without them, keeping its other events |
| Label, catalogue number | adds the label and catalogue number to a release without labels, or the catalogue number to its label without one |
| Format | sets CD, Cassette or Vinyl on mediums without a format |
| New release | creates the release (and its release group if that isn't on MusicBrainz) with the label, catalogue number, barcode, release event and tracklist |

## Pairing

- **Release:** by barcode, then by catalogue number with an agreeing label or title, then by title and artist. A paired release in another format (e.g. only a digital release reusing the CD's catalogue number) means the release group is paired and this release is missing, not a match.
- **Artists:** by the NK ČR authority ID through Wikidata (P691 → P434), otherwise by an exact name or sort name. The artist credit of a new release comes from the statement of responsibility (245 $c), and every name in it must be a paired artist.
- **Labels:** by a unique exact name.

When the automatic pairing misses, paste a MusicBrainz release URL into the record's "Pair with" field, or a release group URL when only this release is missing. Focusing the field suggests releases and their release groups found by the MusicBrainz search. The table of candidate releases pre-selects the paired release, or the new release when none is paired; picking another row pairs it manually. Manual pairings are kept when pairing runs again and saving one re-pairs only that record.

Each record links [Obálky knih](https://www.obalkyknih.cz/), the cover database of Czech libraries, whether it has an entry for the record or not. Its API is only for registered library catalogues and checking its public page for every record shown would load their server too much; the code for that check (`ObalkyKnih`, `checkObalkyKnih()` in the page) is kept but not called.

Records that can't be processed safely are shown with the reason and can only be ignored: unpaired artists or labels, tracklists given only as text (often classical works), partial tracklists and several discs without disc structure.

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `xmlreader`, `dom`, `curl`, `intl`, `mbstring`, `zlib` (`sudo apt install php8.4-pgsql php8.4-xml php8.4-curl php8.4-intl php8.4-mbstring`).
- A PostgreSQL database with a MusicBrainz mirror, e.g. made by [mbslave](https://pypi.org/project/mbslave/). The tool creates its own schema `nkp_musicbrainz` in the same database.

## Setup

Create `config.local.php` overriding the keys of [`config.php`](config.php) you need:

```php
<?php
return [
	'database' => ['dsn' => 'pgsql:host=localhost;dbname=musicbrainz', 'user' => 'musicbrainz', 'password' => '***'],
	'mode' => 'preview', // 'submit' to edit
];
```

```bash
bin/nkp-musicbrainz import https://aleph.nkp.cz/data/cnb.xml.gz var/cnb.xml.gz  # about 500 MB download, 3 minutes to parse
bin/nkp-musicbrainz authorities                                                 # one query to QLever
bin/nkp-musicbrainz pair                                                        # about 4 minutes
php -S localhost:8088 -t public
```

In WSL, Windows gets `localhost` forwarded only from servers listening on all interfaces: `php -S 0.0.0.0:8088 -t public`.

`import` downloads the dump only when the cache file doesn't exist, pass `--refresh` to download a newer one. `pair` stores its results and runs again only when the records, the authorities or the pairing code (`Pairer::VERSION`) changed, `--force` pairs against the current mirror.

## Editing

musicbrainz.org logs in through MetaBrainz OAuth, which a script can't do. Log in as the bot in a browser, copy its `remember_login` cookie and run `bin/nkp-musicbrainz cookie remember_login=<value>`. curl keeps the cookie jar updated.

Edits are submitted through `/ws/js/edit/create`, the endpoint of the release editor. In the `preview` mode, "process" calls `/ws/js/edit/preview` instead and submits nothing.

Following the [Code of Conduct for bots](https://wiki.musicbrainz.org/Code_of_Conduct/Bots):

- every edit is chosen manually on the review page;
- the edit note links the library record and explains the data and how the release was paired;
- before an edit, the release is loaded from musicbrainz.org and the edit is skipped if the field changed since the mirror, and a new release is skipped if a release with its barcode exists;
- requests are sent at most once per 1.1 seconds with a User-Agent with contact information;
- at most 1000 edits a day and, for votable edits, 2000 open edits (both configurable);
- ignored and processed proposals are remembered and not offered again; failed ones stay open.

## Tests

```bash
php tests/run.php
```

## Licence

MIT, see [LICENSE](LICENSE). The data of NK ČR is under CC0.
