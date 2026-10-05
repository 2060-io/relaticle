<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;

final class FilterDescription
{
    /**
     * @param  class-string  $tool
     */
    public static function of(string $tool): string
    {
        return resolve($tool)->schema(new JsonSchemaTypeFactory)['filter']->toArray()['description'];
    }

    /**
     * @return array{names: array<string, string>, rules: array<string, string>, types: array<string, string>, fields: array<string, string>}
     */
    public static function lines(string $description): array
    {
        $section = '';
        $lines = ['names' => [], 'rules' => [], 'types' => [], 'fields' => []];

        foreach (explode("\n", $description) as $line) {
            $section = match ($line) {
                'Field types:' => 'types',
                'Fields:' => 'fields',
                'Rules by type:' => 'rules',
                'Names for this entity type:' => 'names',
                default => $section,
            };

            if (str_starts_with($line, '- ')) {
                $lines[$section][str($line)->after('- ')->before(in_array($section, ['types', 'rules'], true) ? ':' : ' (')->toString()] = $line;
            }
        }

        return $lines;
    }
}
