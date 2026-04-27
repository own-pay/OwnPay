<?php
// see: https://github.com/phpstan/phpstan/issues/7843

declare(strict_types = 1);

$includes = [];

if(PHP_VERSION_ID < 80400){
	$includes[] = __DIR__.'/baseline-lt-8.4.neon';
}

$config                             = [];
$config['includes']                 = $includes;
$config['parameters']['phpVersion'] = PHP_VERSION_ID;

return $config;


