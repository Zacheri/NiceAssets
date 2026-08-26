<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Audit;
use App\Services\Assistant\Tools;
use App\Services\LlmClient;
use App\Services\LlmServer;
use RuntimeException;

class AssistantController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::output(View::render('assistant/index', [
            'title' => 'Assistant',
        ]));
    }

    public function state(): void
    {
        Auth::requireLogin();
        Response::json(LlmServer::state());
    }

    public function chat(): void
    {
        $user = Auth::requireLogin();
        $message = trim((string) Request::post('message', ''));
        if ($message === '') {
            Response::json(['error' => 'Empty message.'], 400);
        }
        unset($_SESSION['assistant_plan']);

        $st = LlmServer::state();
        if ($st['binary'] === null) {
            Response::json(['error' => 'llama.cpp is not installed. Install it from Admin → System (AI Assistant card).', 'code' => 'no_binary']);
        }
        if ($st['selected'] === null) {
            Response::json(['error' => 'No model available. Drop a .gguf file into the models folder (Admin → System).', 'code' => 'no_model']);
        }
        try {
            LlmServer::ensureRunning();
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage(), 'code' => 'model_not_ready']);
        }

        $history = $_SESSION['llm_history'] ?? [];
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($user)]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $plan = [];
        $handler = static function (string $name, array $args) use ($user, &$plan): array {
            $result = Tools::execute($name, $args, $user, false);
            if (!is_array($result)) {
                $result = ['message' => (string) $result];
            }
            if (Tools::isAction($name) && isset($result['preview'])) {
                $plan[] = [
                    'tool' => $result['op'],
                    'args' => $result['args'],
                    'preview' => $result['preview'],
                ];
            }
            return $result;
        };

        try {
            $res = LlmClient::chat($messages, Tools::definitions(), $handler);
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage(), 'code' => 'llm_error']);
        }

        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $res['text']];
        $_SESSION['llm_history'] = array_slice($history, -12);
        if ($plan !== []) {
            $_SESSION['assistant_plan'] = $plan;
        }
        Audit::log('assistant.query', 'assistant', (string) time(), [
            'role' => $user['role_name'] ?? '',
            'text' => $message,
            'plan_ops' => array_column($plan, 'tool'),
        ]);
        Response::json(['text' => $res['text'], 'plan' => $plan !== [] ? $plan : null, 'trace' => $res['trace']]);
    }

    public function confirm(): void
    {
        $user = Auth::requireLogin();
        $plan = $_SESSION['assistant_plan'] ?? null;
        if (!is_array($plan) || $plan === []) {
            Response::json(['error' => 'No pending plan to confirm.'], 400);
        }
        unset($_SESSION['assistant_plan']);
        $results = [];
        foreach ($plan as $op) {
            try {
                $r = Tools::execute((string) $op['tool'], (array) $op['args'], $user, true);
            } catch (\Throwable $e) {
                $r = ['error' => $e->getMessage()];
            }
            if (!is_array($r)) {
                $r = ['message' => (string) $r];
            }
            $results[] = ['op' => $op['tool'], 'preview' => $op['preview'] ?? '', 'result' => $r];
        }
        Audit::log('assistant.execute', 'assistant', (string) time(), ['results' => $results]);
        Response::json(['results' => $results]);
    }

    public function cancel(): void
    {
        Auth::requireLogin();
        unset($_SESSION['assistant_plan']);
        Response::json(['ok' => true]);
    }

    public function clear(): void
    {
        Auth::requireLogin();
        unset($_SESSION['llm_history'], $_SESSION['assistant_plan']);
        Response::json(['ok' => true]);
    }

    private function systemPrompt(array $user): string
    {
        $scope = '';
        if (($user['role_name'] ?? '') === 'department_manager') {
            $scope = ' You are a department manager: you can only see and act on assets in your own department.';
        }
        if (($user['role_name'] ?? '') === 'viewer') {
            $scope = ' You are a viewer: read-only. Never attempt action tools.';
        }
        return 'You are the assistant of ATR Inventory, a local asset-management app (assets have tags like 00001; '
            . 'statuses: available, checked_out, in_repair, broken, lost, disposed, sold, donated). '
            . 'Current user: ' . ($user['full_name'] ?? '') . ' (role: ' . ($user['role_name'] ?? '') . '). '
            . $scope . ' '
            . 'Use the provided tools for every fact you state; never invent asset tags, people, or numbers. '
            . 'Resolve names with find_person and assets with find_asset before acting. '
            . 'When a user says someone "returned", "gave back", or "handed in" equipment, resolve that person with find_person and use check_in_all_for_person. '
            . 'If a name is ambiguous, ask which one. Action tools only preview — the user confirms separately. '
            . 'If a tool returns an error, relay it plainly and suggest a fix. Be concise. Quote asset tags in backticks.';
    }
}
