<?php

declare(strict_types=1);

use App\Config\Config;
use App\Support\Container;
use App\Support\Paths;

require dirname(__DIR__) . '/vendor/autoload.php';

$paths = new Paths(dirname(__DIR__));
$config = Config::load($paths);

date_default_timezone_set($config->string('APP_TIMEZONE', 'America/Bahia'));

$container = new Container();
$container->set(Paths::class, fn (): Paths => $paths);
$container->set(Config::class, fn (): Config => $config);

(require __DIR__ . '/services.php')($container);

return $container;
