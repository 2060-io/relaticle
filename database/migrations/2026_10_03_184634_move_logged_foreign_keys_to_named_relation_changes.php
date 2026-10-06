<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const string EVENT = 'custom_field_changes';

    private const string EMPTY = '—';

    /** @var array<string, array<string, array{code: string, label: string, table: string}>> */
    private const array RELATIONS = [
        'people' => [
            'company_id' => ['code' => 'company', 'label' => 'Company', 'table' => 'companies'],
        ],
        'opportunity' => [
            'company_id' => ['code' => 'company', 'label' => 'Company', 'table' => 'companies'],
            'contact_id' => ['code' => 'contact', 'label' => 'Point of Contact', 'table' => 'people'],
        ],
    ];

    public function up(): void
    {
        DB::table('activity_log')
            ->whereIn('subject_type', array_keys(self::RELATIONS))
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('attribute_changes::text like ?', ['%"company_id"%'])
                ->orWhereRaw('attribute_changes::text like ?', ['%"contact_id"%']))
            ->eachById(fn (stdClass $row) => $this->convert($row));
    }

    private function convert(stdClass $row): void
    {
        $changes = json_decode((string) $row->attribute_changes, true);

        if (! is_array($changes)) {
            return;
        }

        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $relationChanges = [];

        foreach (self::RELATIONS[$row->subject_type] as $foreignKey => $relation) {
            if (! array_key_exists($foreignKey, $new) && ! array_key_exists($foreignKey, $old)) {
                continue;
            }

            $before = $old[$foreignKey] ?? null;
            $after = $new[$foreignKey] ?? null;
            unset($new[$foreignKey], $old[$foreignKey]);

            if ($row->event !== 'updated' || $before === $after) {
                continue;
            }

            $relationChanges[] = [
                'code' => $relation['code'],
                'label' => $relation['label'],
                'type' => 'relation',
                'old' => $this->side($relation['table'], $before),
                'new' => $this->side($relation['table'], $after),
            ];
        }

        $remaining = array_filter(['attributes' => $new, 'old' => $old], fn (array $side): bool => $side !== []);

        if ($relationChanges === []) {
            DB::table('activity_log')->where('id', $row->id)->update([
                'attribute_changes' => json_encode($remaining),
            ]);

            return;
        }

        $properties = json_decode((string) $row->properties, true);

        $relationRow = [
            'event' => self::EVENT,
            'description' => self::EVENT,
            'attribute_changes' => json_encode([]),
            'properties' => json_encode([...(is_array($properties) ? $properties : []), self::EVENT => $relationChanges]),
        ];

        if ($remaining === []) {
            DB::table('activity_log')->where('id', $row->id)->update($relationRow);

            return;
        }

        $batchUuid = $row->batch_uuid ?? (string) Str::uuid();

        DB::table('activity_log')->where('id', $row->id)->update([
            'attribute_changes' => json_encode($remaining),
            'batch_uuid' => $batchUuid,
        ]);

        DB::table('activity_log')->insert([
            ...$relationRow,
            'workspace_id' => $row->workspace_id,
            'log_name' => $row->log_name,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'causer_type' => $row->causer_type,
            'causer_id' => $row->causer_id,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'batch_uuid' => $batchUuid,
        ]);
    }

    /**
     * @return array{value: ?string, label: string}
     */
    private function side(string $table, mixed $id): array
    {
        if (! is_string($id) || $id === '') {
            return ['value' => null, 'label' => self::EMPTY];
        }

        $name = DB::table($table)->where('id', $id)->value('name');

        return ['value' => $id, 'label' => is_string($name) && $name !== '' ? $name : self::EMPTY];
    }
};
