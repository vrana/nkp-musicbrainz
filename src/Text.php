<?php declare(strict_types=1);

namespace NkpMusicBrainz;

use Normalizer;

/** normalization used for matching catalogue values with MusicBrainz */
final class Text
{

	public static function stripAccents(string $text): string
	{
		return (string) preg_replace('~\p{Mn}+~u', '', (string) Normalizer::normalize($text, Normalizer::FORM_KD));
	}


	/** lowercase ASCII letters and digits only, for catalogue numbers and titles */
	public static function code(string $text): string
	{
		return (string) preg_replace('~[^0-9a-z]+~', '', strtolower(self::stripAccents($text)));
	}


	/** digits without leading zeros, so UPC and EAN forms of one barcode match; '' if it isn't a barcode */
	public static function barcodeKey(string $barcode): string
	{
		$digits = (string) preg_replace('~\D+~', '', $barcode);
		$length = strlen($digits);
		return $length >= 8 && $length <= 14 ? ltrim($digits, '0') : '';
	}


	public static function isValidGtin(string $barcode): bool
	{
		if (!preg_match('~^(\d{8}|\d{12,14})$~', $barcode)) {
			return false;
		}
		$sum = 0;
		$digits = array_reverse(str_split(substr($barcode, 0, -1)));
		foreach ($digits as $i => $digit) {
			$sum += (int) $digit * ($i % 2 ? 1 : 3);
		}
		return (10 - $sum % 10) % 10 === (int) substr($barcode, -1);
	}


	public static function label(string $name): string
	{
		$name = self::code((string) preg_replace('~\(.*?\)~', '', $name));
		while (true) {
			$shorter = (string) preg_replace('~(records|recordings|music|musik|production|productions|spolsro|sro|as|ltd|gmbh|inc)$~', '', $name);
			if ($shorter === $name || strlen($shorter) < 2) { // B&M Music -> bm
				return $name;
			}
			$name = $shorter;
		}
	}


	public static function labelsAgree(string $a, string $b): bool
	{
		return self::prefixAgree(self::label($a), self::label($b), 3);
	}


	public static function titlesAgree(string $a, string $b): bool
	{
		return self::prefixAgree(self::code($a), self::code($b), 6);
	}


	/** without [cataloguer additions] and the ISBD punctuation at the end, an ellipsis transcribed as "--" (AACR2) is "…" again */
	public static function clean(string $text): string
	{
		$text = (string) preg_replace('~\[[^\]]*\]~u', '', $text);
		$text = (string) preg_replace('~(?<=[^\s-])--(?=[\s,;:/=]|$)~u', '…', $text);
		return trim((string) preg_replace('~[\s/:;,.=]+$~u', '', $text));
	}


	/** "Svěrák, Zdeněk" -> "Zdeněk Svěrák" */
	public static function directName(string $name): string
	{
		return preg_match('~^([^,]+), ([^,]+)$~u', $name, $match) ? "$match[2] $match[1]" : $name;
	}


	private static function prefixAgree(string $a, string $b, int $minimum): bool
	{
		return $a !== '' && $b !== '' && ($a === $b || (min(strlen($a), strlen($b)) >= $minimum && (str_starts_with($a, $b) || str_starts_with($b, $a))));
	}

}
