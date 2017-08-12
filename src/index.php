<?php

require_once './Transpiler.php';

\header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
\header("Cache-Control: post-check=0, pre-check=0", false);
\header("Pragma: no-cache");

\header("Content-Type: text/plain");

$transpiled = (new SLBBTools\Transpiler($_GET['source']))->run();
echo($transpiled);
\file_put_contents($_GET['source'] . '_out.bb', $transpiled);
