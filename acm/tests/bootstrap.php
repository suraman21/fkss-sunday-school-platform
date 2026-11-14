<?php
/**
 * ACM PHPUnit bootstrap. Static requires only (folder safety net):
 * Composer's autoloader proves the committed PSR-4 mapping, then the
 * package manifest loads every class explicitly.
 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';
