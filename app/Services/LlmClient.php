<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use RuntimeException;

final class LlmClient
{
    public static function chat(array $messages, array $toolDefs, callable $toolHandler, int $maxRounds = 8, int $timeout = 300): array
    {
        $trace = [];
        $deadline = time() + $timeout * $maxRounds;
        for ($round = 0; $round < $maxRounds; $round++) {
            if (time() > $deadline) {
                throw new RuntimeException('The model took too long. Try a smaller model or rephrase.');
            }
            $body = [
                'model' => LlmServer::selectedModel(),
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => 1024,
            ];
            if ($toolDefs !== []) {
                $body['tools'] = $toolDefs;
                $body['tool_choice'] = 'auto';
            }
            $res = self::request('/v1/chat/completions', $body, $timeout);
            $msg = $res['choices'][0]['message'] ?? null;
            if (!is_array($msg)) {
                throw new RuntimeException('Empty model response.');
            }
            $calls = $msg['tool_calls'] ?? [];
            if ($calls === []) {
                return ['text' => (string) ($msg['content'] ?? ''), 'trace' => $trace];
            }
            $messages[] = $msg;
            foreach ($calls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }
                try {
                    $result = $toolHandler($name, $args);
                    if (!is_array($result)) {
                        $result = ['message' => (string) $result];
                    }
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                }
                $trace[] = ['tool' => $name, 'args' => $args, 'result' => $result];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'content' => json_encode($result, JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        return ['text' => 'I ran out of steps before finishing. Please rephrase the request.', 'trace' => $trace];
    }

    public static function request(string $path, array $body, int $timeout = 120): array
    {
        $ch = curl_init('http://' . LlmServer::host() . ':' . LlmServer::port() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer naims-local'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($raw === false) {
            Logger::error('llama-server request failed', ['error' => $err]);
            if (stripos($err, 'timed out') !== false) {
                throw new RuntimeException('The model took too long to reply. Try a smaller model or rephrase.');
            }
            throw new RuntimeException('Cannot reach the local model server. Start it from the System tab.');
        }
        $data = json_decode((string) $raw, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $detail = is_array($data)
                ? (string) ($data['error']['message'] ?? substr((string) $raw, 0, 200))
                : substr((string) $raw, 0, 200);
            throw new RuntimeException('Model server error ' . $code . ': ' . $detail);
        }
        return $data;
    }
}
