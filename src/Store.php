<?php declare(strict_types=1);

namespace NkpMusicBrainz;

/** decisions about proposals, they survive regenerating the proposals */
final class Store
{

	public function __construct(
		private readonly App $app,
	) {
	}


	/** @param list<mixed> $edits */
	public function decide(string $key, string $cnb, string $kind, string $state, array $edits = [], ?string $message = null, bool $votable = false, ?int $batch = null, ?string $preview = null): void
	{
		$this->app->db()->prepare('
			INSERT INTO decision (key, cnb, kind, state, edits, message, votable, batch, preview) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT (key) DO UPDATE SET state = excluded.state, edits = excluded.edits, message = excluded.message,
				votable = excluded.votable, batch = excluded.batch, preview = excluded.preview, decided_at = now()
		')->execute([$key, $cnb, $kind, $state, json_encode($edits, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $message, $votable ? 't' : 'f', $batch, $preview]);
	}


	/** @param ?string $url MusicBrainz release or release group URL, a release MBID, 'new' (not on MusicBrainz) or null removing the pairing */
	public function pairManually(string $cnb, ?string $url): void
	{
		$db = $this->app->db();
		if ($url === null) {
			$db->prepare('DELETE FROM manual_pairing WHERE cnb = ?')->execute([$cnb]);
			return;
		}
		if ($url === 'new') {
			$db->prepare("INSERT INTO manual_pairing (cnb, entity) VALUES (?, 'new') ON CONFLICT (cnb) DO UPDATE SET entity = excluded.entity, gid = NULL, created_at = now()")->execute([$cnb]);
			return;
		}
		if (!preg_match('~(?:(release-group|release)/)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i', $url, $match)) {
			throw new \InvalidArgumentException("\"$url\" isn't a MusicBrainz release URL or MBID");
		}
		$db->prepare('
			INSERT INTO manual_pairing (cnb, entity, gid) VALUES (?, ?, ?)
			ON CONFLICT (cnb) DO UPDATE SET entity = excluded.entity, gid = excluded.gid, created_at = now()
		')->execute([$cnb, $match[1] ?: 'release', strtolower($match[2])]);
	}


	public function nextBatch(): int
	{
		return (int) $this->app->db()->query('SELECT coalesce(max(batch), 0) + 1 FROM decision')->fetchColumn();
	}


	/** edits submitted in the last 24 hours */
	public function editsToday(): int
	{
		return (int) $this->app->db()->query("SELECT coalesce(sum(jsonb_array_length(edits)), 0) FROM decision WHERE state = 'processed' AND decided_at > now() - interval '1 day'")->fetchColumn();
	}


	/** upper bound of open edits: votable edits submitted in the last 7 days (the voting period) */
	public function openEdits(): int
	{
		return (int) $this->app->db()->query("SELECT coalesce(sum(jsonb_array_length(edits)), 0) FROM decision WHERE state = 'processed' AND votable AND decided_at > now() - interval '7 days'")->fetchColumn();
	}

}
