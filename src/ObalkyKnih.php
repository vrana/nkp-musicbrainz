<?php declare(strict_types=1);

namespace NkpMusicBrainz;

use RuntimeException;

/**
 * whether obalkyknih.cz (covers collected by Czech libraries) has an entry for a record
 * its API is only for registered library catalogues, so the public page is checked, once per record viewed on the review page
 */
final class ObalkyKnih
{

	private const MISSING = 'ještě nemáme v databázi'; // "Tuto knihu ještě nemáme v databázi!"


	public function __construct(
		private readonly App $app,
	) {
	}


	public static function url(string $cnb): string
	{
		return 'https://www.obalkyknih.cz/view?nbn=' . rawurlencode($cnb);
	}


	/** @return ?array{found: bool, cover: bool} null if it should be checked, missing entries are checked again after 30 days */
	public function cached(string $cnb): ?array
	{
		$statement = $this->app->db()->prepare("SELECT found, cover FROM obalky_knih WHERE cnb = ? AND (found OR checked_at > now() - interval '30 days')");
		$statement->execute([$cnb]);
		$row = $statement->fetch();
		return $row ? ['found' => (bool) $row['found'], 'cover' => (bool) $row['cover']] : null;
	}


	/** @return array{found: bool, cover: bool} */
	public function check(string $cnb): array
	{
		$cached = $this->cached($cnb);
		if ($cached !== null) {
			return $cached;
		}
		$curl = curl_init(self::url($cnb));
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_FAILONERROR => true,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_USERAGENT => $this->app->config['user_agent'],
		]);
		$html = curl_exec($curl);
		if (!is_string($html)) {
			throw new RuntimeException('obalkyknih.cz: ' . curl_error($curl));
		}
		$result = ['found' => !str_contains($html, self::MISSING), 'cover' => str_contains($html, '/file/cover/')];
		$this->app->db()->prepare('
			INSERT INTO obalky_knih (cnb, found, cover) VALUES (?, ?, ?)
			ON CONFLICT (cnb) DO UPDATE SET found = excluded.found, cover = excluded.cover, checked_at = now()
		')->execute([$cnb, $result['found'] ? 't' : 'f', $result['cover'] ? 't' : 'f']);
		return $result;
	}

}
