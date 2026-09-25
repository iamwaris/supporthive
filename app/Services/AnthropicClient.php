<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Logger;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Thin wrapper over the Anthropic Messages API, called with raw cURL — the
 * app ships with an empty vendor/ at runtime (see CLAUDE.md's Stack table),
 * so no SDK package is available to require.
 *
 * The API key never appears in a log line. A transport failure logs only the
 * HTTP status/curl errno — there is no structured body to read. A 4xx/5xx
 * response additionally logs Anthropic's own error.type/error.message: those
 * describe what's wrong with the request (bad model, wrong key type, missing
 * header, ...), not secrets, and are what makes a configuration mistake
 * distinguishable in storage/logs from a real outage. The raw response body
 * is otherwise never logged, since outside that structured error field it
 * may echo request content back.
 */
final class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT_SECONDS = 30;
    private const MODEL = 'claude-sonnet-5';

    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * Builds a client for the currently active branch, decrypting its stored
     * Anthropic key. Throws when AI is disabled or no key has been saved —
     * both are configuration states, not errors worth a stack trace.
     */
    public static function forCurrentBranch(): self
    {
        $encrypted = Settings::string('ai_anthropic_api_key_encrypted', '');
        if ($encrypted === '' || Settings::get('ai_enabled', false) !== true) {
            throw new RuntimeException('AI is not configured for this branch.');
        }

        return new self(Crypto::decrypt($encrypted));
    }

    /**
     * Sends one Messages API request and returns the decoded JSON body.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param array<int,array<string,mixed>> $tools
     * @return array<string,mixed>
     */
    public function createMessage(array $messages, string $system = '', array $tools = [], int $maxTokens = 1024): array
    {
        $body = [
            'model' => self::MODEL,
            'max_tokens' => $maxTokens,
            'messages' => self::normalizeToolUseInputs($messages),
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        try {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('AI service could not process the request.', 0, $e);
        }

        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) {
            throw new RuntimeException('AI service is currently unavailable.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($response === false || $errno !== 0) {
            // Never log $this->apiKey or the curl error string — it can echo
            // back parts of the request URL or connection details.
            Logger::error('Anthropic API request failed', ['errno' => $errno]);
            throw new RuntimeException('AI service is currently unavailable.');
        }

        if ($status < 200 || $status >= 300) {
            // Never log the raw response body: it can echo back request
            // content. But for a genuine error status, Anthropic's own
            // error.message/type describe what's wrong with the REQUEST
            // (bad model, wrong key type, missing header, etc.) — never API
            // key material — and are exactly what's needed to tell a config
            // mistake apart from a real outage without reproducing the call
            // out of band. Decoding is best-effort: an unparsable body just
            // falls back to 'unknown' rather than throwing here. The
            // browser-facing exception below stays generic per rule 11; this
            // only reaches storage/logs.
            $errorDetail = null;
            try {
                /** @var array<string,mixed> $errorBody */
                $errorBody = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                $errorDetail = $errorBody['error'] ?? null;
            } catch (JsonException) {
                // Fall through with $errorDetail left null.
            }

            Logger::error('Anthropic API returned an error status', [
                'status' => $status,
                'type' => $errorDetail['type'] ?? 'unknown',
                'message' => $errorDetail['message'] ?? 'unknown',
            ]);
            throw new RuntimeException('AI service could not process the request.');
        }

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Logger::error('Anthropic API returned unparsable JSON', ['status' => $status]);
            throw new RuntimeException('AI service could not process the request.', 0, $e);
        }

        return $decoded;
    }

    /**
     * Guards against a PHP json_decode/json_encode asymmetry: a tool_use
     * block's `input` of `{}` comes back from this class's own
     * json_decode(..., true) as an empty PHP array, and a caller that
     * re-appends that assistant content into the next request's `messages`
     * (as any tool-use loop must) hands json_encode() an empty array back —
     * which serializes as `[]`, not `{}`, and Anthropic rejects it with
     * "Input should be an object". A non-empty input, associative or not,
     * already round-trips correctly and is left untouched; only the empty
     * case is ambiguous in PHP and needs the explicit stdClass marker so
     * json_encode() emits `{}`.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeToolUseInputs(array $messages): array
    {
        foreach ($messages as &$message) {
            if (!isset($message['content']) || !is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as &$block) {
                if (
                    is_array($block)
                    && ($block['type'] ?? null) === 'tool_use'
                    && isset($block['input'])
                    && is_array($block['input'])
                    && $block['input'] === []
                ) {
                    $block['input'] = new stdClass();
                }
            }
            unset($block);
        }
        unset($message);

        return $messages;
    }
}
