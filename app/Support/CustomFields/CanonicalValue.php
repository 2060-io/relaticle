<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\Models\CustomField;

final readonly class CanonicalValue
{
    public static function of(CustomField $field, string $value): string
    {
        // The link normalizer recurses once per leading scheme, which is quadratic on a stack of them.
        // The field types validate at most one scheme, so no stored value can equal such input.
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*://){2}#i', $value) === 1) {
            return $value;
        }

        $type = CustomFieldsType::getFieldTypeInstance($field->type);

        return $type instanceof BaseFieldType ? $type->normalize($value, $field) : $value;
    }

    /**
     * @return list<string>
     */
    public static function spellings(CustomField $field, string $value): array
    {
        $value = trim($value);
        $type = CustomFieldsType::getFieldTypeInstance($field->type);
        $stored = $type instanceof BaseFieldType ? $type->setValue($value) : $value;

        return array_values(array_unique([self::of($field, $value), $stored, $value]));
    }

    /**
     * @return array<int, string>
     */
    public static function each(CustomField $field, mixed $value): array
    {
        return collect(is_iterable($value) ? $value : [$value])
            ->filter(fn (mixed $item): bool => filled($item))
            ->map(fn (mixed $item): string => self::of($field, (string) $item))
            ->reject(fn (string $item): bool => $item === '')
            ->unique(strict: true)
            ->values()
            ->all();
    }
}
