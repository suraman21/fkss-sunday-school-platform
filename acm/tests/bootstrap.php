<?php
/**
 * ACM PHPUnit bootstrap. Prefers Composer's autoloader (proves the
 * committed PSR-4 mapping once the dev machine regenerates it) and
 * falls back to the package SPL loader otherwise.
 */
$composer = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
}
require_once dirname(__DIR__) . '/autoload.php';
