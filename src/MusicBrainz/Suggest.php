<?php declare(strict_types=1);

namespace NkpMusicBrainz\MusicBrainz;

use NkpMusicBrainz\Text;

/** releases suggested for manual pairing, found by the MusicBrainz search */
final class Suggest
{

	public function __construct(
		private readonly Client $client,
	) {
	}


	/**
	 * @param array<string, mixed> $record
	 * @return list<array{value: string, label: string}>
	 */
	public function releases(array $record): array
	{
		$queries = [];
		$artist = self::artist($record);
		$queries[] = 'release:(' . self::escape($record['title']) . ')' . ($artist !== '' ? ' AND artist:(' . self::escape($artist) . ')' : '');
		foreach ($record['barcodes'] as $barcode) {
			$queries[] = "barcode:$barcode";
		}
		foreach ($record['catalog_numbers'] as $number) {
			$queries[] = 'catno:"' . self::escape($number['number']) . '"';
		}
		$suggestions = [];
		$groups = [];
		foreach ($this->client->searchReleases('(' . implode(') OR (', $queries) . ')') as $release) {
			$credit = implode('', array_map(fn($name) => $name['name'] . ($name['joinphrase'] ?? ''), $release['artist-credit'] ?? []));
			$media = implode(' + ', array_map(fn($medium) => ($medium['format'] ?? '?') . ' (' . ($medium['track-count'] ?? '?') . ')', $release['media'] ?? []));
			$labels = implode(', ', array_map(fn($info) => trim(($info['label']['name'] ?? '') . ' ' . ($info['catalog-number'] ?? '')), $release['label-info'] ?? []));
			$suggestions[] = [
				'value' => "https://musicbrainz.org/release/$release[id]",
				'label' => 'Release: ' . implode(' · ', array_filter([
					"$release[title] – $credit", $release['date'] ?? '', $release['country'] ?? '', $media, $labels, $release['barcode'] ?? '',
				])),
			];
			$group = $release['release-group'] ?? null;
			if ($group && !isset($groups[$group['id']])) { // the release group, when this release is missing
				$groups[$group['id']] = true;
				$suggestions[] = [
					'value' => "https://musicbrainz.org/release-group/$group[id]",
					'label' => "Release group, new release: $group[title] – $credit" . (isset($group['primary-type']) ? " · {$group['primary-type']}" : ''),
				];
			}
		}
		return $suggestions;
	}


	/** @param array<string, mixed> $record */
	private static function artist(array $record): string
	{
		if ($record['statement'] !== '') {
			return trim(preg_split('~\s*(;|,|&)\s*~u', $record['statement'])[0] ?? '');
		}
		foreach ($record['artists'] as $artist) {
			return Text::directName($artist['name']);
		}
		return '';
	}


	/** Lucene special characters */
	private static function escape(string $text): string
	{
		return (string) preg_replace('~([+\-&|!(){}\[\]^"\~*?:\\\\/])~', '\\\\$1', $text);
	}

}
