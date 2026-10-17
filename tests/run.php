<?php declare(strict_types=1);

// php tests/run.php - checks of the MARC parsing on real records from the Czech National Bibliography (CC0)

use NkpMusicBrainz\Marc\Reader;
use NkpMusicBrainz\Marc\Record;
use NkpMusicBrainz\Text;

require __DIR__ . '/../bootstrap.php';

$failures = 0;
$check = function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
	if ($actual !== $expected) {
		$failures++;
		echo "FAIL $name\n  expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n  actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
	}
};

$records = [];
foreach ((new Reader())->records(__DIR__ . '/fixtures/records.xml') as $element) {
	$record = Record::parse($element);
	$records[$record['cnb']] = $record;
}
$check('fixture count', count($records), 6);

$organ = $records['cnb000353747'];
$check('canonical record URL', $organ['url'], 'https://aleph.nkp.cz/F/?func=direct&doc_number=000353747&local_base=CNB');
$check('UPC barcode as printed', $organ['barcodes'], ['099925335526']);
$check('catalog number and label', $organ['catalog_numbers'], [['number' => 'SU 3355-2 131', 'label' => 'Supraphon']]);
$check('statement of responsibility', $organ['statement'], 'Johann Pachelbel ; Jaroslav Tůma');
$check('first track with length', $organ['tracks'][0], ['title' => 'Toccata et Fuga in d', 'length' => 214000, 'credit' => null]);
$check('format from 007', [$organ['format'], $organ['discs'], $organ['year'], $organ['country']], ['CD', 1, 1998, 'CZ']);
$check('main entry artist', $organ['artists'][0], ['tag' => '100', 'name' => 'Pachelbel, Johann', 'authority' => 'jn19990006331', 'roles' => ['cmp']]);

$hornet = $records['cnb003732492'];
$check('matrix numbers are not catalog numbers', $hornet['catalog_numbers'], [['number' => 'SU 6977-1', 'label' => 'Supraphon']]);
$check('vinyl', $hornet['format'], 'Vinyl');
$check('complete tracklist', [$hornet['tracks_source'], $hornet['tracks_complete'], count($hornet['tracks'])], ['t', true, 12]);

$seasons = $records['cnb002785621'];
$check('Czechoslovakia before 1993', $seasons['country'], 'XC');
$check('tracklist split from $a', [$seasons['tracks_source'], count($seasons['tracks']), $seasons['tracks'][1]['length']], ['a', 2, 969000]);

$day = $records['cnb003069690'];
$check('spaces around the dash in a catalog number', $day['catalog_numbers'][0]['number'], 'AV 042718-2');
$check('group without qualifier', $day['artists'][1]['name'], 'Ultramarin');

$burian = $records['cnb000188298'];
$check('qualifier without " --" continues the track', [count($burian['tracks']), end($burian['tracks'])['title']], [17, 'Prosím jízdenku (starý Berounský)']);

$check('GTIN checksum', [Text::isValidGtin('8595082724279'), Text::isValidGtin('8595082724270'), Text::isValidGtin('099925335526')], [true, false, true]);
$check('UPC and EAN forms match', Text::barcodeKey('0099925335526'), Text::barcodeKey('099925335526'));
$check('labels agree', [Text::labelsAgree('B&M', 'B&M Music'), Text::labelsAgree('Supraphon', 'Panton')], [true, false]);
$check('catalogue numbers that are barcodes', array_column(NkpMusicBrainz\Proposal\Builder::catalogNumbers([
	['number' => '7243 8 56809 2 2', 'label' => 'Monitor-EMI'], ['number' => '8 56809 2', 'label' => 'Monitor-EMI'],
	['number' => 'SU 6300-2', 'label' => 'Supraphon'], ['number' => '0081', 'label' => 'B&M'],
], []), 'number'), ['8 56809 2', 'SU 6300-2', '0081']);
$check('barcode from a catalogue number', NkpMusicBrainz\Proposal\Builder::barcodes(['barcodes' => [], 'catalog_numbers' => [['number' => '7243 8 56809 2 2', 'label' => ''], ['number' => '8 56809 2', 'label' => '']]]), ['724385680922']);
$check('own barcode first', NkpMusicBrainz\Proposal\Builder::barcodes(['barcodes' => ['099925630027'], 'catalog_numbers' => [['number' => '7243 8 56809 2 2', 'label' => '']]]), ['099925630027']);
$check('direct name', Text::directName('Svěrák, Zdeněk'), 'Zdeněk Svěrák');

echo $failures ? "$failures failed\n" : "all passed\n";
exit($failures ? 1 : 0);
