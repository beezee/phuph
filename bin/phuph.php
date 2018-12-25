<?php

require_once(__DIR__.'/../vendor/autoload.php');

echo phuph\Phuph::parse(file_get_contents($argv[1]));
