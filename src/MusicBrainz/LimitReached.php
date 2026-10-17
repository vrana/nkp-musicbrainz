<?php declare(strict_types=1);

namespace NkpMusicBrainz\MusicBrainz;

/** the daily or open edit limit of Code of Conduct/Bots, stops the whole batch */
final class LimitReached extends \RuntimeException
{
}
