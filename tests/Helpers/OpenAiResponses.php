<?php

declare(strict_types=1);

namespace Tests\Helpers;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A faked OpenAI Responses API that answers the way the reasoning models do:
 * a request carrying a sampling parameter is rejected with the provider's 400.
 */
final class OpenAiResponses
{
    /** @param array<string, mixed> $structured */
    public static function fakeStructured(array $structured, string $model = 'gpt-5.6-luna'): void
    {
        Http::fake([
            'api.openai.com/*' => static fn (Request $request): PromiseInterface => array_key_exists('temperature', $request->data())
                ? Http::response(['error' => ['message' => "Unsupported parameter: 'temperature' is not supported with this model.", 'type' => 'invalid_request_error', 'param' => 'temperature', 'code' => 'unsupported_parameter']], 400)
                : Http::response([
                    'id' => 'resp_fake',
                    'model' => $model,
                    'status' => 'completed',
                    'output' => [[
                        'type' => 'message',
                        'status' => 'completed',
                        'content' => [['type' => 'output_text', 'text' => json_encode($structured, JSON_THROW_ON_ERROR), 'annotations' => []]],
                    ]],
                    'usage' => ['input_tokens' => 12, 'output_tokens' => 6],
                ]),
        ]);
    }

    /**
     * Reasoning tokens count against `max_output_tokens`, and the answer is cut off once the
     * budget runs out. The defaults are measured from gpt-5.6-luna titling a chat on 2026-10-20.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function fakeStructuredWithinBudget(array $structured, int $reasoningTokens = 46, float $charactersPerToken = 1.8): void
    {
        Http::fake([
            'api.openai.com/*' => static function (Request $request) use ($structured, $reasoningTokens, $charactersPerToken): PromiseInterface {
                $text = json_encode($structured, JSON_THROW_ON_ERROR);
                $budget = (int) ($request->data()['max_output_tokens'] ?? PHP_INT_MAX);
                $visibleCharacters = (int) floor(max(0, $budget - $reasoningTokens) * $charactersPerToken);
                $isTruncated = mb_strlen($text) > $visibleCharacters;

                return Http::response([
                    'id' => 'resp_fake',
                    'model' => 'gpt-5.6-luna',
                    'status' => $isTruncated ? 'incomplete' : 'completed',
                    'incomplete_details' => $isTruncated ? ['reason' => 'max_output_tokens'] : null,
                    'output' => [[
                        'type' => 'message',
                        'status' => $isTruncated ? 'incomplete' : 'completed',
                        'content' => [['type' => 'output_text', 'text' => $isTruncated ? mb_substr($text, 0, $visibleCharacters) : $text, 'annotations' => []]],
                    ]],
                    'usage' => ['input_tokens' => 533, 'output_tokens' => min($budget, $reasoningTokens + 20), 'output_tokens_details' => ['reasoning_tokens' => $reasoningTokens]],
                ]);
            },
        ]);
    }
}
