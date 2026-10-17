<?php declare(strict_types=1);

namespace NkpMusicBrainz\Marc;

use DOMElement;
use NkpMusicBrainz\Text;

/** fields of a MARC21 record of a sound recording that matter for MusicBrainz */
final class Record
{

	private const COUNTRIES = ['xr' => 'CZ', 'cs' => 'XC', 'xo' => 'SK']; // MARC country codes of 008/15-17


	public static function isMusic(DOMElement $record): bool
	{
		foreach ($record->childNodes as $node) {
			if ($node instanceof DOMElement && $node->localName === 'leader') {
				return substr($node->textContent, 6, 1) === 'j'; // musical sound recording
			}
		}
		return false;
	}


	/** @return array<string, mixed> */
	public static function parse(DOMElement $record): array
	{
		$control = [];
		$fields = [];
		foreach ($record->childNodes as $node) {
			if (!$node instanceof DOMElement) {
				continue;
			}
			$tag = $node->getAttribute('tag');
			if ($node->localName === 'controlfield') {
				$control[$tag] = $node->textContent;
			} elseif ($node->localName === 'datafield') {
				$subfields = [];
				foreach ($node->childNodes as $subfield) {
					if ($subfield instanceof DOMElement) {
						$subfields[] = [$subfield->getAttribute('code'), trim($subfield->textContent)];
					}
				}
				$fields[$tag][] = ['ind1' => $node->getAttribute('ind1'), 'ind2' => $node->getAttribute('ind2'), 'subfields' => $subfields];
			}
		}

		$fixed = str_pad($control['008'] ?? '', 40);
		$year = ctype_digit(substr($fixed, 7, 4)) && $fixed[6] !== 'q' ? (int) substr($fixed, 7, 4) : null;
		$country = self::COUNTRIES[substr($fixed, 15, 2)] ?? null;
		if ($country === 'CZ' && $year !== null && $year < 1993) {
			$country = 'XC'; // Czechoslovakia
		}
		$physical = mb_strtolower(implode(' ', self::values($fields['300'] ?? [], 'a', 'c')));
		[$tracks, $source, $complete] = self::tracks($fields['505'] ?? []);

		return [
			'cnb' => self::values($fields['015'] ?? [], 'a')[0] ?? ($control['001'] ?? ''),
			'url' => self::url(self::values($fields['998'] ?? [], 'a')[0] ?? ''),
			'title' => Text::clean(implode(' ', self::values(array_slice($fields['245'] ?? [], 0, 1), 'a', 'n', 'p'))),
			'subtitle' => Text::clean(self::values(array_slice($fields['245'] ?? [], 0, 1), 'b')[0] ?? ''),
			'statement' => Text::clean(self::values(array_slice($fields['245'] ?? [], 0, 1), 'c')[0] ?? ''), // credits as on the release
			'artists' => self::artists($fields),
			'barcodes' => self::barcodes($fields['024'] ?? []),
			'catalog_numbers' => self::catalogNumbers($fields['028'] ?? []),
			'publishers' => array_values(array_unique(array_filter(array_map(
				Text::clean(...),
				array_merge(self::values($fields['260'] ?? [], 'b'), self::values(array_filter($fields['264'] ?? [], fn($field) => $field['ind2'] === '1'), 'b')),
			)))),
			'year' => $year,
			'country' => $country,
			'language' => trim(substr($fixed, 35, 3)) ?: null,
			'format' => self::format($control['007'] ?? '', $physical),
			'discs' => preg_match('~^\s*(\d+)\s+(cd|zvuk\w*\s+des\w*|zvuk\w*\s+kazet\w*|audiokazet\w*|gramofon\w*\s+des\w*|lp|mc)\b~u', $physical, $match) ? (int) $match[1] : null,
			'physical' => $physical,
			'tracks' => $tracks,
			'tracks_source' => $source, // t: one subfield per track, a: split text, null: no tracklist
			'tracks_complete' => $complete,
		];
	}


	/**
	 * @param list<array{ind1: string, ind2: string, subfields: list<array{string, string}>}> $fields
	 * @return list<string>
	 */
	private static function values(array $fields, string ...$codes): array
	{
		$values = [];
		foreach ($fields as $field) {
			foreach ($field['subfields'] as [$code, $value]) {
				if (in_array($code, $codes, true)) {
					$values[] = $value;
				}
			}
		}
		return $values;
	}


	/** canonical record URL from 998 $a, the dumps have http */
	private static function url(string $url): ?string
	{
		return preg_match('~^https?://aleph\.nkp\.cz/F/\?func=direct&doc_number=(\d+)&local_base=(\w+)$~', $url, $match)
			? "https://aleph.nkp.cz/F/?func=direct&doc_number=$match[1]&local_base=$match[2]"
			: null;
	}


	private static function format(string $carrier, string $physical): ?string
	{
		$speed = $carrier[3] ?? '';
		if (str_starts_with($carrier, 'ss') || preg_match('~kazet|\bmc\b~u', $physical)) {
			return 'Cassette';
		}
		if ((str_starts_with($carrier, 'sd') && $speed === 'f') || preg_match('~\bcd\b|kompaktní|12 cm~u', $physical)) {
			return 'CD';
		}
		if ((str_starts_with($carrier, 'sd') && in_array($speed, ['a', 'b', 'c', 'd'], true)) || preg_match('~gramofon|\blp\b|30 cm|25 cm|17 cm~u', $physical)) {
			return 'Vinyl';
		}
		return null;
	}


	/**
	 * @param array<string, list<array{ind1: string, ind2: string, subfields: list<array{string, string}>}>> $fields
	 * @return list<array{tag: string, name: string, authority: ?string, roles: list<string>}>
	 */
	private static function artists(array $fields): array
	{
		$artists = [];
		foreach (['100', '110', '700', '710'] as $tag) {
			foreach ($fields[$tag] ?? [] as $field) {
				$name = trim((string) preg_replace('~\s*\(.*?\)\s*~u', ' ', Text::clean(self::values([$field], 'a')[0] ?? '')));
				if ($name !== '') {
					$artists[] = [
						'tag' => $tag,
						'name' => $name,
						'authority' => self::values([$field], '7')[0] ?? null,
						'roles' => self::values([$field], '4'),
					];
				}
			}
		}
		return $artists;
	}


	/**
	 * @param list<array{ind1: string, ind2: string, subfields: list<array{string, string}>}> $fields
	 * @return list<string>
	 */
	private static function barcodes(array $fields): array
	{
		$barcodes = [];
		foreach ($fields as $field) {
			if (in_array($field['ind1'], ['1', '3'], true)) { // UPC, EAN
				foreach (self::values([$field], 'a') as $value) {
					$digits = (string) preg_replace('~\D+~', '', $value);
					if (Text::barcodeKey($digits) !== '') {
						$barcodes[] = $digits;
					}
				}
			}
		}
		return array_values(array_unique($barcodes));
	}


	/**
	 * @param list<array{ind1: string, ind2: string, subfields: list<array{string, string}>}> $fields
	 * @return list<array{number: string, label: string}>
	 */
	private static function catalogNumbers(array $fields): array
	{
		$numbers = [];
		foreach ($fields as $field) {
			if ($field['ind1'] !== '0') { // issue number, not matrix or other numbers
				continue;
			}
			foreach (self::values([$field], 'a') as $number) {
				$numbers[] = ['number' => trim((string) preg_replace('~\s*-\s*~', '-', $number)), 'label' => Text::clean(self::values([$field], 'b')[0] ?? '')];
			}
		}
		return $numbers;
	}


	/**
	 * @param list<array{ind1: string, ind2: string, subfields: list<array{string, string}>}> $fields
	 * @return array{list<array{title: string, length: ?int, credit: ?string}>, ?string, bool}
	 */
	private static function tracks(array $fields): array
	{
		$tracks = [];
		$source = null;
		$complete = $fields !== [];
		foreach ($fields as $field) {
			$complete = $complete && $field['ind1'] === '0';
			$titles = self::values([$field], 't');
			if ($titles !== []) {
				$source = 't';
				$track = null;
				$previous = '';
				foreach ($field['subfields'] as [$code, $value]) {
					if ($code === 't') {
						$title = Text::clean((string) preg_replace('~\(\d{1,3}[:.]\d{2}\)~', '', str_replace(' --', '', $value)));
						// tracks are separated by " --", without it a parallel title (" =") or a qualifier ("(...)") continues the track
						if ($track !== null && !str_ends_with($previous, '--') && (str_ends_with($previous, '=') || preg_match('~^[(\[:]~u', $title))) {
							$track['title'] .= (str_ends_with($previous, '=') ? ' = ' : ' ') . $title;
							$track['length'] ??= self::length($value);
						} else {
							if ($track !== null) {
								$tracks[] = $track;
							}
							$track = ['title' => $title, 'length' => self::length($value), 'credit' => null];
						}
					} elseif ($track !== null && $code === 'g') {
						$track['length'] ??= self::length($value);
					} elseif ($track !== null && $code === 'r') {
						$track['credit'] = Text::clean(str_replace(' --', '', $value));
					}
					$previous = rtrim($value);
				}
				if ($track !== null) {
					$tracks[] = $track;
				}
			} else {
				foreach (self::values([$field], 'a') as $text) {
					foreach (preg_split('~\s+--\s*~u', $text) ?: [] as $piece) {
						$title = Text::clean((string) preg_replace('~\(\d{1,3}[:.]\d{2}\)\s*$~', '', $piece));
						if ($title !== '') {
							$source ??= 'a';
							$tracks[] = ['title' => $title, 'length' => self::length($piece), 'credit' => null];
						}
					}
				}
			}
		}
		return [$tracks, $source, $complete && $tracks !== []];
	}


	/** milliseconds from "(3:25)" */
	private static function length(string $text): ?int
	{
		return preg_match('~\((\d{1,3}):(\d{2})\)~', $text, $match) ? ((int) $match[1] * 60 + (int) $match[2]) * 1000 : null;
	}

}
