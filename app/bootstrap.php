<?php

umask(0);

//echo (bcmul(microtime(TRUE) - $_SERVER['REQUEST_TIME_FLOAT'], 1000, 2).' bootstrap start'.PHP_EOL;
define('APP_DIR', __DIR__);

require __DIR__ . '/../vendor/autoload.php';

if (!is_writable(__DIR__ . '/../log')){ mkdir(__DIR__ . '/../log', 0777); }
if (!is_writable(__DIR__ . '/../temp')){ mkdir(__DIR__ . '/../temp', 0777); }

$configurator = new Nette\Configurator;
$configurator->setTimeZone('Europe/Prague');
//$configurator->setDebugMode();
$configurator->enableDebugger(__DIR__ . '/../log');
$configurator->setTempDirectory(__DIR__ . '/../temp');                       
$configurator->createRobotLoader()
             ->addDirectory(__DIR__)
             ->register();
$configurator->addConfig(__DIR__ . '/config.neon');

return $configurator->createContainer(); 
