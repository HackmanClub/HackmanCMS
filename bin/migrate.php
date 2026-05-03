#!/usr/bin/env php
<?php
define('ROOT', dirname(__DIR__));
$config = require ROOT . '/config/config.php';
require_once ROOT . '/lib/DB.php';

DB::connect($config['db_path']);
$applied = DB::autoMigrate(ROOT . '/sql');
foreach ($applied as $v) echo "  applied: $v\n";
if (!$applied) echo "  Nothing to do.\n";
