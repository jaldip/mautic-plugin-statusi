<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ActivitySqlBuilder;
use MauticPlugin\MauticStatusiBundle\EmailActivity\EmailActivityReportBuilder;
use MauticPlugin\MauticStatusiBundle\EmailActivity\EmailActivityRepository;
use MauticPlugin\MauticStatusiBundle\EmailActivity\ReportCriteriaFactory;
use Symfony\Component\HttpFoundation\Request;

require __DIR__.'/../bootstrap.php';

$options = getopt('', ['sends::', 'keep', 'reuse']);
$sends   = (int) ($options['sends'] ?? 1000000);
$keep    = isset($options['keep']);
$reuse   = isset($options['reuse']);

$scale = [
    'emails'    => 200,
    'campaigns' => 20,
    'contacts'  => 100000,
    'sends'     => $sends,
    'opens'     => (int) ($sends * 0.6),
    'clicks'    => (int) ($sends * 0.2),
    'dnc'       => (int) ($sends * 0.02),
    'ips'       => 10000,
    'redirects' => 1000,
];
$perEmail = intdiv($scale['sends'], $scale['emails']); // recipients per email
$days     = 30;
$base     = '2026-09-10 00:00:00';

$config = $GLOBALS['STATUSI_TEST_DB_CONFIG'];
$source = $GLOBALS['STATUSI_TEST_SOURCE_DB'];
$target = $source.'_statusi_perf';
$p      = MAUTIC_TABLE_PREFIX;

$tables = [
    'email_stats', 'email_stats_devices', 'page_hits', 'page_redirects', 'lead_donotcontact',
    'campaign_lead_event_log', 'campaign_lead_event_failed_log', 'campaign_events', 'campaigns',
    'campaign_leads', 'emails', 'leads', 'ip_addresses', 'lead_devices',
];

$server = DriverManager::getConnection($config);
$db     = DriverManager::getConnection($config + ['dbname' => $source]);

function line(string $text = ''): void
{
    echo $text, PHP_EOL;
}

function timed(string $label, callable $fn): mixed
{
    $start  = microtime(true);
    $result = $fn();
    line(sprintf('  %-46s %8.1f s', $label, microtime(true) - $start));

    return $result;
}

/**
 * SQL literals for NOT NULL columns without a default, so INSERT ... SELECT only lists what matters.
 *
 * @param string[] $given
 *
 * @return array<string, string>
 */
function requiredDefaults(Connection $conn, string $table, array $given): array
{
    $rows = $conn->fetchAllAssociative(
        'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    );
    $defaults = [];
    foreach ($rows as $row) {
        if (in_array($row['COLUMN_NAME'], $given, true) || 'YES' === $row['IS_NULLABLE'] || null !== $row['COLUMN_DEFAULT'] || str_contains((string) $row['EXTRA'], 'auto_increment')) {
            continue;
        }
        $type                          = strtolower((string) $row['DATA_TYPE']);
        $defaults[$row['COLUMN_NAME']] = match (true) {
            str_contains($type, 'int'), str_contains($type, 'decimal'), str_contains($type, 'double') => '0',
            str_contains($type, 'datetime'), str_contains($type, 'timestamp') => "'2026-01-01 00:00:00'",
            'date' === $type => "'2026-01-01'",
            str_contains($type, 'text') => "'[]'",
            default => "''",
        };
    }

    return $defaults;
}

/**
 * @param array<string, string> $columns column => SQL expression over the sequence value "seq"
 */
function fill(Connection $conn, string $table, int $rows, array $columns, ?string $from = null): void
{
    $columns += requiredDefaults($conn, $table, array_keys($columns));
    $from ??= 'seq_1_to_'.$rows;
    $conn->executeStatement(
        'INSERT INTO `'.$table.'` (`'.implode('`, `', array_keys($columns)).'`) SELECT '.implode(', ', $columns).' FROM '.$from
    );
}

line('Email activity report benchmark');
line(sprintf('Database: %s (MariaDB %s)', $target, $db->fetchOne('SELECT VERSION()')));
line(sprintf('Data: %d emails, %d campaigns, %d contacts, %d sends, %d opens, %d clicks, %d DNC over %d days',
    $scale['emails'], $scale['campaigns'], $scale['contacts'], $scale['sends'], $scale['opens'], $scale['clicks'], $scale['dnc'], $days));
line();

$exists = (bool) $server->fetchOne('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$target]);
if (!$reuse || !$exists) {
    line('Generating data');
    $server->executeStatement('DROP DATABASE IF EXISTS `'.$target.'`');
    $server->executeStatement('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($tables as $table) {
        $server->executeStatement('CREATE TABLE `'.$target.'`.`'.$p.$table.'` LIKE `'.$source.'`.`'.$p.$table.'`');
    }
    $conn = DriverManager::getConnection($config + ['dbname' => $target]);

    // Expressions for send number "s": email blocks of $perEmail recipients, sent over 30 days.
    $statEmail = fn (string $s): string => '(1 + (FLOOR(('.$s.' - 1) / '.$perEmail.') % '.$scale['emails'].'))';
    $statLead  = fn (string $s): string => '(1 + ('.$s.' % '.$scale['contacts'].'))';
    $statDate  = fn (string $s): string => "(TIMESTAMP('".$base."') + INTERVAL FLOOR(".$s.' * '.($days * 86400).' / '.$scale['sends'].') SECOND)';

    timed('emails, campaigns, steps, redirects, ips', function () use ($conn, $p, $scale): void {
        fill($conn, $p.'emails', $scale['emails'], [
            'id' => 'seq', 'name' => "CONCAT('Email ', seq)", 'subject' => "CONCAT('Subject ', seq)", 'email_type' => "'template'",
            'is_published' => '1', 'date_added' => "'2026-06-01 00:00:00'",
        ]);
        fill($conn, $p.'campaigns', $scale['campaigns'], [
            'id' => 'seq', 'name' => "CONCAT('Campaign ', seq)", 'is_published' => '1', 'date_added' => "'2026-06-01 00:00:00'",
        ]);
        fill($conn, $p.'campaign_events', $scale['emails'], [
            'id' => 'seq', 'campaign_id' => '(1 + (seq % '.$scale['campaigns'].'))', 'name' => "CONCAT('Send email ', seq)",
            'type' => "'email.send'", 'event_type' => "'action'", 'event_order' => '1', 'properties' => "'[]'",
            'trigger_mode' => "'immediate'", 'channel' => "'email'", 'channel_id' => 'seq',
        ]);
        fill($conn, $p.'page_redirects', $scale['redirects'], [
            'id' => 'seq', 'redirect_id' => "CONCAT('r', seq)", 'url' => "CONCAT('https://example.com/p', seq)",
            'hits' => '0', 'unique_hits' => '0', 'is_published' => '1',
        ]);
        fill($conn, $p.'ip_addresses', $scale['ips'], [
            'id' => 'seq', 'ip_address' => "CONCAT('10.0.', FLOOR(seq / 250), '.', seq % 250)",
            'ip_details' => "CONCAT('a:4:{s:4:\"city\";s:4:\"City\";s:6:\"region\";s:2:\"RG\";s:7:\"country\";s:5:\"India\";s:3:\"isp\";s:3:\"ISP\";}')",
        ]);
    });
    timed('contacts + devices', function () use ($conn, $p, $scale): void {
        fill($conn, $p.'leads', $scale['contacts'], [
            'id' => 'seq', 'email' => "CONCAT('perf', seq, '@example.com')", 'firstname' => "CONCAT('First', seq)",
            'lastname' => "CONCAT('Last', seq)", 'country' => "ELT(1 + seq % 4, 'India', 'United States', 'United Kingdom', 'Germany')",
            'city' => "'City'", 'date_added' => "'2026-01-01 00:00:00'",
        ]);
        fill($conn, $p.'lead_devices', $scale['contacts'], [
            'id' => 'seq', 'lead_id' => 'seq', 'date_added' => "'2026-01-01 00:00:00'", 'device' => "ELT(1 + seq % 2, 'desktop', 'smartphone')",
            'device_os_name' => "ELT(1 + seq % 3, 'Windows', 'Android', 'iOS')",
            'client_info' => "'a:3:{s:4:\"type\";s:7:\"browser\";s:4:\"name\";s:6:\"Chrome\";s:7:\"version\";s:5:\"129.0\";}'",
        ]);
    });
    timed('sends (email_stats)', function () use ($conn, $p, $scale, $statEmail, $statLead, $statDate): void {
        fill($conn, $p.'email_stats', $scale['sends'], [
            'id' => 'seq', 'email_id' => $statEmail('seq'), 'lead_id' => $statLead('seq'), 'email_address' => "CONCAT('perf', ".$statLead('seq').", '@example.com')",
            'date_sent' => $statDate('seq'), 'is_failed' => 'IF(seq % 100 = 0, 1, 0)', 'is_read' => '0', 'viewed_in_browser' => '0',
            'tracking_hash' => "CONCAT('h', seq)", 'retry_count' => '0', 'open_count' => '0',
            'source' => "IF(seq % 10 < 7, 'campaign.event', NULL)", 'source_id' => 'IF(seq % 10 < 7, '.$statEmail('seq').', NULL)',
        ]);
    });
    timed('campaign logs, failures, memberships', function () use ($conn, $p, $scale): void {
        $conn->executeStatement(
            'INSERT INTO `'.$p.'campaign_lead_event_log` (id, event_id, lead_id, campaign_id, rotation, date_triggered, is_scheduled, system_triggered, channel, channel_id, non_action_path_taken)'
            .' SELECT s.id, s.source_id, s.lead_id, 1 + (s.source_id % '.$scale['campaigns'].'), 1, s.date_sent - INTERVAL 2 SECOND, 0, 1, \'email\', s.email_id, 0'
            .' FROM `'.$p.'email_stats` s WHERE s.source = \'campaign.event\''
        );
        $conn->executeStatement(
            'INSERT INTO `'.$p.'campaign_lead_event_failed_log` (log_id, date_added, reason)'
            .' SELECT l.id, l.date_triggered, \'Email address is invalid\' FROM `'.$p.'campaign_lead_event_log` l WHERE l.id % 100 = 0'
        );
        $conn->executeStatement(
            'INSERT IGNORE INTO `'.$p.'campaign_leads` (campaign_id, lead_id, date_added, manually_removed, manually_added, rotation)'
            .' SELECT l.campaign_id, l.lead_id, MIN(l.date_triggered), 0, 0, 1 FROM `'.$p.'campaign_lead_event_log` l GROUP BY l.campaign_id, l.lead_id'
        );
    });
    timed('opens (email_stats_devices)', function () use ($conn, $p, $scale, $statDate): void {
        $stat = '(1 + ((seq * 13) % '.intdiv($scale['sends'], 2).'))';
        fill($conn, $p.'email_stats_devices', $scale['opens'], [
            'id' => 'seq', 'stat_id' => $stat, 'date_opened' => $statDate($stat).' + INTERVAL (60 + seq % 7200) SECOND',
            'ip_id' => '(1 + seq % '.$scale['ips'].')', 'device_id' => '(1 + ('.$stat.' % '.$scale['contacts'].'))',
        ]);
    });
    timed('clicks (page_hits)', function () use ($conn, $p, $scale, $statEmail, $statLead, $statDate): void {
        $stat = '(1 + ((seq * 17) % '.intdiv($scale['sends'], 2).'))';
        fill($conn, $p.'page_hits', $scale['clicks'], [
            'id' => 'seq', 'email_id' => $statEmail($stat), 'lead_id' => $statLead($stat), 'date_hit' => $statDate($stat).' + INTERVAL (120 + seq % 7200) SECOND',
            'url' => "CONCAT('https://example.com/p', 1 + seq % 5)", 'redirect_id' => '(1 + seq % '.$scale['redirects'].')', 'source' => "'email'",
            'source_id' => $statEmail($stat), 'code' => '200', 'ip_id' => '(1 + seq % '.$scale['ips'].')', 'device_id' => $statLead($stat),
            'country' => "'India'", 'user_agent' => "'Mozilla/5.0 Benchmark'",
            'query' => "CONCAT('a:1:{s:2:\"ct\";a:2:{s:4:\"stat\";s:', LENGTH(CONCAT('h', ".$stat.")), ':\"h', ".$stat.", '\";s:5:\"email\";i:', ".$statEmail($stat).", ';}}')",
        ]);
    });
    timed('do-not-contact', function () use ($conn, $p, $scale, $statEmail, $statLead, $statDate): void {
        $stat = '(1 + ((seq * 29) % '.$scale['sends'].'))';
        fill($conn, $p.'lead_donotcontact', $scale['dnc'], [
            'id' => 'seq', 'lead_id' => $statLead($stat), 'date_added' => $statDate($stat).' + INTERVAL 1 HOUR', 'reason' => '(1 + seq % 3)',
            'channel' => "'email'", 'channel_id' => $statEmail($stat), 'comments' => "IF(seq % 3 = 1, '550 5.1.1 User unknown', NULL)",
        ]);
    });
    timed('ANALYZE TABLE', function () use ($conn, $p, $tables): void {
        foreach ($tables as $table) {
            $conn->executeQuery('ANALYZE TABLE `'.$p.$table.'`')->fetchAllAssociative();
        }
    });
    line();
}

$conn       = DriverManager::getConnection($config + ['dbname' => $target]);
$sql        = new ActivitySqlBuilder($p);
$factory    = new ReportCriteriaFactory();
$criteriaOf = static fn (array $query) => $factory->fromRequest(Request::create('/', 'GET', $query));

// ---- EXPLAIN -------------------------------------------------------------------------------
line('EXPLAIN (one day, page 1)');
$day           = ['dateFrom' => '2026-10-01 00:00:00', 'dateTo' => '2026-10-01 23:59:59'];
[$union, $par] = $sql->build($criteriaOf($day));
$explain       = $conn->fetchAllAssociative(
    'EXPLAIN SELECT g.email_id, g.last_ts, g.total, COUNT(*) OVER () FROM (SELECT a.email_id, MAX(a.ts) AS last_ts, COUNT(*) AS total FROM ('.$union.') a GROUP BY a.email_id) g ORDER BY last_ts DESC, g.email_id DESC LIMIT 25',
    $par
);
foreach ($explain as $row) {
    line(sprintf('  %-8s %-34s key=%-40s rows=%-8s %s', $row['select_type'], $row['table'] ?? '', $row['key'] ?? 'NULL', $row['rows'] ?? '', $row['Extra'] ?? ''));
}
line();

// ---- scenarios -----------------------------------------------------------------------------
$scenarios = [
    'A  1 day, defaults (25 emails, 50 contacts, 100 activities)' => $day,
    'B  1 day, 100 emails per page'                               => $day + ['limit' => '100'],
    'C  1 day, summary only (includeContacts=false)'              => $day + ['includeContacts' => 'false'],
    'D  1 day, page 5'                                            => $day + ['page' => '5', 'limit' => '10'],
    'E  7 days, defaults'                                         => ['dateFrom' => '2026-09-25', 'dateTo' => '2026-10-01'],
    'F  30 days, defaults (whole dataset)'                        => ['dateFrom' => '2026-09-10', 'dateTo' => '2026-10-09'],
    'G  30 days, one email, 500 contacts per page'                => ['dateFrom' => '2026-09-10', 'dateTo' => '2026-10-09', 'emailId' => '100', 'contactLimit' => '500'],
    'H  30 days, one contact'                                     => ['dateFrom' => '2026-09-10', 'dateTo' => '2026-10-09', 'contactId' => '4242'],
    'I  7 days, one campaign'                                     => ['dateFrom' => '2026-09-25', 'dateTo' => '2026-10-01', 'campaignId' => '7'],
    'J  1 day, search by email name'                              => $day + ['search' => 'Email 13'],
];

line(sprintf('%-62s %8s %8s %8s %8s %9s %7s %8s %10s', 'Scenario', 'runs ms', 'db ms', 'queries', 'peak MB', 'JSON KB', 'emails', 'contacts', 'activities'));
foreach ($scenarios as $name => $query) {
    $criteria = $criteriaOf($query);
    $times    = [];
    $result   = null;
    $dbMs     = 0;
    $peak     = 0.0;
    foreach ([1, 2, 3] as $run) {
        $repository = new EmailActivityRepository($conn, $sql);
        $builder    = new EmailActivityReportBuilder($repository);
        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $before = memory_get_usage();
        $start  = microtime(true);
        $result = $builder->build($criteria);
        $json   = json_encode($result, JSON_THROW_ON_ERROR);
        $times[] = (microtime(true) - $start) * 1000;
        $peak    = max($peak, (memory_get_peak_usage() - $before) / 1048576);
        $dbMs    = $result['meta']['dbTimeMs'];
    }

    $contacts   = 0;
    $activities = 0;
    foreach ($result['data'] as $group) {
        $contacts += count($group['contacts'] ?? []);
        foreach ($group['contacts'] ?? [] as $contact) {
            $activities += count($contact['activities'] ?? []);
        }
    }
    line(sprintf(
        '%-62s %8s %8d %8d %8.1f %9.0f %7d %8d %10d',
        $name,
        implode('/', array_map(static fn (float $t): string => (string) round($t), $times)),
        $dbMs,
        $result['meta']['queryCount'],
        $peak,
        strlen($json) / 1024,
        count($result['data']),
        $contacts,
        $activities
    ));
}
line();
line('runs ms = wall time of three consecutive runs (report building + JSON encoding); db ms = last run.');

if (!$keep) {
    $server->executeStatement('DROP DATABASE `'.$target.'`');
    line('Dropped '.$target.' (use --keep to keep it).');
}
