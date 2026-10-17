<?php declare(strict_types=1);

namespace NkpMusicBrainz\MusicBrainz;

use NkpMusicBrainz\App;
use NkpMusicBrainz\Proposal\Builder;
use NkpMusicBrainz\Text;

/** fields prefilling the MusicBrainz release editor (release editor seeding) with a new release */
final class Seed
{

	public const URL = 'https://musicbrainz.org/release/add?skip_confirmation=1'; // the confirmation of data posted from another site submits itself, MBS-13225

	/** @var array<string, string> MARC language code (ISO 639-2/B) => ISO 639-3 */
	private array $languages = [];


	public function __construct(App $app)
	{
		foreach ($app->db()->query("SELECT iso_code_2b, iso_code_3 FROM {$app->mb('language')} WHERE iso_code_2b IS NOT NULL AND iso_code_3 IS NOT NULL") as $row) {
			$this->languages[$row['iso_code_2b']] = $row['iso_code_3'];
		}
	}


	/**
	 * unresolved artists and labels are seeded by name to be completed in the editor
	 * @param array<string, mixed> $proposal
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 * @return array<string, string|int>
	 */
	public function fields(array $proposal, array $record, array $pairing): array
	{
		$fields = ['name' => $record['title'], 'status' => 'official'];
		if ($pairing['release_group']) {
			$fields['release_group'] = $pairing['release_group']['gid'];
		}
		$names = $pairing['credit']['names'];
		if (!$names) {
			$names = [['name' => $record['statement'] ?: Text::directName($record['artists'][0]['name'] ?? ''), 'artist' => null, 'join_phrase' => null]];
		}
		foreach ($names as $i => $name) {
			$fields["artist_credit.names.$i.name"] = $name['name'];
			$fields["artist_credit.names.$i.artist.name"] = $name['artist']['name'] ?? $name['name'];
			if (isset($name['artist']['gid'])) {
				$fields["artist_credit.names.$i.mbid"] = $name['artist']['gid'];
			}
			$fields["artist_credit.names.$i.join_phrase"] = $name['join_phrase'] ?? '';
		}
		if ($record['year'] !== null) {
			$fields['events.0.date.year'] = $record['year'];
		}
		if ($record['country'] !== null) {
			$fields['events.0.country'] = $record['country'];
		}
		foreach (Builder::catalogNumbers($pairing['labels'], $record['barcodes']) as $i => $number) {
			$fields["labels.$i.catalog_number"] = $number['number'];
			if ($number['mb']) {
				$fields["labels.$i.mbid"] = $number['mb']['gid'];
			} else {
				$fields["labels.$i.name"] = $number['label'];
			}
		}
		$barcodes = Builder::barcodes($record);
		if (count($barcodes) === 1) {
			$fields['barcode'] = $barcodes[0];
		}
		$language = $this->languages[$record['language'] ?? ''] ?? null;
		if ($language !== null) {
			$fields['language'] = $language;
		}
		if (($proposal['proposed']['_create']['release']['script_id'] ?? null) !== null) {
			$fields['script'] = 'Latn';
		}
		if ($record['format'] !== null) {
			$fields['mediums.0.format'] = $record['format'];
		}
		foreach ($record['tracks'] as $i => $track) {
			$fields["mediums.0.track.$i.name"] = $track['title'];
			$fields["mediums.0.track.$i.number"] = $i + 1;
			if ($track['length'] !== null) {
				$fields["mediums.0.track.$i.length"] = $track['length'];
			}
		}
		$fields['edit_note'] = EditNote::create($proposal, $record, $pairing, seeded: true);
		return $fields;
	}

}
