<?php declare(strict_types=1);

namespace NkpMusicBrainz;

use PDO;

final class App
{

	/** @var array<string, mixed> */
	public readonly array $config;

	private ?PDO $db = null;


	public function __construct(string $root)
	{
		$config = require $root . '/config.php';
		if (is_file($root . '/config.local.php')) {
			$config = array_replace_recursive($config, require $root . '/config.local.php');
		}
		$this->config = $config;
	}


	public function db(): PDO
	{
		if ($this->db === null) {
			$database = $this->config['database'];
			$this->db = new PDO($database['dsn'], $database['user'], $database['password'], [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);
			$state = $this->identifier($this->config['state_schema']);
			$this->db->exec("CREATE SCHEMA IF NOT EXISTS $state");
			$this->db->exec("SET search_path = $state, " . $this->identifier($this->config['mirror_schema']));
			$this->db->exec((string) file_get_contents(__DIR__ . '/../sql/schema.sql'));
		}
		return $this->db;
	}


	/** qualified MusicBrainz mirror table */
	public function mb(string $table): string
	{
		return $this->identifier($this->config['mirror_schema']) . '.' . $this->identifier($table);
	}


	public function meta(string $key): ?string
	{
		$statement = $this->db()->prepare('SELECT value FROM meta WHERE key = ?');
		$statement->execute([$key]);
		$value = $statement->fetchColumn();
		return $value === false ? null : $value;
	}


	public function setMeta(string $key, string $value): void
	{
		$this->db()->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
			->execute([$key, $value]);
	}


	private function identifier(string $name): string
	{
		return '"' . str_replace('"', '""', $name) . '"';
	}

}
