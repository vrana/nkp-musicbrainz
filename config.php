<?php declare(strict_types=1);

// defaults, override any key in config.local.php returning an array
return [
	'database' => [
		'dsn' => 'pgsql:host=localhost;dbname=musicbrainz', // must contain both the MusicBrainz mirror and the state schema
		'user' => 'musicbrainz',
		'password' => '',
	],
	'mirror_schema' => 'musicbrainz', // tables of a MusicBrainz mirror (mbslave)
	'state_schema' => 'nkp_musicbrainz', // our records, pairing, proposals and decisions
	'server' => 'https://musicbrainz.org',
	'user_agent' => 'vrana_bot/0.1 ( jakub@vrana.cz )',
	'cookie_jar' => __DIR__ . '/var/cookies.txt', // Netscape format, start it with the remember_login cookie of the bot account
	'mode' => 'preview', // preview: /ws/js/edit/preview, nothing is submitted; submit: /ws/js/edit/create
	'make_votable' => false,
	'daily_edits' => 1000, // Code of Conduct/Bots
	'open_edits' => 2000, // only counts votable edits submitted in the last 7 days
	'records_per_page' => 50,
	'qlever' => 'https://qlever.dev/api/wikidata',
];
