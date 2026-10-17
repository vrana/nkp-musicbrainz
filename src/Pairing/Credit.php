<?php declare(strict_types=1);

namespace NkpMusicBrainz\Pairing;

use NkpMusicBrainz\Text;

/** release artist credit from the statement of responsibility (245 $c), every name must be a paired catalogue artist */
final class Credit
{

	private const JOIN_PHRASES = [';' => '; ', ',' => ', ', '&' => ' & ', 'a' => ' & ', 'and' => ' & '];

	private const PERFORMER_ROLES = ['prf', 'sng', 'itr', 'voc', 'cnd'];


	/**
	 * @param array<string, mixed> $record
	 * @param list<array<string, mixed>> $artists catalogue artists with MusicBrainz candidates
	 * @return array{names: list<array<string, mixed>>, reason: ?string}
	 */
	public static function resolve(array $record, array $artists): array
	{
		$credited = []; // [credited name, catalogue artist]
		$joins = [];
		if ($record['statement'] !== '') {
			$names = [];
			$separators = [];
			$separator = '';
			foreach (preg_split('~\s*(;|,|&|\ba\b|\band\b)\s*~u', $record['statement'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $piece) {
				if ($i % 2) {
					$separator = $piece;
				} elseif (trim($piece) !== '') { // "A, ; B" has an empty name between separators
					if ($names) {
						$separators[] = $separator;
					}
					$names[] = trim($piece);
				}
			}
			for ($i = 0; $i < count($names); $i = $end + 1) {
				for ($end = count($names) - 1; $end >= $i; $end--) { // the longest run first: "Eva a Vašek" is one group
					$name = $names[$i];
					for ($k = $i + 1; $k <= $end; $k++) {
						$name .= ' ' . $separators[$k - 1] . ' ' . $names[$k];
					}
					$artist = self::find($artists, $name);
					if ($artist !== null) {
						break;
					}
				}
				if ($artist === null) {
					return ['names' => [], 'reason' => "\"$names[$i]\" from the statement of responsibility isn't a credited artist"];
				}
				$credited[] = [str_replace([' , ', ' ; '], [', ', '; '], $name), $artist];
				if (isset($separators[$end])) {
					$joins[] = self::JOIN_PHRASES[$separators[$end]];
				}
			}
		} else {
			$main = array_values(array_filter($artists, fn($artist) => in_array($artist['tag'], ['100', '110'], true)));
			if (!$main) {
				$performers = array_values(array_filter($artists, fn($artist) => array_intersect($artist['roles'], self::PERFORMER_ROLES) !== []));
				$main = count($artists) === 1 ? $artists : (count($performers) === 1 ? $performers : []);
			}
			if (!$main) {
				return ['names' => [], 'reason' => 'no statement of responsibility and no single main artist'];
			}
			foreach ($main as $i => $artist) {
				$credited[] = [Text::directName($artist['name']), $artist];
				if ($i) {
					$joins[] = ' & ';
				}
			}
		}

		$names = [];
		foreach ($credited as $i => [$name, $artist]) {
			if (count($artist['mb']) !== 1) {
				return ['names' => [], 'reason' => $artist['mb'] ? "$artist[name] matches several MusicBrainz artists" : "$artist[name] isn't paired with a MusicBrainz artist"];
			}
			$mb = $artist['mb'][0];
			$names[] = ['artist' => ['id' => $mb['id'], 'gid' => $mb['gid'], 'name' => $mb['name']], 'name' => $name, 'join_phrase' => $joins[$i] ?? null];
		}
		return ['names' => $names, 'reason' => null];
	}


	/**
	 * @param list<array<string, mixed>> $artists
	 * @return ?array<string, mixed>
	 */
	private static function find(array $artists, string $name): ?array
	{
		foreach ($artists as $artist) {
			if (Text::code(Text::directName($artist['name'])) === Text::code($name) || Text::code($artist['name']) === Text::code($name)) {
				return $artist;
			}
		}
		return null;
	}

}
