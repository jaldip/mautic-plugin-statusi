<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\ContactChanges;

use Doctrine\DBAL\Connection;

/**
 * Allowlist of contact attributes: Mautic's published contact fields (lead_fields, object = 'lead')
 * whose column exists on the leads table. Core and custom fields are both columns of leads.
 * Only these can be returned or requested with ?fields=.
 */
class ContactFieldCatalog
{
    /** @var array<string, array{alias: string, label: string, type: string, group: string}>|null */
    private ?array $fields = null;

    private string $prefix;

    public function __construct(private Connection $connection, ?string $tablePrefix = null)
    {
        $this->prefix = $tablePrefix ?? (defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '');
    }

    /**
     * @return array<string, array{alias: string, label: string, type: string, group: string}> alias => definition, in field order
     */
    public function all(): array
    {
        if (null !== $this->fields) {
            return $this->fields;
        }

        $columns = array_flip(array_map(
            'strtolower',
            $this->connection->fetchFirstColumn(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$this->prefix.'leads']
            )
        ));

        $this->fields = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT alias, label, type, field_group FROM '.$this->prefix.'lead_fields'
            .' WHERE object = \'lead\' AND is_published = 1 AND (column_is_not_created = 0 OR column_is_not_created IS NULL)'
            .' ORDER BY field_order, id'
        ) as $row) {
            $alias = (string) $row['alias'];
            // Aliases are used as column names: accept plain identifiers that really exist as columns.
            if (!preg_match('/^[a-z][a-z0-9_]*$/i', $alias) || !isset($columns[strtolower($alias)])) {
                continue;
            }
            $this->fields[$alias] = [
                'alias' => $alias,
                'label' => (string) $row['label'],
                'type'  => (string) $row['type'],
                'group' => (string) $row['field_group'],
            ];
        }

        return $this->fields;
    }

    /**
     * @return string[]
     */
    public function aliases(): array
    {
        return array_keys($this->all());
    }
}
