<?php
declare(strict_types=1);
// Default: a private sibling of the closest public_html folder (XServer).
// For custom layouts, replace $private with an absolute path OUTSIDE public_html.
$private = getenv('FOLLOWUP_PRIVATE_DIR') ?: null;
if (!$private) {
    $folder = __DIR__;
    while ($folder !== dirname($folder) && basename($folder) !== 'public_html') $folder = dirname($folder);
    $private = basename($folder) === 'public_html' ? dirname($folder) . '/followup-private' : dirname(__DIR__) . '/data/php';
}
return ['private_dir' => $private];
