<?php

declare(strict_types=1);

namespace MauticPlugin\MauticStatusiBundle\Service;

/**
 * Field mapping of an Ongage Contact Manager integration, as on Statusi's Integrated Lists page
 * (statusi_ui/src/pages/IntegratedList/IntegratedList.tsx):
 *
 *  - Every Statusi contact field ("table.column", from /integration/get_default_fields) is filled from an
 *    Ongage field title, or "None". Fixed fields (/integration/get-fixed-field-mappings) always use their
 *    fixed Ongage field.
 *  - The default mapping uses the fields of Ongage's default list; selected lists can have their own mapping.
 *  - Before anything is saved, fields are matched to the Ongage field with the same name (case-insensitive).
 *  - Sent as {"field_mapping": {...}, "<list id>": {"field_mapping": {...}}}. Statusi keeps the default mapping in
 *    the integration and each list's mapping on the list (field_mapping_override), without "None" fields and
 *    with lower-cased values.
 */
final class OngageFieldMapping
{
    public const NONE = 'None';

    /**
     * @param array{fields: string[], fixed: array<string, string>} $definition
     * @param array<int, array<string, mixed>>                      $lists      Ongage lists (id, name, default)
     * @param array<string, mixed>                                  $saved      saved fields_mapping
     * @param callable(int): string[]                               $fieldsFor  Ongage field titles of a list
     *
     * @return array<string, mixed>
     */
    public function view(array $definition, array $lists, array $saved, callable $fieldsFor): array
    {
        $fixed    = $definition['fixed'];
        $editable = array_values(array_filter($definition['fields'], static fn (string $key): bool => !isset($fixed[$key])));

        // A list's own mapping is stored on the list (field_mapping_override, from get-ongage-lists); older
        // integrations may still have it in fields_mapping under the list id.
        $listRows  = [];
        $listSaved = [];
        $default   = null;
        foreach ($lists as $list) {
            $id = (int) ($list['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $override = $list['field_mapping_override'] ?? null;
            if (!is_array($override) || !$override) {
                $override = $saved[(string) $id] ?? null;
                $override = is_array($override['field_mapping'] ?? null) ? $override['field_mapping'] : $override;
            }
            $row = ['id' => $id, 'name' => (string) ($list['name'] ?? $id), 'selected' => is_array($override) && [] !== $override];
            if ($row['selected']) {
                $listSaved[$id] = $override;
            }
            $listRows[] = $row;
            if (null === $default && !empty($list['default'])) {
                $default = $row;
            }
        }
        $default ??= $listRows[0] ?? null;

        // Statusi stores only the mapped fields: once a mapping is saved, a field missing from it means "None".
        $defaultTitles = null !== $default ? $fieldsFor($default['id']) : [];
        $defaultSaved  = is_array($saved['field_mapping'] ?? null) ? $saved['field_mapping'] : [];
        $defaultValues = [];
        foreach ($editable as $key) {
            $defaultValues[$key] = $defaultSaved ? $this->savedValue($defaultSaved[$key] ?? null, $defaultTitles) : $this->sameNamed($key, $defaultTitles);
        }

        $selected = [];
        foreach ($listRows as $row) {
            if (!$row['selected']) {
                continue;
            }
            $titles = $fieldsFor($row['id']);
            $values = [];
            foreach ($editable as $key) {
                $values[$key] = $this->savedValue($listSaved[$row['id']][$key] ?? null, $titles);
            }
            $selected[$row['id']] = ['name' => $row['name'], 'titles' => $titles, 'values' => $values];
        }

        return [
            'fields'   => array_map(fn (string $key): array => ['key' => $key, 'label' => $this->label($key)], $editable),
            'fixed'    => array_map(fn (string $key, string $value): array => ['key' => $key, 'label' => $this->label($key), 'value' => $value], array_keys($fixed), $fixed),
            'lists'    => $listRows,
            'default'  => ['list' => $default, 'titles' => $defaultTitles, 'values' => $defaultValues],
            'selected' => $selected,
            'previous' => implode(',', array_keys($selected)),
        ];
    }

    /**
     * The update_field_mapping body parts from the posted form.
     *
     * @param array{fields: string[], fixed: array<string, string>} $definition
     * @param array<string, mixed>                                  $posted     mapping[default|<list id>][<field>] = title
     * @param string[]                                              $lists      selected list ids
     * @param string[]                                              $previous   list ids that had a mapping before
     * @param array<string, string>                                 $names      list names by id
     *
     * @return array{fields_mapping: array<string, mixed>, list_names: array<string, string>, deleted_list_ids: string[]}
     */
    public function payload(array $definition, array $posted, array $lists, array $previous, array $names): array
    {
        $lists          = array_values(array_unique(array_filter($lists, 'ctype_digit')));
        $fieldsMapping  = ['field_mapping' => $this->mappingFor($definition, $posted['default'] ?? [])];
        $listNames      = [];
        foreach ($lists as $listId) {
            $fieldsMapping[$listId] = ['field_mapping' => $this->mappingFor($definition, $posted[$listId] ?? [])];
            $listNames[$listId]     = (string) ($names[$listId] ?? $listId);
        }

        return [
            'fields_mapping'   => $fieldsMapping,
            'list_names'       => $listNames,
            'deleted_list_ids' => array_values(array_diff(array_filter($previous, 'ctype_digit'), $lists)),
        ];
    }

    /**
     * @param array{fields: string[], fixed: array<string, string>} $definition
     *
     * @return array<string, string>
     */
    private function mappingFor(array $definition, mixed $posted): array
    {
        $posted  = is_array($posted) ? $posted : [];
        $mapping = [];
        foreach ($definition['fields'] as $key) {
            $value         = trim((string) ($posted[$key] ?? ''));
            $mapping[$key] = $definition['fixed'][$key] ?? ('' === $value ? self::NONE : $value);
        }

        return $mapping;
    }

    /**
     * A saved value (Statusi lower-cases them) as the matching Ongage field title; kept as is if the list no longer has it.
     *
     * @param string[] $titles
     */
    private function savedValue(mixed $saved, array $titles): string
    {
        $saved = is_scalar($saved) ? trim((string) $saved) : '';
        if ('' === $saved || 0 === strcasecmp($saved, self::NONE)) {
            return self::NONE;
        }
        foreach ($titles as $title) {
            if (0 === strcasecmp($title, $saved)) {
                return $title;
            }
        }

        return $saved;
    }

    /**
     * Without a saved mapping: the Ongage field with the Statusi field's own name, if any.
     *
     * @param string[] $titles
     */
    private function sameNamed(string $key, array $titles): string
    {
        $short = $this->label($key);
        foreach ($titles as $title) {
            if (0 === strcasecmp($title, $short)) {
                return $title;
            }
        }

        return self::NONE;
    }

    private function label(string $key): string
    {
        $position = strrpos($key, '.');

        return false === $position ? $key : substr($key, $position + 1);
    }
}
