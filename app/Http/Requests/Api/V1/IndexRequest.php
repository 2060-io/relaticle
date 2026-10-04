<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

final class IndexRequest extends FormRequest
{
    public const string FIRST_CURSOR = 'true';

    public const int MAX_BODY_KILOBYTES = 256;

    private const array OPTIONAL = ['per_page', 'cursor', 'page', 'include'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'include' => ['sometimes', 'string'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->isMethod('POST') && ! $this->hasJsonObjectBody()) {
                    $validator->errors()->add('body', __('validation.filter.body_not_object'));
                }
            },
            function (Validator $validator): void {
                if ($this->has('cursor') && ! $this->hasReadableCursor()) {
                    $validator->errors()->add('cursor', __('validation.filter.cursor'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        abort_if(
            $this->isMethod('POST') && strlen($this->getContent()) > self::MAX_BODY_KILOBYTES * 1024,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            __('validation.filter.body_too_large', ['max' => self::MAX_BODY_KILOBYTES]),
        );

        foreach (self::OPTIONAL as $key) {
            if ($this->input($key) === null || $this->input($key) === '') {
                $this->getInputSource()->remove($key);
            }
        }

        $include = $this->input('include');

        if (is_array($include) && $include !== [] && array_is_list($include) && array_all($include, static fn (mixed $name): bool => is_string($name))) {
            $this->merge(['include' => implode(',', $include)]);
        }

        if ($this->input('cursor') === true) {
            $this->merge(['cursor' => self::FIRST_CURSOR]);
        }
    }

    private function hasReadableCursor(): bool
    {
        $cursor = $this->input('cursor');

        return is_string($cursor) && ($cursor === self::FIRST_CURSOR || Cursor::fromEncoded($cursor) instanceof Cursor);
    }

    private function hasJsonObjectBody(): bool
    {
        $content = trim($this->getContent());

        if ($content === '') {
            return true;
        }

        if (! $this->isJson()) {
            return false;
        }

        // json() casts its decode to an array, so an empty result cannot tell {} from truncated json.
        return match ($content[0]) {
            '{' => $this->json()->count() > 0 || json_validate($content),
            '[' => $this->json()->count() === 0 && json_validate($content),
            default => false,
        };
    }
}
