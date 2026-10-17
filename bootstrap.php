<?php declare(strict_types=1);

// PSR-4 autoloading without Composer, the project has no dependencies
spl_autoload_register(function (string $class): void {
	$prefix = 'NkpMusicBrainz\\';
	if (str_starts_with($class, $prefix)) {
		require __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	}
});

return new NkpMusicBrainz\App(__DIR__);
