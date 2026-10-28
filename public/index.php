<?php declare(strict_types=1);

use NkpMusicBrainz\MusicBrainz\Client;
use NkpMusicBrainz\MusicBrainz\LimitReached;
use NkpMusicBrainz\MusicBrainz\Processor;
use NkpMusicBrainz\MusicBrainz\Seed;
use NkpMusicBrainz\ObalkyKnih;
use NkpMusicBrainz\MusicBrainz\Suggest;
use NkpMusicBrainz\Pairing\Pairer;
use NkpMusicBrainz\Review;
use NkpMusicBrainz\Store;

$app = require __DIR__ . '/../bootstrap.php';
$review = new Review($app);
$store = new Store($app);

if (isset($_GET['suggest'])) { // MusicBrainz releases for the manual pairing datalist
	header('Content-Type: application/json');
	try {
		$record = $review->record((string) $_GET['suggest']);
		echo json_encode($record ? (new Suggest(new Client($app)))->releases($record) : [], JSON_UNESCAPED_UNICODE);
	} catch (RuntimeException $e) {
		http_response_code(502);
		echo json_encode(['error' => $e->getMessage()]);
	}
	exit;
}

if (isset($_GET['obalky'])) { // whether obalkyknih.cz has an entry, asked by the page for records without a cached answer
	header('Content-Type: application/json');
	try {
		$cnb = (string) $_GET['obalky'];
		echo json_encode($review->record($cnb) ? (new ObalkyKnih($app))->check($cnb) + ['url' => ObalkyKnih::url($cnb)] : null);
	} catch (RuntimeException $e) {
		http_response_code(502);
		echo json_encode(['error' => $e->getMessage()]);
	}
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') !== 'same-origin') {
		http_response_code(403);
		exit('Only same-origin form submissions are accepted.');
	}
	set_time_limit(0);

	$choices = array_filter((array) ($_POST['choice'] ?? []), fn($choice) => in_array($choice, ['ignore', 'process'], true));
	$chosen = $review->proposals(array_map('strval', array_keys($choices))); // before re-pairing, which rebuilds the proposals of re-paired records
	$repaired = [];
	$errors = [];
	foreach ((array) ($_POST['pair'] ?? []) as $cnb => $url) {
		$url = trim((string) $url);
		$old = trim((string) ($_POST['pair_old'][$cnb] ?? ''));
		$picked = (string) ($_POST['pick'][$cnb] ?? '');
		if ($url === $old) { // the text field wins over the candidates table, whose radios start at the current pairing
			if ($picked === (string) ($_POST['pick_old'][$cnb] ?? '')) {
				continue;
			}
			$url = $picked;
		}
		try {
			$store->pairManually((string) $cnb, $url === '' ? null : $url);
			$repaired[] = (string) $cnb;
		} catch (InvalidArgumentException $e) {
			$errors[] = "$cnb: " . $e->getMessage();
		}
	}
	if ($repaired) {
		(new Pairer($app))->pairRecords($repaired);
		$carried = []; // choices of re-paired records go to their rebuilt proposals of the same kind
		foreach ($chosen as $key => $proposal) {
			if (in_array($proposal['cnb'], $repaired, true)) {
				$carried[$proposal['cnb']][$proposal['kind']] = $choices[$key];
				unset($choices[$key]);
			}
		}
		foreach ($review->kinds(array_map('strval', array_keys($carried))) as $key => $proposal) {
			if (isset($carried[$proposal['cnb']][$proposal['kind']])) {
				$choices[$key] = $carried[$proposal['cnb']][$proposal['kind']];
			}
		}
	}

	$proposals = $review->proposals(array_map('strval', array_keys($choices)));
	if (!$proposals) {
		parse_str((string) ($_POST['return'] ?? ''), $return); // back to the same page of the list
		$return['error'] = implode('; ', $errors) ?: null;
		header('Location: ?' . http_build_query($return) . ($repaired ? '#' . rawurlencode($repaired[0]) : ''), true, 303);
		exit;
	}
	$batch = $store->nextBatch();
	$processor = new Processor($app, new Client($app), $store);
	$stopped = '';
	foreach ($proposals as $key => $proposal) {
		if ($choices[$key] === 'ignore') {
			$store->decide($key, $proposal['cnb'], $proposal['kind'], 'ignored', batch: $batch);
			continue;
		}
		try {
			$result = $processor->process($proposal, $proposal['record'], $proposal['pairing']);
		} catch (LimitReached $e) {
			$stopped = $e->getMessage();
			break;
		}
		$message = trim($result['message'] . ' ' . ($result['url'] ?? ''));
		$store->decide($key, $proposal['cnb'], $proposal['kind'], $result['state'], $result['edits'], $message, (bool) $app->config['make_votable'], $batch, $result['preview']);
	}
	header('Location: ?' . http_build_query(['batch' => $batch, 'stopped' => $stopped ?: null, 'error' => implode('; ', $errors) ?: null]), true, 303);
	exit;
}

$filters = [
	'kind' => (string) ($_GET['kind'] ?? 'all'),
	'show' => (string) ($_GET['show'] ?? 'open'),
	'processable' => (string) ($_GET['processable'] ?? 'yes'),
	'q' => trim((string) ($_GET['q'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$batch = isset($_GET['batch']) ? $review->batch((int) $_GET['batch']) : null;
$list = $batch === null ? $review->records($filters, $page) : null;
$summary = $review->summary();
$preview = $app->config['mode'] !== 'submit';
$perPage = (int) $app->config['records_per_page'];
$seed = new Seed($app);
$obalky = new ObalkyKnih($app);

require __DIR__ . '/../templates/page.php';
