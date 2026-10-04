<?php

namespace App\Services\Masters;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Paging;
use App\Services\Audit\AuditService;
use App\Services\Support\Clock;
use App\Services\Support\TransactionRunner;
use App\Core\Database;

/**
 * Generic CRUD for the simple master tables (see MasterDefinitions):
 * immutable codes, retire/re-activate instead of delete, every change audited.
 */
class MasterDataService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Clock $clock,
        private readonly TransactionRunner $tx,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $slug): array
    {
        return MasterDefinitions::all()[$slug] ?? throw new NotFoundException('Unknown master data type.');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return MasterDefinitions::all();
    }

    /**
     * @return array<string, int> slug => active row count
     */
    public function counts(): array
    {
        $out = [];
        foreach (MasterDefinitions::all() as $slug => $def) {
            $out[$slug] = $this->db->table($def['table'])->where('is_active', 1)->countAllResults();
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $slug, string $q, string $status, Paging $paging): array
    {
        $def     = $this->definition($slug);
        $builder = $this->db->table($def['table']);

        if ($q !== '') {
            $builder->groupStart();
            foreach ($def['list'] as $i => $column) {
                if (($def['fields'][$column]['type'] ?? 'text') === 'text') {
                    $i === 0 ? $builder->like($column, $q) : $builder->orLike($column, $q);
                }
            }
            $builder->groupEnd();
        }
        if ($status === 'active') {
            $builder->where('is_active', 1);
        } elseif ($status === 'retired') {
            $builder->where('is_active', 0);
        }

        $paging->total = $builder->countAllResults(false);

        return $builder->orderBy($def['order'])->get($paging->perPage, $paging->offset())->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $slug, int $id): array
    {
        $def = $this->definition($slug);

        return $this->db->table($def['table'])->where('id', $id)->get(1)->getRowArray()
            ?? throw new NotFoundException(ucfirst($def['singular']) . ' not found.');
    }

    /**
     * Options for every select field of a definition: field => [value => label].
     *
     * @return array<string, array<string, string>>
     */
    public function selectOptions(string $slug): array
    {
        $out = [];
        foreach ($this->definition($slug)['fields'] as $name => $field) {
            if (($field['type'] ?? '') !== 'select') {
                continue;
            }
            $out[$name] = isset($field['lookup']) ? $this->lookup($field['lookup']) : $field['options'];
        }

        return $out;
    }

    /**
     * Active rows of a lookup master as id => label.
     *
     * @return array<string, string>
     */
    public function lookup(string $lookup): array
    {
        [$table, $label, $order] = MasterDefinitions::lookups()[$lookup];
        $rows = $this->db->table($table)->select("id, {$label} AS label", false)->where('is_active', 1)->orderBy($order)->get()->getResultArray();

        return array_column($rows, 'label', 'id');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     */
    public function create(string $slug, array $input, array $actor): int
    {
        $def    = $this->definition($slug);
        $values = $this->validate($def, $input, null);
        $now    = $this->clock->nowUtcString();

        return $this->tx->run(function (Database $db) use ($def, $values, $now, $actor): int {
            $db->table($def['table'])->insert($values + ['is_active' => 1, 'created_at' => $now, 'created_by' => $actor['id'], 'updated_at' => $now, 'updated_by' => $actor['id']]);
            $id = (int) $db->insertID();
            $this->audit->log('CREATE', $def['table'], $id, null, $values, (string) $values[$def['code']]);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     */
    public function update(string $slug, int $id, array $input, array $actor): void
    {
        $def    = $this->definition($slug);
        $before = $this->find($slug, $id);
        $values = $this->validate($def, $input, $before);
        unset($values[$def['code']]); // codes are immutable

        $this->tx->run(function (Database $db) use ($def, $id, $before, $values, $actor): void {
            $db->table($def['table'])->where('id', $id)->update($values + ['updated_at' => $this->clock->nowUtcString(), 'updated_by' => $actor['id']]);
            $this->audit->log('UPDATE', $def['table'], $id, array_intersect_key($before, $values), $values, (string) $before[$def['code']]);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function toggle(string $slug, int $id, array $actor): bool
    {
        $def    = $this->definition($slug);
        $before = $this->find($slug, $id);
        $active = (int) $before['is_active'] === 1 ? 0 : 1;

        $this->tx->run(function (Database $db) use ($def, $id, $before, $active, $actor): void {
            $db->table($def['table'])->where('id', $id)->update(['is_active' => $active, 'updated_at' => $this->clock->nowUtcString(), 'updated_by' => $actor['id']]);
            $this->audit->log($active === 1 ? 'ACTIVATE' : 'RETIRE', $def['table'], $id, ['is_active' => (int) $before['is_active']], ['is_active' => $active], (string) $before[$def['code']]);
        });

        return $active === 1;
    }

    /**
     * @param array<string, mixed>      $def
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function validate(array $def, array $input, ?array $existing): array
    {
        $data  = [];
        $rules = [];
        foreach ($def['fields'] as $name => $field) {
            if ($existing !== null && $name === $def['code']) {
                continue;
            }
            $type = $field['type'] ?? 'text';
            $raw  = $input[$name] ?? null;
            $data[$name] = match ($type) {
                'bool'  => $raw === '1' || $raw === 1 || $raw === true ? '1' : '0',
                default => is_string($raw) ? trim($raw) : '',
            };
            if (! empty($field['upper'])) {
                $data[$name] = strtoupper($data[$name]);
            }
            $rules[$name] = ['label' => $field['label'], 'rules' => $field['rules']];
        }

        $validation = service('validation', null, false);
        $validation->setRules($rules);
        if (! $validation->run($data)) {
            throw new ValidationException($validation->getErrors());
        }

        if ($existing === null) {
            $code = $data[$def['code']];
            if ($this->db->table($def['table'])->where($def['code'], $code)->countAllResults() > 0) {
                throw ValidationException::single($def['code'], 'This code already exists.');
            }
        }

        foreach ($def['fields'] as $name => $field) {
            if (! array_key_exists($name, $data)) {
                continue;
            }
            $type = $field['type'] ?? 'text';
            if ($data[$name] === '' && ! in_array($type, ['bool'], true)) {
                $data[$name] = null;
            } elseif ($type === 'select' && isset($field['lookup'])) {
                [$table] = MasterDefinitions::lookups()[$field['lookup']];
                if ($this->db->table($table)->where('id', (int) $data[$name])->countAllResults() === 0) {
                    throw ValidationException::single($name, 'Choose a valid ' . strtolower($field['label']) . '.');
                }
                $data[$name] = (int) $data[$name];
            } elseif (in_array($type, ['number', 'bool'], true)) {
                $data[$name] = (int) $data[$name];
            } elseif ($type === 'time' && strlen((string) $data[$name]) === 5) {
                $data[$name] .= ':00';
            }
        }

        return $data;
    }
}
