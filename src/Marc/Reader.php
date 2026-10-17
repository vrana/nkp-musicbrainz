<?php declare(strict_types=1);

namespace NkpMusicBrainz\Marc;

use DOMElement;
use RuntimeException;
use XMLReader;

/** streams a MARCXML collection (optionally gzipped) and yields musical sound recordings */
final class Reader
{

	public const NAMESPACE = 'http://www.loc.gov/MARC21/slim';

	public int $total = 0;


	/** @return \Generator<int, DOMElement> */
	public function records(string $path): \Generator
	{
		$reader = new XMLReader();
		$uri = str_ends_with($path, '.gz') ? 'compress.zlib://' . $path : $path;
		if (!$reader->open($uri, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
			throw new RuntimeException("Can't open $path");
		}
		while ($reader->read() && !($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'record')) {
			// find the first record
		}
		while ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'record') {
			$this->total++;
			$record = $reader->expand();
			if ($record instanceof DOMElement && Record::isMusic($record)) {
				yield $record;
			}
			if (!$reader->next('record')) { // sibling record, skipping the expanded subtree
				break;
			}
		}
		$reader->close();
	}

}
