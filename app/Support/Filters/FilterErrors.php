<?php

declare(strict_types=1);

namespace App\Support\Filters;

use Illuminate\Validation\ValidationException;

final readonly class FilterErrors
{
    public static function at(string $path, string $message): ValidationException
    {
        return ValidationException::withMessages([$path => [$message]]);
    }

    public static function operand(string $name, string $operator, string $expected): ValidationException
    {
        return self::at($operator, __('validation.filter.operand_type', ['name' => "{$name} {$operator}", 'expected' => $expected]));
    }

    public static function sigil(string $path, string $operator): ValidationException
    {
        return self::at($path, __('validation.filter.operator_sigil', ['operator' => '$'.$operator]));
    }

    public static function prefix(ValidationException $exception, string|int $segment): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $messages[$key === '' ? (string) $segment : "{$segment}.{$key}"] = $errors;
        }

        return ValidationException::withMessages($messages);
    }
}
