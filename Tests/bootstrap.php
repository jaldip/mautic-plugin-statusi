<?php

declare(strict_types=1);

$mauticRoot = dirname(__DIR__, 3);
require_once $mauticRoot.'/autoload.php';

$parameters = [];
include $mauticRoot.'/config/local.php';

if (!defined('MAUTIC_TABLE_PREFIX')) {
    define('MAUTIC_TABLE_PREFIX', (string) ($parameters['db_table_prefix'] ?? ''));
}

$GLOBALS['STATUSI_TEST_DB_CONFIG'] = [
    'driver'   => 'pdo_mysql',
    'host'     => getenv('STATUSI_TEST_DB_HOST') ?: ($parameters['db_host'] ?? '127.0.0.1'),
    'port'     => (int) (getenv('STATUSI_TEST_DB_PORT') ?: ($parameters['db_port'] ?? 3306)),
    'user'     => getenv('STATUSI_TEST_DB_USER') ?: ($parameters['db_user'] ?? 'root'),
    'password' => false !== getenv('STATUSI_TEST_DB_PASSWORD') ? getenv('STATUSI_TEST_DB_PASSWORD') : ($parameters['db_password'] ?? ''),
    'charset'  => 'utf8mb4',
];
$GLOBALS['STATUSI_TEST_SOURCE_DB'] = getenv('STATUSI_TEST_SOURCE_DB') ?: ($parameters['db_name'] ?? 'mautic');
$GLOBALS['STATUSI_TEST_DB']        = getenv('STATUSI_TEST_DB') ?: $GLOBALS['STATUSI_TEST_SOURCE_DB'].'_statusi_test';
