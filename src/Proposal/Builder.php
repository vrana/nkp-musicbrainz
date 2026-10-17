<?php declare(strict_types=1);

namespace NkpMusicBrainz\Proposal;

use NkpMusicBrainz\App;
use NkpMusicBrainz\Text;

/** turns a record and its pairing into proposals: field additions to a paired release or a new release */
final class Builder
{

	public const EDIT_RELEASEGROUP_CREATE = 20;
	public const EDIT_RELEASE_CREATE = 31;
	public const EDIT_RELEASE_EDIT = 32;
	public const EDIT_RELEASE_ADDRELEASELABEL = 34;
	public const EDIT_RELEASE_EDITRELEASELABEL = 37;
	public const EDIT_MEDIUM_CREATE = 51;
	public const EDIT_MEDIUM_EDIT = 52;

	public const COUNTRY_NAMES = ['CZ' => 'Czechia', 'XC' => 'Czechoslovakia', 'SK' => 'Slovakia'];

	private const LATIN_LANGUAGES = ['cze', 'slo', 'eng', 'ger', 'pol', 'fre', 'ita', 'spa', 'por', 'hun', 'lat', 'dut', 'swe', 'nor', 'dan', 'fin', 'hrv', 'slv', 'rum', 'tur', 'mul'];

	/** @var array<string, int> */
	private array $countries = [];

	/** @var array<string, int> */
	private array $formats = [];

	/** @var array<string, int> */
	private array $languages = [];

	private int $latin;

	private int $official;


	public function __construct(App $app)
	{
		$db = $app->db();
		foreach ($db->query("SELECT code, area FROM {$app->mb('iso_3166_1')} WHERE code IN ('CZ', 'XC', 'SK')") as $row) {
			$this->countries[$row['code']] = (int) $row['area'];
		}
		foreach ($db->query("SELECT name, id FROM {$app->mb('medium_format')} WHERE name IN ('CD', 'Cassette', 'Vinyl')") as $row) {
			$this->formats[$row['name']] = (int) $row['id'];
		}
		foreach ($db->query("SELECT id, iso_code_2b, iso_code_3 FROM {$app->mb('language')}") as $row) {
			foreach ([$row['iso_code_3'], $row['iso_code_2b']] as $code) {
				if ($code !== null) {
					$this->languages[$code] = (int) $row['id'];
				}
			}
		}
		$this->latin = (int) $db->query("SELECT id FROM {$app->mb('script')} WHERE iso_code = 'Latn'")->fetchColumn();
		$this->official = (int) $db->query("SELECT id FROM {$app->mb('release_status')} WHERE name = 'Official'")->fetchColumn();
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 * @return list<array<string, mixed>>
	 */
	public function build(array $record, array $pairing): array
	{
		$release = $pairing['release'];
		if ($release === null) {
			return [$this->newRelease($record, $pairing)];
		}
		return array_values(array_filter([
			$this->barcode($record, $pairing, $release),
			$this->events($record, $release),
			...$this->labels($record, $pairing, $release),
			$this->format($record, $release),
		]));
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 * @param array<string, mixed> $release
	 * @return ?array<string, mixed>
	 */
	private function barcode(array $record, array $pairing, array $release): ?array
	{
		$barcode = $record['barcodes'][0] ?? null;
		if ($pairing['method'] === 'barcode' || count($record['barcodes']) !== 1 || !Text::isValidGtin((string) $barcode) || $release['barcode'] !== null) {
			return null; // '' on MusicBrainz means the release has no barcode
		}
		return $this->proposal($record, 'barcode', $release['gid'], ['barcode' => ''], ['barcode' => $barcode], [
			['edit_type' => self::EDIT_RELEASE_EDIT, 'to_edit' => $release['gid'], 'barcode' => $barcode],
		]);
	}


	/**
	 * events replace the whole list, so the proposal keeps the existing ones
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $release
	 * @return ?array<string, mixed>
	 */
	private function events(array $record, array $release): ?array
	{
		$year = $record['year'];
		$country = $record['country'];
		$countryId = $country === null ? null : ($this->countries[$country] ?? null);
		$events = $release['events'];
		$new = $events;
		if (!$events) {
			if ($year === null && $countryId === null) {
				return null;
			}
			$new = [['country_id' => $countryId, 'country' => $countryId === null ? null : $country, 'date' => ['year' => $year, 'month' => null, 'day' => null]]];
		} elseif ($year !== null && !array_filter($events, fn($event) => $event['date']['year'] !== null)) {
			if (count($events) !== 1 || ($events[0]['country'] !== null && $events[0]['country'] !== $country)) {
				return null;
			}
			$new[0]['date']['year'] = $year;
			if ($new[0]['country'] === null && $countryId !== null) {
				$new[0]['country_id'] = $countryId;
				$new[0]['country'] = $country;
			}
		} elseif ($countryId !== null && !array_filter($events, fn($event) => $event['country'] !== null)) {
			$index = array_search($year, array_map(fn($event) => $event['date']['year'], $events), true);
			if ($index === false || count($events) !== 1) {
				return null;
			}
			$new[$index]['country_id'] = $countryId;
			$new[$index]['country'] = $country;
		} else {
			return null;
		}
		return $this->proposal($record, 'event', $release['gid'], ['events' => self::eventsText($events)], ['events' => self::eventsText($new)], [[
			'edit_type' => self::EDIT_RELEASE_EDIT,
			'to_edit' => $release['gid'],
			'events' => array_map(fn($event) => ['country_id' => $event['country_id'], 'date' => $event['date']], $new),
		]]);
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 * @param array<string, mixed> $release
	 * @return list<?array<string, mixed>>
	 */
	private function labels(array $record, array $pairing, array $release): array
	{
		$proposals = [];
		$existing = array_filter(array_map(fn($label) => Text::code((string) $label['catalog_number']), $release['labels']));
		foreach ($pairing['labels'] as $number) {
			if (in_array(Text::code($number['number']), $existing, true)) {
				continue;
			}
			$withoutNumber = array_values(array_filter($release['labels'], fn($label) => $label['catalog_number'] === null && $label['label'] !== null && Text::labelsAgree($number['label'], $label['label']['name'])));
			if (count($withoutNumber) === 1) {
				$row = $withoutNumber[0];
				$proposals[] = $this->proposal($record, 'catno', $release['gid'], ['catalog number' => "{$row['label']['name']}: –"], ['catalog number' => "{$row['label']['name']}: $number[number]"], [[
					'edit_type' => self::EDIT_RELEASE_EDITRELEASELABEL,
					'release_label' => $row['id'],
					'label' => $row['label']['id'],
					'catalog_number' => $number['number'],
				]]);
			} elseif (!$release['labels']) {
				$label = $number['mb'];
				$proposals[] = $this->proposal($record, 'label', $release['gid'], ['label' => '–'], ['label' => ($label['name'] ?? $number['label']) . ": $number[number]"], [[
					'edit_type' => self::EDIT_RELEASE_ADDRELEASELABEL,
					'release' => $release['gid'],
					'label' => $label['id'] ?? null,
					'catalog_number' => $number['number'],
				]], $label === null ? "label \"$number[label]\" isn't paired with a MusicBrainz label" : null);
			}
		}
		return $proposals;
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $release
	 * @return ?array<string, mixed>
	 */
	private function format(array $record, array $release): ?array
	{
		$formatId = $this->formats[$record['format'] ?? ''] ?? null;
		$media = $release['media'];
		$unknown = array_values(array_filter($media, fn($medium) => $medium['format_id'] === null));
		if ($formatId === null || !$unknown || count($media) !== ($record['discs'] ?? 1)) {
			return null;
		}
		return $this->proposal($record, 'format', $release['gid'], ['format' => implode(' + ', array_map(fn($medium) => $medium['format'] ?? '?', $media))], ['format' => implode(' + ', array_fill(0, count($media), $record['format']))], array_map(fn($medium) => [
			'edit_type' => self::EDIT_MEDIUM_EDIT,
			'to_edit' => $medium['id'],
			'format_id' => $formatId,
		], $unknown));
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 * @return array<string, mixed>
	 */
	private function newRelease(array $record, array $pairing): array
	{
		$credit = $pairing['credit'];
		$group = $pairing['release_group'];
		$reason = match (true) {
			$pairing['method'] === 'manual-missing' => "the manually paired MusicBrainz entity isn't in the mirror yet",
			$credit['reason'] !== null => $credit['reason'],
			$record['tracks_source'] === null => 'no tracklist',
			$record['tracks_source'] === 'a' => 'the tracklist is only text (often classical works), enter it manually',
			!$record['tracks_complete'] => 'partial tracklist',
			($record['discs'] ?? 1) > 1 => 'several discs, the tracklist has no disc structure',
			!isset($this->formats[$record['format'] ?? '']) => 'unknown format',
			default => null,
		};
		$title = $record['title']; // as on the release, also for a new release in a release group on MusicBrainz
		$barcode = count($record['barcodes']) === 1 && Text::isValidGtin($record['barcodes'][0]) ? $record['barcodes'][0] : null;
		$countryId = $record['country'] === null ? null : ($this->countries[$record['country']] ?? null);
		$events = $record['year'] === null && $countryId === null ? [] : [['country_id' => $countryId, 'date' => ['year' => $record['year'], 'month' => null, 'day' => null]]];
		$artistCredit = ['names' => $credit['names']];
		$languageId = $this->languages[$record['language'] ?? ''] ?? null;

		$create = [
			'release_group' => $group === null ? [
				'edit_type' => self::EDIT_RELEASEGROUP_CREATE, 'name' => $title, 'artist_credit' => $artistCredit, 'primary_type_id' => null, 'comment' => '',
			] : null,
			'release_group_id' => $group['id'] ?? null,
			'release' => [
				'edit_type' => self::EDIT_RELEASE_CREATE, 'name' => $title, 'artist_credit' => $artistCredit, 'comment' => '',
				'barcode' => $barcode, 'language_id' => $languageId, 'packaging_id' => null,
				'script_id' => in_array($record['language'], self::LATIN_LANGUAGES, true) ? $this->latin : null,
				'status_id' => $this->official, 'events' => $events,
			],
			'labels' => array_map(fn($number) => ['edit_type' => self::EDIT_RELEASE_ADDRELEASELABEL, 'label' => $number['mb']['id'] ?? null, 'catalog_number' => $number['number']], $pairing['labels']),
			'medium' => [
				'edit_type' => self::EDIT_MEDIUM_CREATE, 'name' => '', 'format_id' => $this->formats[$record['format'] ?? ''] ?? null, 'position' => 1,
				'tracklist' => array_map(fn($track, $i) => [
					'name' => $track['title'], 'position' => $i + 1, 'number' => (string) ($i + 1), 'length' => $track['length'],
					'artist_credit' => $artistCredit, 'recording_gid' => null, 'is_data_track' => false,
				], $record['tracks'], array_keys($record['tracks'])),
			],
		];
		$proposed = [
			'title' => $title,
			'artist' => implode('', array_map(fn($name) => $name['name'] . ($name['join_phrase'] ?? ''), $credit['names'])) ?: '?',
			'release group' => $group === null ? 'new' : $group['name'],
			'events' => self::eventsText(array_map(fn($event) => $event + ['country' => $record['country']], $events)),
			'label' => implode(', ', array_map(fn($number) => ($number['mb']['name'] ?? $number['label']) . ": $number[number]", $pairing['labels'])),
			'barcode' => $barcode ?? '',
			'format' => ($record['format'] ?? '?') . ', ' . count($record['tracks']) . ' tracks',
			'tracks' => implode(' / ', array_map(fn($track) => $track['title'] . ($track['length'] ? ' (' . self::length($track['length']) . ')' : ''), $record['tracks'])),
		];
		return $this->proposal($record, 'release', null, [], $proposed, [], $reason, $create);
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<string, string> $current
	 * @param array<string, string> $proposed
	 * @param list<array<string, mixed>> $edits
	 * @param ?array<string, mixed> $create
	 * @return array<string, mixed>
	 */
	private function proposal(array $record, string $kind, ?string $target, array $current, array $proposed, array $edits, ?string $reason = null, ?array $create = null): array
	{
		$cnb = (string) $record['cnb'];
		return [
			'key' => sha1(json_encode([$cnb, $kind, $target, $proposed], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
			'cnb' => $cnb,
			'kind' => $kind,
			'target' => $target,
			'current' => $current,
			'proposed' => $proposed + ['_edits' => $edits, '_create' => $create],
			'reason' => $reason,
		];
	}


	/** @param list<array<string, mixed>> $events */
	public static function eventsText(array $events): string
	{
		return implode('; ', array_map(function (array $event): string {
			$date = implode('-', array_filter([$event['date']['year'], $event['date']['month'] ? sprintf('%02d', $event['date']['month']) : null, $event['date']['day'] ? sprintf('%02d', $event['date']['day']) : null]));
			$country = $event['country'] === null ? 'no country' : (self::COUNTRY_NAMES[$event['country']] ?? $event['country']);
			return trim("$country $date");
		}, $events)) ?: '–';
	}


	private static function length(int $milliseconds): string
	{
		$seconds = intdiv($milliseconds, 1000);
		return intdiv($seconds, 60) . ':' . sprintf('%02d', $seconds % 60);
	}

}
