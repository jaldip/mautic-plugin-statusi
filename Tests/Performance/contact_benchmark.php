<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeCriteriaFactory;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeReportBuilder;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactChangeRepository;
use MauticPlugin\MauticStatusiBundle\ContactChanges\ContactFieldCatalog;
use Symfony\Component\HttpFoundation\Request;

require __DIR__.'/../bootstrap.php';

$options  = getopt('', ['contacts::', 'keep', 'reuse']);
$contacts = (int) ($options['contacts'] ?? 1000000);
$reuse    = isset($options['reuse']);
$updates  = (int) ($contacts * 0.5);   // audit "update" rows over the last 30 days
$noise    = (int) ($contacts * 0.3);   // other audit rows (identified, other bundles)
$days     = 365;
$base     = '2025-10-10 00:00:00';     // contacts created from here to 2026-10-09

$config = $GLOBALS['STATUSI_TEST_DB_CONFIG'];
$source = $GLOBALS['STATUSI_TEST_SOURCE_DB'];
$target = $source.'_statusi_contact_perf';
$p      = MAUTIC_TABLE_PREFIX;
$tables = ['leads', 'lead_fields', 'audit_log', 'users', 'stages'];

$server = DriverManager::getConnection($config);

function line(string $text = ''): void
{
    echo $text, PHP_EOL;
}

function timed(string $label, callable $fn): void
{
    $start = microtime(true);
    $fn();
    line(sprintf('  %-40s %8.1f s', $label, microtime(true) - $start));
}

/**
 * @param string[] $given
 *
 * @return array<string, string>
 */
function requiredDefaults(Connection $conn, string $table, array $given): array
{
    $defaults = [];
    foreach ($conn->fetchAllAssociative('SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) as $row) {
        if (in_array($row['COLUMN_NAME'], $given, true) || 'YES' === $row['IS_NULLABLE'] || null !== $row['COLUMN_DEFAULT'] || str_contains((string) $row['EXTRA'], 'auto_increment')) {
            continue;
        }
        $type                          = strtolower((string) $row['DATA_TYPE']);
        $defaults[$row['COLUMN_NAME']] = match (true) {
            str_contains($type, 'int'), str_contains($type, 'double') => '0',
            str_contains($type, 'date'), str_contains($type, 'time') => "'2026-01-01 00:00:00'",
            str_contains($type, 'text') => "'[]'",
            default => "''",
        };
    }

    return $defaults;
}

/**
 * @param array<string, string> $columns
 */
function fill(Connection $conn, string $table, int $rows, array $columns): void
{
    $columns += requiredDefaults($conn, $table, array_keys($columns));
    $conn->executeStatement('INSERT INTO `'.$table.'` (`'.implode('`, `', array_keys($columns)).'`) SELECT '.implode(', ', $columns).' FROM seq_1_to_'.$rows);
}

line('Contact attribute report benchmark');
line(sprintf('Data: %d contacts created over %d days (10%% with a later date_modified), %d audit updates in the last 30 days, %d other audit rows', $contacts, $days, $updates, $noise));
line();

$exists = (bool) $server->fetchOne('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$target]);
if (!$reuse || !$exists) {
    line('Generating data');
    $server->executeStatement('DROP DATABASE IF EXISTS `'.$target.'`');
    $server->executeStatement('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($tables as $table) {
        $server->executeStatement('CREATE TABLE `'.$target.'`.`'.$p.$table.'` LIKE `'.$source.'`.`'.$p.$table.'`');
    }
    $server->executeStatement('INSERT INTO `'.$target.'`.`'.$p.'lead_fields` SELECT * FROM `'.$source.'`.`'.$p.'lead_fields`');
    $conn = DriverManager::getConnection($config + ['dbname' => $target]);

    $added = "(TIMESTAMP('".$base."') + INTERVAL FLOOR(seq * ".($days * 86400).' / '.$contacts.') SECOND)';
    timed('contacts', function () use ($conn, $p, $contacts, $added): void {
        fill($conn, $p.'leads', $contacts, [
            'id' => 'seq', 'is_published' => '1', 'points' => 'seq % 50', 'date_added' => $added,
            'date_modified' => 'IF(seq % 10 = 0, '.$added.' + INTERVAL (1 + seq % 20) DAY, '.$added.')',
            'email' => "CONCAT('perf', seq, '@example.com')", 'firstname' => "CONCAT('First', seq)", 'lastname' => "CONCAT('Last', seq)",
            'company' => "CONCAT('Company ', seq % 1000)", 'city' => "ELT(1 + seq % 4, 'Pune', 'Mumbai', 'Delhi', 'Ahmedabad')",
            'country' => "'India'", 'owner_id' => 'IF(seq % 5 = 0, NULL, 1 + seq % 10)',
        ]);
    });
    timed('audit: create rows (one per contact)', function () use ($conn, $p, $contacts, $added): void {
        fill($conn, $p.'audit_log', $contacts, [
            'id' => 'seq', 'user_id' => '0', 'user_name' => "'System'", 'bundle' => "'lead'", 'object' => "'lead'", 'object_id' => 'seq',
            'action' => "'create'", 'date_added' => $added, 'ip_address' => "''",
            'details' => "CONCAT('a:1:{s:5:\"email\";a:2:{i:0;N;i:1;s:', LENGTH(CONCAT('perf', seq, '@example.com')), ':\"perf', seq, '@example.com\";}}')",
        ]);
    });
    timed('audit: update rows (last 30 days)', function () use ($conn, $p, $contacts, $updates): void {
        fill($conn, $p.'audit_log', $updates, [
            'id' => '('.$contacts.' + seq)', 'user_id' => '1', 'user_name' => "'Admin User'", 'bundle' => "'lead'", 'object' => "'lead'",
            'object_id' => '(1 + ((seq * 7919) % '.$contacts.'))', 'action' => "'update'",
            'date_added' => "(TIMESTAMP('2026-09-10 00:00:00') + INTERVAL FLOOR(seq * ".(30 * 86400).' / '.$updates.') SECOND)', 'ip_address' => "''",
            'details' => "'a:2:{s:4:\"city\";a:2:{i:0;s:4:\"Pune\";i:1;s:6:\"Mumbai\";}s:6:\"fields\";a:1:{s:4:\"city\";a:2:{i:0;s:4:\"Pune\";i:1;s:6:\"Mumbai\";}}}'",
        ]);
    });
    timed('audit: other rows (noise)', function () use ($conn, $p, $contacts, $updates, $noise): void {
        fill($conn, $p.'audit_log', $noise, [
            'id' => '('.($contacts + $updates).' + seq)', 'user_id' => '0', 'user_name' => "'System'", 'bundle' => "IF(seq % 2 = 0, 'lead', 'email')",
            'object' => "IF(seq % 2 = 0, 'lead', 'email')", 'object_id' => '(1 + seq % '.$contacts.')', 'action' => "IF(seq % 2 = 0, 'identified', 'update')",
            'date_added' => "(TIMESTAMP('2026-09-10 00:00:00') + INTERVAL FLOOR(seq * ".(30 * 86400).' / '.$noise.') SECOND)', 'ip_address' => "''", 'details' => "'a:0:{}'",
        ]);
    });
    timed('users + ANALYZE', function () use ($conn, $p, $tables): void {
        fill($conn, $p.'users', 10, ['id' => 'seq', 'first_name' => "CONCAT('Owner', seq)", 'last_name' => "'User'", 'username' => "CONCAT('owner', seq)", 'email' => "CONCAT('owner', seq, '@x.test')", 'password' => "'x'", 'is_published' => '1', 'role_id' => '1']);
        foreach ($tables as $table) {
            $conn->executeQuery('ANALYZE TABLE `'.$p.$table.'`')->fetchAllAssociative();
        }
    });
    line();
}

$conn    = DriverManager::getConnection($config + ['dbname' => $target]);
$catalog = new ContactFieldCatalog($conn, $p);
$factory = new ContactChangeCriteriaFactory($catalog);
$of      = static fn (array $query) => $factory->fromRequest(Request::create('/', 'GET', $query));

// ---- EXPLAIN of the page query (1 day, all) --------------------------------------------------
line('EXPLAIN (1 day, changeType=all)');
$reflection = new ReflectionMethod(ContactChangeRepository::class, 'signals');
[$signals, $params] = $reflection->invoke(new ContactChangeRepository($conn, $p), $of(['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-01']));
foreach ($conn->fetchAllAssociative('EXPLAIN '.$signals, $params) as $row) {
    line(sprintf('  %-8s %-14s type=%-6s key=%-32s rows=%s', $row['select_type'], $row['table'], $row['type'], $row['key'] ?? 'NULL', $row['rows']));
}
line();

$scenarios = [
    'A  1 day, all (100 per page)'                     => ['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-01'],
    'B  1 day, all, with history'                      => ['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-01', 'includeHistory' => 'true'],
    'C  1 day, created only'                           => ['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-01', 'changeType' => 'created'],
    'D  1 day, updated only'                           => ['dateFrom' => '2026-10-01', 'dateTo' => '2026-10-01', 'changeType' => 'updated'],
    'E  7 days, all'                                   => ['dateFrom' => '2026-09-25', 'dateTo' => '2026-10-01'],
    'F  30 days, all'                                  => ['dateFrom' => '2026-09-10', 'dateTo' => '2026-10-09'],
    'G  30 days, all, 500 per page, page 20'           => ['dateFrom' => '2026-09-10', 'dateTo' => '2026-10-09', 'limit' => '500', 'page' => '20'],
    'H  1 year, created only'                          => ['dateFrom' => '2025-10-10', 'dateTo' => '2026-10-09', 'changeType' => 'created'],
    'I  30 days, one contact, with history'            => ['dateFrom' => '2026-09-10', 'dateTo' => '2026-10-09', 'contactId' => '424242', 'includeHistory' => 'true'],
    'J  7 days, search company prefix'                 => ['dateFrom' => '2026-09-25', 'dateTo' => '2026-10-01', 'search' => 'Company 42'],
    'K  7 days, 3 fields only'                         => ['dateFrom' => '2026-09-25', 'dateTo' => '2026-10-01', 'fields' => 'email,city,points'],
];

line(sprintf('%-46s %20s %8s %8s %8s %9s %10s %9s', 'Scenario', 'runs ms', 'db ms', 'queries', 'peak MB', 'JSON KB', 'returned', 'total'));
foreach ($scenarios as $name => $query) {
    $criteria = $of($query);
    $times    = [];
    $result   = [];
    $peak     = 0.0;
    foreach ([1, 2, 3] as $run) {
        $builder = new ContactChangeReportBuilder(new ContactChangeRepository($conn, $p), new ContactFieldCatalog($conn, $p));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before  = memory_get_usage();
        $start   = microtime(true);
        $result  = $builder->build($criteria);
        $json    = json_encode($result, JSON_THROW_ON_ERROR);
        $times[] = (microtime(true) - $start) * 1000;
        $peak    = max($peak, (memory_get_peak_usage() - $before) / 1048576);
    }
    line(sprintf('%-46s %20s %8d %8d %8.1f %9.0f %10d %9d', $name, implode('/', array_map(static fn (float $t): string => (string) round($t), $times)),
        $result['meta']['dbTimeMs'], $result['meta']['queryCount'] + 2, $peak, strlen($json) / 1024, count($result['data']), $result['pagination']['totalContacts']));
}
line();
line('queries = report queries + 2 field catalog queries (lead_fields and the leads columns, loaded once per request).');

if (!isset($options['keep'])) {
    $server->executeStatement('DROP DATABASE `'.$target.'`');
    line('Dropped '.$target.' (use --keep to keep it).');
}
