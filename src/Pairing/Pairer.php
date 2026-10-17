<?php declare(strict_types=1);

namespace NkpMusicBrainz\Pairing;

use NkpMusicBrainz\App;
use NkpMusicBrainz\Importer;
use NkpMusicBrainz\Proposal\Builder;
use NkpMusicBrainz\Text;

/** pairs catalogue records with releases, release groups, artists and labels of the MusicBrainz mirror */
final class Pairer
{

	public const VERSION = 11; // increase when pairing or proposals change, the next run regenerates them

	private const FORMAT_FAMILIES = [1 => 'CD', 3 => 'CD', 7 => 'Vinyl', 53 => 'Vinyl', 8 => 'Cassette'];

	/** @var array<int, ?int> medium format => parent */
	private array $formatParents = [];

	/** @var array<int, string> */
	private array $formatNames = [];

	private bool $verbose = false;


	public function __construct(
		private readonly App $app,
	) {
	}


	public function run(bool $force): void
	{
		$app = $this->app;
		$upToDate = $app->meta('pairing_version') === (string) self::VERSION
			&& $app->meta('paired_at') > max($app->meta('records_changed_at') ?? '', $app->meta('authorities_loaded_at') ?? '');
		if ($upToDate && !$force) {
			echo 'Pairing is up to date (' . $app->meta('paired_at') . "), pass --force to pair against the current mirror\n";
			return;
		}
		ini_set('memory_limit', '-1');
		$this->verbose = true;
		$records = $this->load(null);
		echo count($records) . " records\n";
		$stats = $this->pair($records, true);
		$app->setMeta('pairing_version', (string) self::VERSION);
		$app->setMeta('paired_at', date('c'));
		ksort($stats);
		foreach ($stats as $key => $count) {
			printf("%7d  %s\n", $count, $key);
		}
	}


	/**
	 * re-pairs a few records, e.g. after their manual pairing changed on the review page
	 * @param list<string> $cnbs
	 */
	public function pairRecords(array $cnbs): void
	{
		$records = $this->load($cnbs);
		if ($records) {
			$this->pair($records, false);
		}
	}


	/**
	 * @param ?list<string> $cnbs null for all
	 * @return array<string, array<string, mixed>>
	 */
	private function load(?array $cnbs): array
	{
		$statement = $this->app->db()->prepare('SELECT cnb, data FROM record WHERE CAST(:all AS boolean) OR cnb = ANY(CAST(:cnbs AS text[])) ORDER BY cnb');
		$statement->execute(['all' => $cnbs === null ? 't' : 'f', 'cnbs' => Importer::pgArray($cnbs ?? [])]);
		$records = [];
		foreach ($statement as $row) {
			$records[$row['cnb']] = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
		}
		return $records;
	}


	/**
	 * pairs the records and replaces their pairings and proposals
	 * @param array<string, array<string, mixed>> $records
	 * @param bool $all the records are all records, delete the others
	 * @return array<string, int> statistics
	 */
	private function pair(array $records, bool $all): array
	{
		$db = $this->app->db();
		foreach ($db->query('SELECT id, parent, name FROM ' . $this->app->mb('medium_format')) as $row) {
			$this->formatParents[(int) $row['id']] = $row['parent'] === null ? null : (int) $row['parent'];
			$this->formatNames[(int) $row['id']] = $row['name'];
		}

		$manual = $this->manual(array_map('strval', array_keys($records)));
		$candidates = $this->candidates($records); // cnb => release id => list of methods
		$artists = $this->artists($records);
		$ids = array_merge(...array_map(array_keys(...), array_values($candidates)));
		$releases = $this->releases(array_values(array_unique(array_merge($ids, array_filter(array_column($manual, 'release_id'))))));
		$labels = $this->labels($records);
		$builder = new Builder($this->app);

		$stats = [];
		$pairings = [];
		$proposals = [];
		foreach ($records as $cnb => $record) {
			$pairing = $this->choose($record, $candidates[$cnb] ?? [], $releases, $manual[$cnb] ?? null);
			$pairing['manual'] = isset($manual[$cnb]) ? ['entity' => $manual[$cnb]['entity'], 'gid' => $manual[$cnb]['gid']] : null;
			$pairing['artists'] = $artists[$cnb] ?? [];
			$pairing['credit'] = Credit::resolve($record, $pairing['artists']);
			$releasesOfRecord = array_values(array_filter([$pairing['release'], ...array_map(fn($id) => $releases[$id] ?? null, array_keys($candidates[$cnb] ?? []))]));
			$pairing['labels'] = array_map(fn($number) => $number + ['mb' => self::label($number['label'], $record, $labels, $releasesOfRecord)], $record['catalog_numbers']);
			$pairings[$cnb] = $pairing;
			$stats['pairing: ' . $pairing['method']] = ($stats['pairing: ' . $pairing['method']] ?? 0) + 1;
		}
		$recordings = $this->recordings(array_values(array_unique(array_filter(array_map(fn($pairing) => $pairing['release'] === null ? ($pairing['release_group']['id'] ?? null) : null, $pairings)))));
		foreach ($pairings as $cnb => $pairing) {
			// a new release in an existing release group reuses its recordings
			$pairing['recordings'] = $pairing['release'] === null && $pairing['release_group'] !== null
				? array_map(fn($track) => self::recording($track['title'], $recordings[$pairing['release_group']['id']] ?? []), $records[$cnb]['tracks'])
				: [];
			$pairings[$cnb] = $pairing;
			foreach ($builder->build($records[$cnb], $pairing) as $proposal) {
				$proposals[] = $proposal;
				$key = 'proposal: ' . $proposal['kind'] . ($proposal['reason'] === null ? '' : ' (not processable)');
				$stats[$key] = ($stats[$key] ?? 0) + 1;
			}
		}

		$db->beginTransaction();
		$scope = $all ? '' : ' WHERE cnb = ANY(CAST(:cnbs AS text[]))';
		$parameters = $all ? [] : ['cnbs' => Importer::pgArray(array_map('strval', array_keys($records)))];
		$db->prepare("DELETE FROM pairing$scope")->execute($parameters);
		$db->prepare("DELETE FROM proposal$scope")->execute($parameters);
		foreach (array_chunk($pairings, 1000, true) as $chunk) {
			$db->prepare('INSERT INTO pairing (cnb, version, data) SELECT cnb, :version, data FROM unnest(CAST(:cnb AS text[]), CAST(:data AS jsonb[])) AS t (cnb, data)')
				->execute(['version' => self::VERSION, 'cnb' => Importer::pgArray(array_map('strval', array_keys($chunk))), 'data' => Importer::pgArray(array_map(self::json(...), array_values($chunk)))]);
		}
		$insert = $db->prepare('INSERT INTO proposal (key, cnb, kind, target, current, proposed, reason) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (key) DO NOTHING');
		foreach ($proposals as $proposal) {
			$insert->execute([$proposal['key'], $proposal['cnb'], $proposal['kind'], $proposal['target'], self::json($proposal['current']), self::json($proposal['proposed']), $proposal['reason']]);
		}
		$db->commit();
		return $stats;
	}


	/**
	 * pairings chosen on the review page, merged MusicBrainz IDs are followed
	 * @param list<string> $cnbs
	 * @return array<string, array<string, mixed>>
	 */
	private function manual(array $cnbs): array
	{
		$mb = $this->app->mb(...);
		$statement = $this->app->db()->prepare("
			SELECT m.cnb, m.entity, m.gid, coalesce(r.id, rr.new_id) AS release_id, rg.id AS group_id, rg.gid AS group_gid, rg.name AS group_name
			FROM manual_pairing m
			LEFT JOIN {$mb('release')} r ON m.entity = 'release' AND r.gid = m.gid
			LEFT JOIN {$mb('release_gid_redirect')} rr ON m.entity = 'release' AND rr.gid = m.gid
			LEFT JOIN {$mb('release_group_gid_redirect')} rgr ON m.entity = 'release-group' AND rgr.gid = m.gid
			LEFT JOIN {$mb('release_group')} rg ON m.entity = 'release-group' AND (rg.gid = m.gid OR rg.id = rgr.new_id)
			WHERE m.cnb = ANY(CAST(:cnbs AS text[]))
		");
		$statement->execute(['cnbs' => Importer::pgArray($cnbs)]);
		$manual = [];
		foreach ($statement as $row) {
			$manual[$row['cnb']] = [
				'entity' => $row['entity'], 'gid' => $row['gid'], 'release_id' => self::int($row['release_id']),
				'group' => $row['group_id'] === null ? null : ['id' => (int) $row['group_id'], 'gid' => $row['group_gid'], 'name' => $row['group_name']],
			];
		}
		return $manual;
	}


	/**
	 * @param array<string, array<string, mixed>> $records
	 * @return array<string, array<int, list<string>>>
	 */
	private function candidates(array $records): array
	{
		$db = $this->app->db();
		$barcodes = $catalogNumbers = $names = $authorities = [];
		foreach ($records as $cnb => $record) {
			foreach (Builder::barcodes($record) as $barcode) {
				$barcodes[] = [$cnb, Text::barcodeKey($barcode)];
			}
			foreach ($record['catalog_numbers'] as $number) {
				$code = Text::code($number['number']);
				if (strlen($code) >= 3) {
					$catalogNumbers[] = [$cnb, $code];
				}
			}
			foreach ($record['artists'] as $artist) {
				$names[] = [$cnb, mb_strtolower($artist['name'])];
				$names[] = [$cnb, mb_strtolower(Text::directName($artist['name']))];
				if ($artist['authority']) {
					$authorities[] = [$cnb, $artist['authority']];
				}
			}
		}
		$this->tempTable('nk_barcode', $barcodes);
		$this->tempTable('nk_catno', $catalogNumbers);
		$this->tempTable('nk_name', $names);
		$this->tempTable('nk_authority', $authorities);
		$db->exec('DROP TABLE IF EXISTS pg_temp.nk_artist');
		$db->exec("CREATE TEMP TABLE nk_artist AS {$this->artistMatchesQuery()}"); // used by the title candidates and the artists

		$candidates = [];
		$release = $this->app->mb('release');
		foreach ($db->query("
			SELECT nb.cnb, r.id FROM nk_barcode nb
			JOIN $release r ON r.barcode <> '' AND ltrim(regexp_replace(r.barcode, '[^0-9]', '', 'g'), '0') = nb.code
		") as $row) {
			$candidates[$row['cnb']][(int) $row['id']][] = 'barcode';
		}
		$this->progress("barcodes paired\n");

		foreach ($db->query("
			SELECT nc.cnb, rl.release, coalesce(l.name, '') AS label, r.name FROM nk_catno nc
			JOIN {$this->app->mb('release_label')} rl ON lower(regexp_replace(rl.catalog_number, '[^0-9A-Za-z]', '', 'g')) = nc.code
			JOIN $release r ON r.id = rl.release
			LEFT JOIN {$this->app->mb('label')} l ON l.id = rl.label
		") as $row) {
			$record = $records[$row['cnb']];
			$labelAgrees = false;
			foreach (array_merge(array_column($record['catalog_numbers'], 'label'), $record['publishers']) as $label) {
				$labelAgrees = $labelAgrees || ($label !== '' && Text::labelsAgree($label, $row['label']));
			}
			if ($labelAgrees || Text::titlesAgree($record['title'], $row['name'])) {
				$candidates[$row['cnb']][(int) $row['release']][] = 'catno';
			}
		}
		$this->progress("catalog numbers paired\n");

		foreach ($db->query("
			SELECT DISTINCT m.cnb, r.id, r.name FROM nk_artist m
			JOIN {$this->app->mb('artist_credit_name')} acn ON acn.artist = m.id
			JOIN $release r ON r.artist_credit = acn.artist_credit
		") as $row) {
			if (Text::titlesAgree($records[$row['cnb']]['title'], $row['name'])) {
				$candidates[$row['cnb']][(int) $row['id']][] = 'title';
			}
		}
		$this->progress("titles paired\n");
		return $candidates;
	}


	private function artistMatchesQuery(): string
	{
		$artist = $this->app->mb('artist');
		$unaccent = $this->app->mb('musicbrainz_unaccent');
		// the MusicBrainz index artist_idx_lower_unaccent_name_comment finds the candidates, the exact lowercase name decides
		// names are looked up as catalogued ("Svěrák, Zdeněk") and direct ("Zdeněk Svěrák"), which covers sort names too
		return "
			SELECT n.cnb, a.id, a.gid, a.name, a.sort_name, NULL AS authority FROM nk_name n
				JOIN $artist a ON lower($unaccent(a.name)) = lower($unaccent(n.code)) AND lower(a.name) = n.code
			UNION SELECT na.cnb, a.id, a.gid, a.name, a.sort_name, na.code FROM nk_authority na
				JOIN artist_authority aa ON aa.authority = na.code
				JOIN $artist a ON a.gid = aa.artist_gid
		";
	}


	/**
	 * catalogue artists with their MusicBrainz candidates, by authority first, then by name
	 * @param array<string, array<string, mixed>> $records
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function artists(array $records): array
	{
		$byAuthority = $byName = [];
		foreach ($this->app->db()->query('SELECT * FROM nk_artist') as $row) {
			$artist = ['id' => (int) $row['id'], 'gid' => $row['gid'], 'name' => $row['name']];
			if ($row['authority'] !== null) {
				$byAuthority[$row['cnb']][$row['authority']][$row['gid']] = $artist;
			} else {
				$byName[$row['cnb']][mb_strtolower($row['name'])][$row['gid']] = $artist;
				$byName[$row['cnb']][mb_strtolower($row['sort_name'])][$row['gid']] = $artist;
			}
		}
		$result = [];
		foreach ($records as $cnb => $record) {
			foreach ($record['artists'] as $artist) {
				$found = $byAuthority[$cnb][$artist['authority'] ?? ''] ?? [];
				$via = 'authority';
				if (!$found) {
					$found = ($byName[$cnb][mb_strtolower($artist['name'])] ?? []) + ($byName[$cnb][mb_strtolower(Text::directName($artist['name']))] ?? []);
					$via = 'name';
				}
				$result[$cnb][] = $artist + ['mb' => array_values($found), 'via' => $found ? $via : null];
			}
		}
		return $result;
	}


	/**
	 * snapshot of the candidate releases
	 * @param list<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private function releases(array $ids): array
	{
		$db = $this->app->db();
		$mb = $this->app->mb(...);
		$parameters = ['ids' => '{' . implode(',', $ids) . '}'];
		$releases = [];
		$statement = $db->prepare("
			SELECT r.id, r.gid, r.name, r.comment, r.barcode, rg.id AS group_id, rg.gid AS group_gid, rg.name AS group_name, ac.name AS artist
			FROM {$mb('release')} r
			JOIN {$mb('release_group')} rg ON rg.id = r.release_group
			JOIN {$mb('artist_credit')} ac ON ac.id = r.artist_credit
			WHERE r.id = ANY(CAST(:ids AS integer[]))
		");
		$statement->execute($parameters);
		foreach ($statement as $row) {
			$releases[(int) $row['id']] = [
				'id' => (int) $row['id'], 'gid' => $row['gid'], 'name' => $row['name'], 'comment' => $row['comment'], 'barcode' => $row['barcode'], 'artist' => $row['artist'],
				'group' => ['id' => (int) $row['group_id'], 'gid' => $row['group_gid'], 'name' => $row['group_name']],
				'labels' => [], 'events' => [], 'media' => [],
			];
		}
		$statement = $db->prepare("
			SELECT rl.release, rl.id, l.id AS label_id, l.gid AS label_gid, l.name AS label, rl.catalog_number
			FROM {$mb('release_label')} rl LEFT JOIN {$mb('label')} l ON l.id = rl.label
			WHERE rl.release = ANY(CAST(:ids AS integer[])) ORDER BY rl.id
		");
		$statement->execute($parameters);
		foreach ($statement as $row) {
			$releases[(int) $row['release']]['labels'][] = [
				'id' => (int) $row['id'], 'label' => $row['label_id'] === null ? null : ['id' => (int) $row['label_id'], 'gid' => $row['label_gid'], 'name' => $row['label']],
				'catalog_number' => $row['catalog_number'],
			];
		}
		$statement = $db->prepare("
			SELECT rc.release, rc.country AS country_id, i.code AS country, rc.date_year, rc.date_month, rc.date_day
			FROM {$mb('release_country')} rc LEFT JOIN {$mb('iso_3166_1')} i ON i.area = rc.country
			WHERE rc.release = ANY(CAST(:ids AS integer[]))
			UNION ALL SELECT release, NULL, NULL, date_year, date_month, date_day FROM {$mb('release_unknown_country')} WHERE release = ANY(CAST(:ids AS integer[]))
		");
		$statement->execute($parameters);
		foreach ($statement as $row) {
			$releases[(int) $row['release']]['events'][] = [
				'country_id' => $row['country_id'] === null ? null : (int) $row['country_id'],
				'country' => $row['country'],
				'date' => ['year' => self::int($row['date_year']), 'month' => self::int($row['date_month']), 'day' => self::int($row['date_day'])],
			];
		}
		$statement = $db->prepare("SELECT release, id, position, format, track_count FROM {$mb('medium')} WHERE release = ANY(CAST(:ids AS integer[])) ORDER BY release, position");
		$statement->execute($parameters);
		foreach ($statement as $row) {
			$format = self::int($row['format']);
			$releases[(int) $row['release']]['media'][] = [
				'id' => (int) $row['id'], 'position' => (int) $row['position'], 'format_id' => $format,
				'format' => $format === null ? null : $this->formatNames[$format], 'family' => $this->family($format), 'track_count' => (int) $row['track_count'],
			];
		}
		$this->progress(count($releases) . " candidate releases loaded\n");
		return $releases;
	}


	/**
	 * recordings on the releases of the release groups, by release group and normalized track or recording title
	 * @param list<int> $groups
	 * @return array<int, array<string, array<string, true>>>
	 */
	private function recordings(array $groups): array
	{
		$mb = $this->app->mb(...);
		$statement = $this->app->db()->prepare("
			SELECT DISTINCT r.release_group, t.name AS track, rec.gid, rec.name
			FROM {$mb('release')} r
			JOIN {$mb('medium')} m ON m.release = r.id
			JOIN {$mb('track')} t ON t.medium = m.id
			JOIN {$mb('recording')} rec ON rec.id = t.recording
			WHERE r.release_group = ANY(CAST(:groups AS integer[]))
		");
		$statement->execute(['groups' => '{' . implode(',', $groups) . '}']);
		$recordings = [];
		foreach ($statement as $row) {
			foreach ([$row['track'], $row['name']] as $title) {
				$recordings[(int) $row['release_group']][Text::code($title)][$row['gid']] = true;
			}
		}
		return $recordings;
	}


	/**
	 * the only recording of the title in the release group, null when there is none or several (e.g. live versions)
	 * @param array<string, array<string, true>> $recordings
	 */
	private static function recording(string $title, array $recordings): ?string
	{
		$found = array_keys($recordings[Text::code($title)] ?? []);
		return count($found) === 1 ? $found[0] : null;
	}


	/**
	 * MusicBrainz labels named so by their name or an alias, by lowercase name
	 * @param array<string, array<string, mixed>> $records
	 * @return array<string, list<array{id: int, gid: string, name: string, country: ?string}>>
	 */
	private function labels(array $records): array
	{
		$names = [];
		foreach ($records as $record) {
			foreach ($record['catalog_numbers'] as $number) {
				if ($number['label'] !== '') {
					$names[mb_strtolower($number['label'])] = true;
				}
			}
		}
		$mb = $this->app->mb(...);
		$statement = $this->app->db()->prepare("
			SELECT DISTINCT lower(n.name) AS key, l.id, l.gid, l.name, i.code AS country
			FROM (SELECT id, name FROM {$mb('label')} UNION ALL SELECT label, name FROM {$mb('label_alias')}) n
			JOIN {$mb('label')} l ON l.id = n.id
			LEFT JOIN {$mb('iso_3166_1')} i ON i.area = l.area
			WHERE lower(n.name) = ANY(CAST(:names AS text[]))
		");
		$statement->execute(['names' => Importer::pgArray(array_map('strval', array_keys($names)))]);
		$labels = [];
		foreach ($statement as $row) {
			$labels[$row['key']][] = ['id' => (int) $row['id'], 'gid' => $row['gid'], 'name' => $row['name'], 'country' => $row['country']];
		}
		return $labels;
	}


	/**
	 * one of the labels of the name: the one on a candidate release, the only one from the country of publication or the only one
	 * @param array<string, mixed> $record
	 * @param array<string, list<array{id: int, gid: string, name: string, country: ?string}>> $labels
	 * @param list<array<string, mixed>> $releases candidate releases
	 * @return ?array{id: int, gid: string, name: string}
	 */
	private static function label(string $name, array $record, array $labels, array $releases): ?array
	{
		$options = [];
		foreach ($labels[mb_strtolower($name)] ?? [] as $label) {
			$options[$label['id']] = $label;
		}
		$pick = function (array $label): array {
			unset($label['country']);
			return $label;
		};
		foreach ($releases as $release) {
			foreach ($release['labels'] as $releaseLabel) {
				if (isset($options[$releaseLabel['label']['id'] ?? 0])) {
					return $pick($options[$releaseLabel['label']['id']]);
				}
			}
		}
		$countries = ['CZ' => ['CZ', 'XC'], 'XC' => ['XC', 'CZ'], 'SK' => ['SK', 'XC']][$record['country'] ?? ''] ?? [];
		$local = array_values(array_filter($options, fn($label) => in_array($label['country'], $countries, true)));
		if (count($local) === 1) {
			return $pick($local[0]);
		}
		return count($options) === 1 ? $pick(reset($options)) : null;
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<int, list<string>> $candidates
	 * @param array<int, array<string, mixed>> $releases
	 * @param ?array<string, mixed> $manual
	 * @return array<string, mixed>
	 */
	private function choose(array $record, array $candidates, array $releases, ?array $manual): array
	{
		$automatic = $this->chooseAutomatically($record, $candidates, $releases);
		if ($manual === null) {
			return $automatic;
		}
		$release = $releases[$manual['release_id'] ?? 0] ?? null;
		$listed = $automatic['candidates']; // the review page keeps offering them
		if ($release !== null && !in_array($release['gid'], array_column($listed, 'gid'), true)) {
			array_unshift($listed, self::candidate($release));
		}
		return match (true) {
			$manual['entity'] === 'new' => ['method' => 'manual-new', 'release' => null, 'release_group' => null, 'candidates' => $listed],
			$release !== null => ['method' => 'manual', 'release' => $release, 'release_group' => $release['group'], 'candidates' => $listed],
			$manual['group'] !== null => ['method' => 'manual-release-group', 'release' => null, 'release_group' => $manual['group'], 'candidates' => $listed],
			default => ['method' => 'manual-missing', 'release' => null, 'release_group' => null, 'candidates' => $listed],
		};
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<int, list<string>> $candidates
	 * @param array<int, array<string, mixed>> $releases
	 * @return array<string, mixed>
	 */
	private function chooseAutomatically(array $record, array $candidates, array $releases): array
	{
		if (!$candidates) {
			return ['method' => 'none', 'release' => null, 'release_group' => null, 'candidates' => []];
		}
		$score = function (int $id) use ($record, $candidates, $releases): array {
			$release = $releases[$id];
			$years = array_filter(array_map(fn($event) => $event['date']['year'], $release['events']));
			return [
				in_array('barcode', $candidates[$id], true), in_array('catno', $candidates[$id], true),
				in_array($record['format'], array_column($release['media'], 'family'), true),
				in_array($record['year'], $years, true), Text::titlesAgree($record['title'], $release['name']),
			];
		};
		$ids = array_keys($candidates);
		usort($ids, fn($a, $b) => $score($b) <=> $score($a));
		$best = $ids[0];
		$tied = array_values(array_filter($ids, fn($id) => $score($id) === $score($best)));
		$release = $releases[$best];
		$methods = $candidates[$best];
		$method = in_array('barcode', $methods, true) ? 'barcode' : (in_array('catno', $methods, true) ? 'catno' : 'title');
		$listed = array_map(fn($id) => self::candidate($releases[$id]), array_slice($ids, 0, 10)); // shown on the review page to pick from

		if ($method === 'title' && count($tied) > 1) {
			$groups = array_unique(array_map(fn($id) => $releases[$id]['group']['gid'], $tied));
			return count($groups) === 1
				? ['method' => 'release-ambiguous', 'release' => null, 'release_group' => $release['group'], 'candidates' => $listed]
				: ['method' => 'release-group-ambiguous', 'release' => null, 'release_group' => null, 'candidates' => $listed];
		}
		$families = array_column($release['media'], 'family');
		if ($method !== 'barcode' && $record['format'] !== null && $families && !in_array($record['format'], $families, true) && !in_array(null, $families, true)) {
			return ['method' => 'release-missing', 'release' => null, 'release_group' => $release['group'], 'candidates' => $listed, 'other_format' => $release['gid']];
		}
		return ['method' => $method, 'release' => $release, 'release_group' => $release['group'], 'candidates' => $listed];
	}


	/**
	 * a candidate release as the review page lists it
	 * @param array<string, mixed> $release
	 * @return array<string, mixed>
	 */
	private static function candidate(array $release): array
	{
		return [
			'gid' => $release['gid'], 'name' => $release['name'], 'comment' => $release['comment'], 'artist' => $release['artist'], 'barcode' => $release['barcode'],
			'group' => ['gid' => $release['group']['gid'], 'name' => $release['group']['name']],
			'media' => array_map(fn($medium) => ['format' => $medium['format'], 'track_count' => $medium['track_count']], $release['media']),
			'events' => array_map(fn($event) => ['country' => $event['country'], 'date' => $event['date']], $release['events']),
			'labels' => array_map(fn($label) => ['label' => $label['label']['name'] ?? null, 'catalog_number' => $label['catalog_number']], $release['labels']),
		];
	}


	private function progress(string $message): void
	{
		if ($this->verbose) {
			echo $message;
		}
	}


	private function family(?int $format): ?string
	{
		while ($format !== null && !in_array($this->formatParents[$format] ?? null, [null, 73], true)) { // 73: phonograph record
			$format = $this->formatParents[$format];
		}
		return $format === null ? null : (self::FORMAT_FAMILIES[$format] ?? $this->formatNames[$format] ?? null);
	}


	/** @param list<array{string, string}> $rows cnb, code */
	private function tempTable(string $name, array $rows): void
	{
		$db = $this->app->db();
		$db->exec("DROP TABLE IF EXISTS pg_temp.$name");
		$db->exec("CREATE TEMP TABLE $name (cnb text, code text)");
		foreach (array_chunk($rows, 5000) as $chunk) {
			$db->prepare("INSERT INTO $name SELECT * FROM unnest(CAST(:cnb AS text[]), CAST(:code AS text[]))")
				->execute(['cnb' => Importer::pgArray(array_column($chunk, 0)), 'code' => Importer::pgArray(array_column($chunk, 1))]);
		}
		$db->exec("ANALYZE $name");
	}


	private static function int(mixed $value): ?int
	{
		return $value === null ? null : (int) $value;
	}


	private static function json(mixed $value): string
	{
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

}
