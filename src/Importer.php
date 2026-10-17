<?php declare(strict_types=1);

namespace NkpMusicBrainz;

use NkpMusicBrainz\Marc\Reader;
use NkpMusicBrainz\Marc\Record;
use RuntimeException;

/** loads music records of a MARC21 dump into the state schema */
final class Importer
{

	public function __construct(
		private readonly App $app,
	) {
	}


	/** the cache is used whenever it exists, it is downloaded only when missing or with $refresh */
	public function import(string $url, string $cache, bool $refresh): void
	{
		if ($refresh || !is_file($cache)) {
			$this->download($url, $cache);
		} else {
			echo "Using $cache from " . date('Y-m-d H:i', (int) filemtime($cache)) . ", pass --refresh to download $url again\n";
		}

		$db = $this->app->db();
		$db->beginTransaction();
		$db->exec('CREATE TEMP TABLE seen (cnb text PRIMARY KEY) ON COMMIT DROP');
		$reader = new Reader();
		$batch = [];
		$music = 0;
		$changed = 0;
		foreach ($reader->records($cache) as $element) {
			$record = Record::parse($element);
			if ($record['cnb'] === '') {
				continue;
			}
			$batch[$record['cnb']] = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$music++;
			if (count($batch) >= 500) {
				$changed += $this->save($batch);
				$batch = [];
				fwrite(STDERR, "\r$reader->total records, $music music");
			}
		}
		$changed += $this->save($batch);
		$deleted = $db->exec('DELETE FROM record WHERE cnb NOT IN (SELECT cnb FROM seen)');
		$db->commit();

		$this->app->setMeta('source_url', $url);
		$this->app->setMeta('source_date', date('c', (int) filemtime($cache)));
		if ($changed || $deleted) {
			$this->app->setMeta('records_changed_at', date('c'));
		}
		fwrite(STDERR, "\r");
		echo "$reader->total records, $music music: $changed new or changed, $deleted deleted\n";
	}


	/** @param array<string, string> $batch cnb => JSON */
	private function save(array $batch): int
	{
		if (!$batch) {
			return 0;
		}
		$db = $this->app->db();
		$parameters = ['cnb' => self::pgArray(array_map('strval', array_keys($batch)))];
		$db->prepare('INSERT INTO seen SELECT unnest(CAST(:cnb AS text[])) ON CONFLICT DO NOTHING')->execute($parameters);
		$statement = $db->prepare('
			INSERT INTO record (cnb, data)
			SELECT * FROM unnest(CAST(:cnb AS text[]), CAST(:data AS jsonb[]))
			ON CONFLICT (cnb) DO UPDATE SET data = excluded.data, imported_at = now()
			WHERE record.data IS DISTINCT FROM excluded.data
		');
		$statement->execute($parameters + ['data' => self::pgArray(array_values($batch))]);
		return $statement->rowCount();
	}


	/** @param list<string> $values */
	public static function pgArray(array $values): string
	{
		return '{' . implode(',', array_map(fn($value) => '"' . addcslashes($value, '"\\') . '"', $values)) . '}';
	}


	private function download(string $url, string $cache): void
	{
		echo "Downloading $url to $cache\n";
		$temporary = "$cache.part";
		$file = fopen($temporary, 'wb') ?: throw new RuntimeException("Can't write $temporary");
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_FILE => $file,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_FAILONERROR => true,
			CURLOPT_USERAGENT => $this->app->config['user_agent'],
			CURLOPT_NOPROGRESS => false,
			CURLOPT_XFERINFOFUNCTION => function ($curl, int $total, int $done): int {
				if ($total) {
					fwrite(STDERR, sprintf("\r%d / %d MB", $done >> 20, $total >> 20));
				}
				return 0;
			},
		]);
		$ok = curl_exec($curl);
		fclose($file);
		fwrite(STDERR, "\n");
		if (!$ok) {
			unlink($temporary);
			throw new RuntimeException('Download failed: ' . curl_error($curl));
		}
		rename($temporary, $cache);
	}

}
