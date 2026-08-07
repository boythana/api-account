<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);

            $loggerSettings = $settings->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },
        PDO::class => function (ContainerInterface $c) {
            // 1. ดึง Object Settings ออกมา
            $settingsInstance = $c->get(SettingsInterface::class);

            // 2. ดึงค่า 'db' ออกมา ซึ่งจะได้เป็น Array ที่คุณตั้งค่าไว้
            $dbSettings = $settingsInstance->get('db');

            // 3. นำค่าจาก $dbSettings (ที่เป็น Array) มาใช้
            $dsn = "mysql:host={$dbSettings['host']};port=3309;dbname={$dbSettings['database']};charset={$dbSettings['charset']}";

            $attributes = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];

            return new PDO($dsn, $dbSettings['username'], $dbSettings['password'], $attributes);
        },
    ]);
};
