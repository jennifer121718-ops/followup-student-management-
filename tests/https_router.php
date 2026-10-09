<?php
// Only for local automated tests simulating Apache's trusted HTTPS flag.
$_SERVER['HTTPS']='on';
require dirname(__DIR__).'/router.php';
