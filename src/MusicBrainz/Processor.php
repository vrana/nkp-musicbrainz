<?php declare(strict_types=1);

namespace NkpMusicBrainz\MusicBrainz;

use NkpMusicBrainz\App;
use NkpMusicBrainz\Proposal\Builder;
use NkpMusicBrainz\Store;
use RuntimeException;

/** submits a proposal as edits of the bot account */
final class Processor
{

	public function __construct(
		private readonly App $app,
		private readonly Client $client,
		private readonly Store $store,
	) {
	}


	/**
	 * @param array<string, mixed> $proposal
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $pairing
	 * @return array{state: string, message: string, edits: list<mixed>, url: ?string}
	 */
	public function process(array $proposal, array $record, array $pairing): array
	{
		if ($proposal['reason'] !== null) {
			return self::result('failed', "not processable: $proposal[reason]");
		}
		$note = EditNote::create($proposal, $record, $pairing);
		try {
			return $proposal['kind'] === 'release'
				? $this->createRelease($proposal['proposed']['_create'], $note)
				: $this->editRelease($proposal, $note);
		} catch (LimitReached $e) {
			throw $e;
		} catch (RuntimeException $e) {
			return self::result('failed', $e->getMessage());
		}
	}


	/**
	 * @param array<string, mixed> $proposal
	 * @return array{state: string, message: string, edits: list<mixed>, url: ?string}
	 */
	private function editRelease(array $proposal, string $note): array
	{
		$url = "https://musicbrainz.org/release/$proposal[target]";
		$changed = $this->changedMeanwhile($proposal);
		if ($changed !== null) {
			return self::result('failed', "changed on MusicBrainz since the mirror: $changed", url: $url);
		}
		$edits = $proposal['proposed']['_edits'];
		$this->checkLimits(count($edits));
		$response = $this->client->edit($edits, $note, $this->votable());
		return $this->client->isPreview()
			? self::result('previewed', 'MusicBrainz would accept: ' . implode(', ', array_column($response['previews'] ?? [], 'editName')), url: $url, preview: self::preview($response))
			: self::result('processed', 'submitted', $response['edits'], "$url/edits");
	}


	/**
	 * release group, release, then its labels and medium, each step needs the ID from the previous one
	 * @param array<string, mixed> $create
	 * @return array{state: string, message: string, edits: list<mixed>, url: ?string}
	 */
	private function createRelease(array $create, string $note): array
	{
		$release = $create['release'];
		if ($release['barcode'] !== null && $this->client->releasesWithBarcode($release['barcode'])) {
			return self::result('failed', "a release with the barcode $release[barcode] exists on MusicBrainz now");
		}
		$this->checkLimits(($create['release_group'] ? 1 : 0) + 2 + count($create['labels']));
		$votable = $this->votable();

		if ($this->client->isPreview()) {
			$response = $this->client->edit(array_values(array_filter([$create['release_group'], $release + ['release_group_id' => $create['release_group_id']]])), $note, $votable);
			return self::result('previewed', 'MusicBrainz would accept: ' . implode(', ', array_column($response['previews'] ?? [], 'editName')) . ' (the label and tracklist edits can be checked only after the release exists)', preview: self::preview($response));
		}

		$edits = [];
		try {
			$groupId = $create['release_group_id'];
			if ($create['release_group']) {
				$response = $this->client->edit([$create['release_group']], $note, $votable);
				$edits = array_merge($edits, $response['edits']);
				$groupId = $response['edits'][0]['entity']['id'] ?? throw new RuntimeException('no release group in the response');
			}
			$response = $this->client->edit([$release + ['release_group_id' => $groupId]], $note, $votable);
			$edits = array_merge($edits, $response['edits']);
			$gid = $response['edits'][0]['entity']['gid'] ?? throw new RuntimeException('no release in the response');
			$rest = array_map(fn($edit) => $edit + ['release' => $gid], [...$create['labels'], $create['medium']]);
			$response = $this->client->edit($rest, $note, $votable);
			$edits = array_merge($edits, $response['edits']);
		} catch (RuntimeException $e) {
			return self::result('failed', 'stopped: ' . $e->getMessage(), $edits, isset($gid) ? "https://musicbrainz.org/release/$gid" : null);
		}
		return self::result('processed', 'created', $edits, "https://musicbrainz.org/release/$gid");
	}


	/**
	 * compares the live release with the snapshot the proposal was built from
	 * @param array<string, mixed> $proposal
	 */
	private function changedMeanwhile(array $proposal): ?string
	{
		$live = $this->client->release($proposal['target']);
		$current = $proposal['current'];
		switch ($proposal['kind']) {
			case 'barcode':
				return $live['barcode'] === null ? null : 'the barcode is set';
			case 'event':
				$events = array_map(function (array $event): array {
					[$year, $month, $day] = array_map(fn($part) => $part === '' ? null : (int) $part, explode('-', ($event['date'] ?? '') . '--'));
					return ['country' => $event['area']['iso-3166-1-codes'][0] ?? null, 'date' => ['year' => $year, 'month' => $month, 'day' => $day]];
				}, $live['release-events'] ?? []);
				return Builder::eventsText($events) === $current['events'] ? null : 'the release events are ' . Builder::eventsText($events);
			case 'catno':
				$label = (string) preg_replace('~: –$~u', '', $current['catalog number']);
				foreach ($live['label-info'] ?? [] as $info) {
					if (($info['label']['name'] ?? null) === $label && ($info['catalog-number'] ?? '') === '') {
						return null;
					}
				}
				return 'the label has a catalogue number';
			case 'label':
				return ($live['label-info'] ?? []) ? 'the release has a label' : null;
			case 'format':
				$formats = implode(' + ', array_map(fn($medium) => $medium['format'] ?? '?', $live['media'] ?? []));
				return $formats === $current['format'] ? null : "the format is $formats";
		}
		return null;
	}


	private function checkLimits(int $edits): void
	{
		if ($this->client->isPreview()) {
			return;
		}
		if ($this->store->editsToday() + $edits > $this->app->config['daily_edits']) {
			throw new LimitReached('daily edit limit reached');
		}
		if ($this->votable() && $this->store->openEdits() + $edits > $this->app->config['open_edits']) {
			throw new LimitReached('open edit limit reached');
		}
	}


	private function votable(): bool
	{
		return (bool) $this->app->config['make_votable'];
	}


	/**
	 * the edits as MusicBrainz renders them in the edit history, links made absolute
	 * @param array<string, mixed> $response
	 */
	private static function preview(array $response): string
	{
		$html = '';
		foreach ($response['previews'] ?? [] as $preview) {
			$html .= '<h3>' . htmlspecialchars((string) ($preview['editName'] ?? '')) . '</h3>' . ($preview['preview'] ?? '');
		}
		$html = (string) preg_replace('~<script\b.*?</script>~is', '', $html);
		return (string) preg_replace('~\b(href|src)="/(?!/)~', '$1="https://musicbrainz.org/', $html);
	}


	/**
	 * @param list<mixed> $edits
	 * @return array{state: string, message: string, edits: list<mixed>, url: ?string, preview: ?string}
	 */
	private static function result(string $state, string $message, array $edits = [], ?string $url = null, ?string $preview = null): array
	{
		return ['state' => $state, 'message' => $message, 'edits' => $edits, 'url' => $url, 'preview' => $preview];
	}

}
