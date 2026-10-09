<?php
// Local testing only: php -S 127.0.0.1:8000 -t web router.php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('~^/api(?:/.*)?$~',$path)) { $_SERVER['SCRIPT_NAME']='/api.php'; require __DIR__.'/web/api.php'; }
elseif ($path==='/settings.php' || str_contains($path,'..')) { http_response_code(404); }
else return false;
