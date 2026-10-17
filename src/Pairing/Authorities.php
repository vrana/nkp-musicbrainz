<?php declare(strict_types=1);

namespace NkpMusicBrainz\Pairing;

use NkpMusicBrainz\App;
use NkpMusicBrainz\Importer;
use RuntimeException;

/** NK ČR authority IDs of MusicBrainz artists from Wikidata, one query to QLever */
final class Authorities
{

	private const QUERY = 'PREFIX wdt: <http://www.wikidata.org/prop/direct/> SELECT ?nkc ?mb WHERE { ?item wdt:P691 ?nkc . ?item wdt:P434 ?mb . }';


	public function __construct(
		private readonly App $app,
	) {
	}


	public function load(): void
	{
		$curl = curl_init($this->app->config['qlever']);
		curl_setopt_array($curl, [
			CURLOPT_POSTFIELDS => http_build_query(['query' => self::QUERY]),
			CURLOPT_HTTPHEADER => ['Accept: text/tab-separated-values'],
			CURLOPT_USERAGENT => $this->app->config['user_agent'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FAILONERROR => true,
			CURLOPT_TIMEOUT => 300,
		]);
		$tsv = curl_exec($curl);
		if (!is_string($tsv)) {
			throw new RuntimeException('QLever failed: ' . curl_error($curl));
		}

		$authorities = [];
		$artists = [];
		foreach (array_slice(explode("\n", trim($tsv)), 1) as $line) {
			[$authority, $artist] = array_map(fn($value) => trim($value, '"'), explode("\t", $line) + [1 => '']);
			if (preg_match('~^[0-9a-f-]{36}$~', $artist)) {
				$authorities[] = $authority;
				$artists[] = $artist;
			}
		}

		$db = $this->app->db();
		$db->beginTransaction();
		$db->exec('DELETE FROM artist_authority');
		$db->prepare('INSERT INTO artist_authority SELECT * FROM unnest(CAST(:authority AS text[]), CAST(:artist AS uuid[])) ON CONFLICT DO NOTHING')
			->execute(['authority' => Importer::pgArray($authorities), 'artist' => Importer::pgArray($artists)]);
		$db->commit();
		$this->app->setMeta('authorities_loaded_at', date('c'));
		echo count($authorities) . " authority -> artist pairs\n";
	}

}
