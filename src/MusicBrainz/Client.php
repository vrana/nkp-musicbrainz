<?php declare(strict_types=1);

namespace NkpMusicBrainz\MusicBrainz;

use CurlHandle;
use NkpMusicBrainz\App;
use RuntimeException;

/**
 * musicbrainz.org as the bot account: the internal JSON edit endpoint of the release editor and the WS/2 API for checks
 * logging in needs a browser (MetaBrainz OAuth), the cookie jar starts with the remember_login cookie and curl keeps it updated
 */
final class Client
{

	public const RESPONSE_OK = 1;
	public const RESPONSE_NO_CHANGES = 2;

	private CurlHandle $curl;

	private float $lastRequest = 0;


	public function __construct(
		private readonly App $app,
	) {
		$this->curl = curl_init();
		curl_setopt_array($this->curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT => $app->config['user_agent'],
			CURLOPT_COOKIEFILE => $app->config['cookie_jar'],
			CURLOPT_COOKIEJAR => $app->config['cookie_jar'],
			CURLOPT_TIMEOUT => 120,
		]);
	}


	public function isPreview(): bool
	{
		return $this->app->config['mode'] !== 'submit';
	}


	/**
	 * @param list<array<string, mixed>> $edits
	 * @return array<string, mixed> {"edits": [{"response": 1, "entity": ...}]} or {"previews": [...]}
	 */
	public function edit(array $edits, string $note, bool $votable): array
	{
		$path = $this->isPreview() ? '/ws/js/edit/preview' : '/ws/js/edit/create';
		$response = $this->request('POST', $path, ['edits' => $edits, 'editNote' => $note, 'makeVotable' => $votable]);
		if (!$this->isPreview()) {
			foreach ($response['edits'] ?? [] as $edit) {
				if (!in_array($edit['response'] ?? null, [self::RESPONSE_OK, self::RESPONSE_NO_CHANGES], true)) {
					throw new RuntimeException('Unexpected response: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
				}
			}
			if (count($response['edits'] ?? []) !== count($edits)) {
				throw new RuntimeException('Unexpected response: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
			}
		}
		return $response;
	}


	/** @return array<string, mixed> current state on musicbrainz.org, the mirror may be behind */
	public function release(string $gid): array
	{
		return $this->request('GET', "/ws/2/release/$gid?inc=labels+media&fmt=json");
	}


	/** @return list<array<string, mixed>> MusicBrainz search results, best first */
	public function searchReleases(string $query, int $limit = 10): array
	{
		return $this->request('GET', '/ws/2/release?fmt=json&limit=' . $limit . '&query=' . rawurlencode($query))['releases'] ?? [];
	}


	public function releasesWithBarcode(string $barcode): int
	{
		return (int) ($this->request('GET', '/ws/2/release?fmt=json&limit=1&query=' . rawurlencode("barcode:$barcode"))['count'] ?? 0);
	}


	/**
	 * @param ?array<string, mixed> $json
	 * @return array<string, mixed>
	 */
	private function request(string $method, string $path, ?array $json = null): array
	{
		$wait = $this->lastRequest + 1.1 - microtime(true); // about one request per second
		if ($wait > 0) {
			usleep((int) ($wait * 1e6));
		}
		$headers = ['Accept: application/json']; // no Origin header, the endpoint then needs no CSRF token
		curl_setopt($this->curl, CURLOPT_URL, $this->app->config['server'] . $path);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
		if ($json !== null) {
			$headers[] = 'Content-Type: application/json; charset=utf-8';
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		} else {
			curl_setopt($this->curl, CURLOPT_HTTPGET, true);
		}
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
		$body = curl_exec($this->curl);
		$this->lastRequest = microtime(true);
		$status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
		if (!is_string($body)) {
			throw new RuntimeException("$method $path failed: " . curl_error($this->curl));
		}
		$data = json_decode($body, true);
		if ($status !== 200 || !is_array($data)) {
			$hint = $status === 401 || str_contains($body, 'logged in') ? ' (log in as the bot in a browser and store its cookie: bin/nkp-musicbrainz cookie remember_login=...)' : '';
			throw new RuntimeException("$method $path: HTTP $status$hint " . mb_substr(strip_tags($body), 0, 500));
		}
		return $data;
	}

}
