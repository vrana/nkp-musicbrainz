<?php declare(strict_types=1);

use NkpMusicBrainz\MusicBrainz\EditNote;
use NkpMusicBrainz\Proposal\Builder;
use NkpMusicBrainz\Review;
use NkpMusicBrainz\Text;

/**
 * @var array{kind: string, show: string, processable: string, q: string} $filters
 * @var int $page
 * @var int $perPage
 * @var ?list<array<string, mixed>> $batch
 * @var ?array{total: int, records: list<array<string, mixed>>} $list
 * @var array<string, mixed> $summary
 * @var bool $preview
 * @var NkpMusicBrainz\MusicBrainz\Seed $seed
 * @var NkpMusicBrainz\ObalkyKnih $obalky
 */
$seeds = []; // forms posted to the release editor, outside of the main form

$h = fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$query = fn(array $changes): string => '?' . http_build_query(array_merge($filters, ['page' => $page], $changes));
$mb = fn(string $type, string $gid, string $name): string => '<a href="https://musicbrainz.org/' . $type . '/' . $h($gid) . '" target="_blank" rel="noopener">' . $h($name) . '</a>';
$search = fn(string $type, string $name): string => '<a class="search" href="https://musicbrainz.org/search?' . $h(http_build_query(['query' => $name, 'type' => $type, 'method' => 'indexed'])) . '" target="_blank" rel="noopener">search ' . $h($name) . '</a>';
$date = fn(array $date): string => implode('-', array_filter([$date['year'], $date['month'] ? sprintf('%02d', $date['month']) : null, $date['day'] ? sprintf('%02d', $date['day']) : null]));
$linkify = fn(string $text): string => (string) preg_replace('~https://\S+~', '<a href="$0" target="_blank" rel="noopener">$0</a>', $h($text));
$obalkyLink = fn(string $cnb, array $result): string => $result['found']
	? ' · <a href="' . $h(NkpMusicBrainz\ObalkyKnih::url($cnb)) . '" target="_blank" rel="noopener">Obálky knih' . ($result['cover'] ? ' (cover)' : '') . '</a>'
	: '';
$methods = [
	'barcode' => 'paired by barcode', 'catno' => 'paired by catalogue number', 'title' => 'paired by title and artist',
	'release-missing' => 'release group paired, this release missing', 'release-ambiguous' => 'release group paired, release ambiguous',
	'release-group-ambiguous' => 'title matches several release groups', 'none' => 'not on MusicBrainz',
	'manual' => 'paired manually', 'manual-release-group' => 'release group paired manually, this release missing', 'manual-missing' => "paired manually, not in the mirror yet",
	'manual-new' => 'checked manually: not on MusicBrainz',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NKP → MusicBrainz</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="style.css">
</head>
<body>
<main>
<header class="bar">
	<h1><a href="?">NKP → MusicBrainz</a></h1>
	<span class="muted"><?= $h($summary['open']) ?> open processable proposals · <?= $h($summary['ignored']) ?> ignored · <?= $h($summary['processed']) ?> processed</span>
</header>
<?php if ($preview) { ?>
	<div class="banner preview">Preview mode: "process" only asks MusicBrainz to preview the edits, nothing is submitted. Set <code>'mode' => 'submit'</code> in config.local.php to edit.</div>
<?php } ?>

<?php if (!empty($_GET['error'])) { ?><div class="banner error"><?= $h($_GET['error']) ?></div><?php } ?>

<?php if ($batch !== null) { ?>
	<?php if (!empty($_GET['stopped'])) { ?><div class="banner">Stopped: <?= $h($_GET['stopped']) ?></div><?php } ?>
	<div class="table-wrap"><table>
		<tr><th>Record</th><th>Kind</th><th>Result</th><th>Message</th></tr>
		<?php foreach ($batch as $decision) { ?>
			<tr>
				<td><a href="<?= $h(EditNote::recordUrl($decision)) ?>" target="_blank" rel="noopener"><?= $h($decision['title'] ?? $decision['cnb']) ?></a></td>
				<td><?= $h(Review::KINDS[$decision['kind']] ?? $decision['kind']) ?></td>
				<td><span class="state <?= $h($decision['state']) ?>"><?= $h($decision['state']) ?></span></td>
				<td><?= $linkify((string) $decision['message']) ?>
					<?php if ($decision['preview']) { ?><details class="preview"><summary>Show the edits as MusicBrainz renders them</summary><?= $decision['preview'] ?></details><?php } ?>
				</td>
			</tr>
		<?php } ?>
	</table></div>
	<p><a href="?">Back to the proposals</a></p>
<?php } else { ?>
	<form class="bar search" method="get" action="">
		<?php foreach (array_diff_key($filters, ['q' => true]) as $name => $value) { ?><input type="hidden" name="<?= $h($name) ?>" value="<?= $h($value) ?>"><?php } ?>
		<input type="search" name="q" id="q" value="<?= $h($filters['q']) ?>" placeholder="Title, artist, barcode, catalogue number, cnb…" aria-label="Search">
		<button type="submit">Search</button>
		<?php if ($filters['q'] !== '') { ?><a href="<?= $h($query(['q' => '', 'page' => 1])) ?>">clear</a><?php } ?>
	</form>
	<nav class="bar filters">
		<?php foreach (['kind' => ['all' => 'All kinds'] + Review::KINDS, 'processable' => Review::PROCESSABLE, 'show' => Review::SHOW] as $name => $options) { ?>
			<span>
				<?php foreach ($options as $value => $label) { ?>
					<a class="<?= $filters[$name] === $value ? 'on' : '' ?>" href="<?= $h($query([$name => $value, 'page' => 1])) ?>"><?= $h($label) ?></a>
				<?php } ?>
			</span>
		<?php } ?>
	</nav>
	<?php $pages = max(1, (int) ceil($list['total'] / $perPage)); ?>
	<form method="post" action="?">
	<input type="hidden" name="return" value="<?= $h(http_build_query(array_merge($filters, ['page' => $page]))) ?>">
	<?php foreach ($list['records'] as $item) {
		$record = $item['record'];
		$pairing = $item['pairing'];
		$release = $pairing['release'];
		$group = $pairing['release_group'];
		?>
		<section class="record" id="<?= $h($record['cnb']) ?>">
			<h2><?= $h($record['title']) ?> <?php if ($record['statement'] !== '') { ?><small>/ <?= $h($record['statement']) ?></small><?php } ?></h2>
			<div class="meta">
				<?= $h(implode(' · ', array_filter([
					implode(', ', $record['publishers']), $record['year'], Builder::COUNTRY_NAMES[$record['country'] ?? ''] ?? $record['country'],
					$record['physical'], implode(', ', $record['barcodes']),
					implode(', ', array_map(fn($number) => $number['number'], $record['catalog_numbers'])),
				]))) ?>
				· <a href="<?= $h(EditNote::recordUrl($record)) ?>" target="_blank" rel="noopener"><?= $h($record['cnb']) ?></a>
				<?php
				// linked unconditionally: telling whether obalkyknih.cz has an entry costs a request to their server per record shown
				// (their API is only for library catalogues), checkObalkyKnih() in the script below does it but isn't called
				$cached = $obalky->cached($record['cnb']);
				?>
				<?= $obalkyLink($record['cnb'], $cached ?? ['found' => true, 'cover' => false]) ?>
				<span<?= $cached === null ? ' data-obalky="' . $h($record['cnb']) . '"' : '' ?>></span>
				· <?= $h($methods[$pairing['method']] ?? $pairing['method']) ?>
			</div>
			<dl>
				<dt>Artists</dt>
				<dd>
					<?php foreach ($pairing['artists'] as $i => $artist) { ?>
						<?= $i ? ' · ' : '' ?><?= $h(Text::directName($artist['name'])) ?><?= $artist['roles'] ? ' <span class="meta">(' . $h(implode(', ', $artist['roles'])) . ')</span>' : '' ?>
						→ <?= $artist['mb']
							? implode(', ', array_map(fn($mbArtist) => $mb('artist', $mbArtist['gid'], $mbArtist['name']), $artist['mb'])) . ' <span class="meta">by ' . $h($artist['via']) . '</span>'
							: $search('artist', Text::directName($artist['name'])) ?>
					<?php } ?>
				</dd>
				<dt>Release group</dt>
				<dd><?= $group ? $mb('release-group', $group['gid'], $group['name']) : 'new: ' . $h($record['title']) . ' · ' . $search('release_group', $record['title']) ?></dd>
				<dt><label for="pair-<?= $h($record['cnb']) ?>">Pair with</label></dt>
				<dd class="pair">
					<?php $manual = $pairing['manual'] ? ($pairing['manual']['entity'] === 'new' ? 'new' : "https://musicbrainz.org/{$pairing['manual']['entity']}/{$pairing['manual']['gid']}") : ''; ?>
					<input type="text" id="pair-<?= $h($record['cnb']) ?>" name="pair[<?= $h($record['cnb']) ?>]" value="<?= $h($manual) ?>" list="suggest-<?= $h($record['cnb']) ?>"
						data-suggest="<?= $h($record['cnb']) ?>" autocomplete="off" placeholder="MusicBrainz release or release group URL, or new; focus for suggestions">
					<input type="hidden" name="pair_old[<?= $h($record['cnb']) ?>]" value="<?= $h($manual) ?>">
					<datalist id="suggest-<?= $h($record['cnb']) ?>"></datalist>
				</dd>
				<dt>Release</dt>
				<dd>
					<?= $release ? $mb('release', $release['gid'], $release['name']) . ' <span class="meta">' . $h($release['artist']) . '</span>' : 'new' ?>
				</dd>
			</dl>
			<?php if ($pairing['candidates'] || $release) {
				$newValue = $group ? "https://musicbrainz.org/release-group/{$group['gid']}" : 'new';
				$pairedValue = $release ? "https://musicbrainz.org/release/{$release['gid']}" : $newValue; // pre-selected, without a paired release the new one
				$credit = implode('', array_map(fn($name) => $name['name'] . ($name['join_phrase'] ?? ''), $pairing['credit']['names']))
					?: ($record['statement'] !== '' ? $record['statement'] : Text::directName($record['artists'][0]['name'] ?? ''));
				?>
				<div class="table-wrap"><table class="candidates">
					<tr><th></th><th>Release</th><th>Artist</th><th>Format</th><th>Tracks</th><th>Country/Date</th><th>Label</th><th>Catalog#</th><th>Barcode</th></tr>
					<?php foreach ($pairing['candidates'] as $candidate) {
						$value = "https://musicbrainz.org/release/{$candidate['gid']}";
						?>
						<tr class="<?= ($release['gid'] ?? null) === $candidate['gid'] ? 'paired' : '' ?>">
							<td><input type="radio" name="pick[<?= $h($record['cnb']) ?>]" value="<?= $h($value) ?>" aria-label="Pair with this release"<?= $pairedValue === $value ? ' checked' : '' ?>></td>
							<td><?= $mb('release', $candidate['gid'], $candidate['name']) ?><?= $candidate['comment'] ? ' <span class="meta">(' . $h($candidate['comment']) . ')</span>' : '' ?></td>
							<td><?= $h($candidate['artist']) ?></td>
							<td><?= $h(implode(' + ', array_map(fn($medium) => $medium['format'] ?? '?', $candidate['media']))) ?></td>
							<td><?= $h(implode(' + ', array_column($candidate['media'], 'track_count'))) ?></td>
							<td><?= implode('<br>', array_map(fn($event) => $h(trim(($event['country'] ?? '') . ' ' . $date($event['date']))), $candidate['events'])) ?></td>
							<td><?= implode('<br>', array_map(fn($label) => $h($label['label'] ?? ''), $candidate['labels'])) ?></td>
							<td><?= implode('<br>', array_map(fn($label) => $h($label['catalog_number'] ?? ''), $candidate['labels'])) ?></td>
							<td><?= $h($candidate['barcode'] ?? '') ?></td>
						</tr>
					<?php } ?>
					<tr class="new">
						<td><input type="radio" name="pick[<?= $h($record['cnb']) ?>]" value="<?= $h($newValue) ?>" aria-label="None of these, a new release"<?= $pairedValue === $newValue ? ' checked' : '' ?>>
							<input type="hidden" name="pick_old[<?= $h($record['cnb']) ?>]" value="<?= $h($pairedValue) ?>"></td>
						<td><?= $h($record['title']) ?> <span class="meta">(new release)</span></td>
						<td><?= $h($credit) ?></td>
						<td><?= $h(implode(' + ', array_fill(0, $record['discs'] ?? 1, $record['format'] ?? '?'))) ?></td>
						<td><?= count($record['tracks']) ?></td>
						<td><?= $h(trim(($record['country'] ?? '') . ' ' . ($record['year'] ?? ''))) ?></td>
						<?php $numbers = Builder::catalogNumbers($record['catalog_numbers'], $record['barcodes']); ?>
						<td><?= implode('<br>', array_map(fn($number) => $h($number['label']), $numbers)) ?></td>
						<td><?= implode('<br>', array_map(fn($number) => $h($number['number']), $numbers)) ?></td>
						<td><?= $h(implode(', ', Builder::barcodes($record))) ?></td>
					</tr>
				</table></div>
			<?php } ?>
			<div class="table-wrap"><table>
				<tr><th>Proposal</th><th>MusicBrainz now</th><th>Proposed</th><th>Choice</th></tr>
				<?php foreach ($item['proposals'] as $proposal) {
					$shown = array_filter($proposal['proposed'], fn($key) => $key[0] !== '_', ARRAY_FILTER_USE_KEY);
					$order = array_flip(['title', 'artist', 'release group', 'events', 'label', 'barcode', 'format', 'tracks']); // jsonb doesn't keep the order
					uksort($shown, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
					?>
					<tr>
						<td><?= $h(Review::KINDS[$proposal['kind']] ?? $proposal['kind']) ?>
							<?php if ($proposal['state']) { ?><div class="state <?= $h($proposal['state']) ?>"><?= $h($proposal['state']) ?></div><div class="meta"><?= $linkify((string) $proposal['message']) ?></div><?php } ?>
						</td>
						<td><?= $h(implode('; ', $proposal['current'])) ?: '–' ?></td>
						<td>
							<?php if ($proposal['kind'] === 'release') { ?>
								<div class="sub">
									<?php foreach ($shown as $field => $value) { ?>
										<span><?= $h($field) ?></span><span class="<?= $field === 'tracks' ? 'tracks' : '' ?>"><?= $h($value) ?: '–' ?></span>
									<?php } ?>
								</div>
							<?php } else { ?>
								<?= $h(implode('; ', $shown)) ?>
							<?php } ?>
							<?php if ($proposal['reason'] !== null) { ?><div class="reason"><?= $h($proposal['reason']) ?></div><?php } ?>
						</td>
						<td class="choice">
							<?php $name = 'choice[' . $proposal['key'] . ']'; ?>
							<label><input type="radio" name="<?= $h($name) ?>" value="none" checked> do nothing</label>
							<label><input type="radio" name="<?= $h($name) ?>" value="ignore"> ignore</label>
							<label><input type="radio" name="<?= $h($name) ?>" value="process"<?= $proposal['reason'] !== null || $proposal['state'] === 'processed' ? ' disabled' : '' ?>> process</label>
							<?php if ($proposal['kind'] === 'release') {
								$seeds[$proposal['key']] = $seed->fields($proposal, $record, $pairing); // the editor reads the seed only from a POST
								?>
								<button type="submit" class="link" form="seed-<?= $h($proposal['key']) ?>" title="Open the MusicBrainz release editor prefilled with this release">prefill at MusicBrainz</button>
							<?php } elseif ($proposal['target']) { // MusicBrainz can't prefill an edit of an existing release ?>
								<a href="https://musicbrainz.org/release/<?= $h($proposal['target']) ?>/edit" target="_blank" rel="noopener">edit at MusicBrainz</a>
							<?php } ?>
						</td>
					</tr>
				<?php } ?>
			</table></div>
		</section>
	<?php } ?>
	<?php if (!$list['records']) { ?><p>No proposals match the filters.</p><?php } ?>
	<div class="actions">
		<button type="submit" class="primary">Submit</button>
		<span>Set all on this page:</span>
		<?php foreach (['none' => 'do nothing', 'ignore' => 'ignore', 'process' => 'process'] as $value => $label) { ?>
			<button type="button" data-all="<?= $h($value) ?>"><?= $h($label) ?></button>
		<?php } ?>
		<span class="meta"><?= $h($list['total']) ?> records · page <?= $page ?> of <?= $pages ?></span>
		<?php if ($page > 1) { ?><a href="<?= $h($query(['page' => $page - 1])) ?>">previous</a><?php } ?>
		<?php if ($page < $pages) { ?><a href="<?= $h($query(['page' => $page + 1])) ?>">next</a><?php } ?>
	</div>
	</form>
	<?php foreach ($seeds as $key => $fields) { ?>
		<form id="seed-<?= $h($key) ?>" method="post" action="<?= $h(NkpMusicBrainz\MusicBrainz\Seed::URL) ?>" target="_blank" hidden>
			<?php foreach ($fields as $field => $value) { ?><input type="hidden" name="<?= $h($field) ?>" value="<?= $h($value) ?>"><?php } ?>
		</form>
	<?php } ?>
<?php } ?>
</main>
<script>
document.querySelectorAll('[data-suggest]').forEach(input => input.addEventListener('focus', async () => {
	if (input.dataset.loaded) {
		return;
	}
	input.dataset.loaded = '1';
	const placeholder = input.placeholder;
	input.placeholder = 'Searching MusicBrainz…';
	try {
		const response = await fetch('?suggest=' + encodeURIComponent(input.dataset.suggest));
		const suggestions = await response.json();
		if (!response.ok) {
			throw new Error(suggestions.error || response.statusText);
		}
		const datalist = document.getElementById(input.getAttribute('list'));
		for (const suggestion of suggestions) {
			datalist.append(new Option(suggestion.label, suggestion.value));
		}
		input.placeholder = suggestions.length ? placeholder : 'MusicBrainz found nothing, paste a URL';
	} catch (error) {
		input.placeholder = 'Search failed: ' + error.message;
		delete input.dataset.loaded;
	}
}));

// not called, too many requests to obalkyknih.cz (one per record shown), the records link it unconditionally instead
async function checkObalkyKnih() { // one request at a time, the answers are cached
	for (const span of document.querySelectorAll('[data-obalky]')) {
		try {
			const response = await fetch('?obalky=' + encodeURIComponent(span.dataset.obalky));
			const result = await response.json();
			if (response.ok && result && result.found) {
				span.append(' · ');
				const link = Object.assign(document.createElement('a'), {href: result.url, target: '_blank', rel: 'noopener', textContent: 'Obálky knih' + (result.cover ? ' (cover)' : '')});
				span.append(link);
			}
		} catch (error) {
			// no link, the next page load tries again
		}
	}
}

document.querySelectorAll('[data-all]').forEach(button => button.addEventListener('click', () => {
	document.querySelectorAll(`input[type=radio][value=${button.dataset.all}]:not(:disabled)`).forEach(radio => radio.checked = true);
}));
</script>
</body>
</html>
