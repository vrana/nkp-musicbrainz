<?php declare(strict_types=1);

namespace NkpMusicBrainz;

/** data of the review page */
final class Review
{

	public const KINDS = ['barcode' => 'Barcode', 'event' => 'Release event', 'catno' => 'Catalogue number', 'label' => 'Label', 'format' => 'Format', 'release' => 'New release'];

	public const SHOW = ['open' => 'Open', 'ignored' => 'Ignored', 'processed' => 'Processed', 'all' => 'All'];

	public const PROCESSABLE = ['yes' => 'Processable', 'no' => 'Not processable', 'all' => 'All'];


	public function __construct(
		private readonly App $app,
	) {
	}


	/**
	 * @param array{kind: string, show: string, processable: string, q: string} $filters
	 * @return array{string, array<string, string>} SQL condition and parameters, r is the record
	 */
	private function where(array $filters): array
	{
		$conditions = ['TRUE'];
		$parameters = [];
		$searched = "concat_ws(' ', r.cnb, r.data->>'title', r.data->>'statement', r.data->'artists', r.data->'barcodes', r.data->'catalog_numbers', r.data->'publishers')";
		foreach (preg_split('~\s+~u', trim($filters['q']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $word) { // every word anywhere
			$conditions[] = "$searched ILIKE :q$i";
			$parameters["q$i"] = '%' . addcslashes($word, '%_\\') . '%';
		}
		if (isset(self::KINDS[$filters['kind']])) {
			$conditions[] = 'p.kind = :kind';
			$parameters['kind'] = $filters['kind'];
		}
		$conditions[] = match ($filters['show']) {
			'ignored' => "d.state = 'ignored'",
			'processed' => "d.state = 'processed'",
			'all' => 'TRUE',
			default => "(d.state IS NULL OR d.state IN ('failed', 'previewed'))",
		};
		$conditions[] = match ($filters['processable']) {
			'yes' => 'p.reason IS NULL',
			'no' => 'p.reason IS NOT NULL',
			default => 'TRUE',
		};
		return [implode(' AND ', $conditions), $parameters];
	}


	/**
	 * records with their proposals, paginated by record
	 * @param array{kind: string, show: string, processable: string, q: string} $filters
	 * @return array{total: int, records: list<array<string, mixed>>}
	 */
	public function records(array $filters, int $page): array
	{
		$db = $this->app->db();
		[$where, $parameters] = $this->where($filters);
		$from = "FROM proposal p LEFT JOIN decision d USING (key) JOIN record r ON r.cnb = p.cnb WHERE $where";
		$statement = $db->prepare("SELECT count(DISTINCT p.cnb) $from");
		$statement->execute($parameters);
		$total = (int) $statement->fetchColumn();

		$perPage = (int) $this->app->config['records_per_page'];
		$statement = $db->prepare("SELECT DISTINCT p.cnb $from ORDER BY p.cnb LIMIT $perPage OFFSET " . max(0, $page - 1) * $perPage);
		$statement->execute($parameters);
		$cnbs = $statement->fetchAll(\PDO::FETCH_COLUMN);
		if (!$cnbs) {
			return ['total' => $total, 'records' => []];
		}

		$statement = $db->prepare("
			SELECT p.*, d.state, d.message, r.data AS record, pa.data AS pairing
			FROM proposal p LEFT JOIN decision d USING (key) JOIN record r ON r.cnb = p.cnb JOIN pairing pa ON pa.cnb = p.cnb
			WHERE $where AND p.cnb = ANY(CAST(:cnbs AS text[]))
			ORDER BY p.cnb, array_position(ARRAY['barcode', 'event', 'label', 'catno', 'format', 'release'], p.kind), p.key
		");
		$statement->execute($parameters + ['cnbs' => Importer::pgArray($cnbs)]);
		$records = [];
		foreach ($statement as $row) {
			$cnb = $row['cnb'];
			$records[$cnb] ??= ['record' => self::decode($row['record']), 'pairing' => self::decode($row['pairing']), 'proposals' => []];
			$records[$cnb]['proposals'][] = self::proposal($row);
		}
		return ['total' => $total, 'records' => array_values($records)];
	}


	/**
	 * proposals chosen on the page, in the page order
	 * @param list<string> $keys
	 * @return array<string, array<string, mixed>>
	 */
	public function proposals(array $keys): array
	{
		$statement = $this->app->db()->prepare('
			SELECT p.*, r.data AS record, pa.data AS pairing FROM proposal p JOIN record r USING (cnb) JOIN pairing pa USING (cnb)
			WHERE p.key = ANY(CAST(:keys AS text[]))
		');
		$statement->execute(['keys' => Importer::pgArray($keys)]);
		$proposals = [];
		foreach ($statement as $row) {
			$proposals[$row['key']] = self::proposal($row) + ['record' => self::decode($row['record']), 'pairing' => self::decode($row['pairing'])];
		}
		return array_replace(array_intersect_key(array_flip($keys), $proposals), $proposals);
	}


	/** @return ?array<string, mixed> */
	public function record(string $cnb): ?array
	{
		$statement = $this->app->db()->prepare('SELECT data FROM record WHERE cnb = ?');
		$statement->execute([$cnb]);
		$data = $statement->fetchColumn();
		return $data === false ? null : self::decode($data);
	}


	/** @return list<array<string, mixed>> */
	public function batch(int $batch): array
	{
		$statement = $this->app->db()->prepare("
			SELECT d.*, r.data->>'title' AS title, r.data->>'url' AS url FROM decision d LEFT JOIN record r USING (cnb) WHERE d.batch = ? ORDER BY d.decided_at
		");
		$statement->execute([$batch]);
		return $statement->fetchAll();
	}


	/** @return array<string, mixed> */
	public function summary(): array
	{
		return $this->app->db()->query("
			SELECT count(*) FILTER (WHERE p.reason IS NULL AND (d.state IS NULL OR d.state IN ('failed', 'previewed'))) AS open,
				count(*) FILTER (WHERE d.state = 'ignored') AS ignored,
				count(*) FILTER (WHERE d.state = 'processed') AS processed
			FROM proposal p LEFT JOIN decision d USING (key)
		")->fetch();
	}


	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function proposal(array $row): array
	{
		return [
			'key' => $row['key'], 'cnb' => $row['cnb'], 'kind' => $row['kind'], 'target' => $row['target'], 'reason' => $row['reason'],
			'current' => self::decode($row['current']), 'proposed' => self::decode($row['proposed']),
			'state' => $row['state'] ?? null, 'message' => $row['message'] ?? null,
		];
	}


	/** @return array<string, mixed> */
	private static function decode(string $json): array
	{
		return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
	}

}
