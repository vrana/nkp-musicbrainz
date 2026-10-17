<?php declare(strict_types=1);

namespace NkpMusicBrainz\MusicBrainz;

use NkpMusicBrainz\Proposal\Builder;

/** edit notes explaining where the data comes from and how the release was paired */
final class EditNote
{

	public const REPOSITORY = 'https://github.com/vrana/nkp-musicbrainz';


	/** @param array<string, mixed> $record */
	public static function recordUrl(array $record): string
	{
		return $record['url'] ?? 'https://aleph.nkp.cz/F/?func=find-b&local_base=CNB&find_code=CNB&request=' . rawurlencode($record['cnb']);
	}


	/**
	 * @param array<string, mixed> $proposal
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 */
	public static function create(array $proposal, array $record, array $pairing, bool $seeded = false): string
	{
		$lines = ['From the Czech National Bibliography (National Library of the Czech Republic, data under CC0): ' . self::recordUrl($record)];
		$lines[] = 'Library record: ' . self::describe($record);
		$proposed = $proposal['proposed'];
		$lines[] = match ($proposal['kind']) {
			'barcode' => "Adding the barcode $proposed[barcode] as recorded by the library.",
			'event' => "Release event as recorded by the library (year and country of publication): $proposed[events].",
			'catno' => "Adding the catalogue number as recorded by the library: {$proposed['catalog number']}.",
			'label' => "Adding the label and catalogue number as recorded by the library: $proposed[label].",
			'format' => "Medium format as recorded by the library: $proposed[format].",
			'release' => match ($pairing['method']) {
				'release-missing' => 'This release is missing on MusicBrainz, its release group has only another format: https://musicbrainz.org/release/' . $pairing['other_format'],
				'manual-release-group' => 'The release group was chosen manually, this release is missing on MusicBrainz.',
				'manual-new' => 'Checked manually that this release is not on MusicBrainz.',
				default => 'Not found on MusicBrainz by barcode, catalogue number or title and artist.',
			},
			default => '',
		};
		if ($proposal['kind'] !== 'release') {
			$lines[] = self::pairedBy($record, $pairing);
		} elseif (array_filter($pairing['recordings'] ?? [])) {
			$lines[] = 'Tracks use the existing recordings of the release group where only one recording has the same title.';
		}
		$lines[] = $seeded ? 'Prefilled with ' . self::REPOSITORY . '.' : 'Submitted with ' . self::REPOSITORY . ' after a manual review of the proposal.';
		return implode("\n", array_filter($lines));
	}


	/** @param array<string, mixed> $record */
	private static function describe(array $record): string
	{
		$parts = [$record['title'] . ($record['statement'] !== '' ? " / $record[statement]" : '')];
		$parts[] = implode(', ', array_filter([implode(', ', $record['publishers']), $record['year']]));
		$parts[] = $record['physical'];
		foreach ($record['catalog_numbers'] as $number) {
			$parts[] = "catalogue number $number[number]" . ($number['label'] !== '' ? " ($number[label])" : '');
		}
		foreach ($record['barcodes'] as $barcode) {
			$parts[] = "barcode $barcode";
		}
		return implode('; ', array_filter($parts));
	}


	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 */
	private static function pairedBy(array $record, array $pairing): string
	{
		return match ($pairing['method']) {
			'barcode' => 'The release was paired by the barcode ' . implode(', ', Builder::barcodes($record)) . '.',
			'catno' => 'The release was paired by the catalogue number ' . implode(', ', array_column($record['catalog_numbers'], 'number')) . ' with an agreeing label or title.',
			'manual' => 'The release was paired manually.',
			default => 'The release was paired by its title and artist.',
		};
	}

}
