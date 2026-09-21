<?php declare(strict_types=1);

$autoload = __DIR__ . '/../../../../vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException('Run the suite inside the shop container, where vendor/ is available.');
}

require_once $autoload;

// The shop also ships this plugin through vendor/frosh/altcha-captcha. Prepending the
// loader makes the working copy win, so the suite tests the sources next to it.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Frosh\\AltchaCaptcha\\Tests\\' => __DIR__ . '/',
        'Frosh\\AltchaCaptcha\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
}, true, true);
