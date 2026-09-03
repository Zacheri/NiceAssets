<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\Setting;
use RuntimeException;

final class LlmServer
{
    public static function modelsDir(): string
    {
        $dir = (string) Setting::get('llm.models_dir', BASE_PATH . '/storage/models');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function models(): array
    {
        $out = [];
        foreach (glob(self::modelsDir() . '/*.gguf') ?: [] as $file) {
            $out[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'mtime' => (int) filemtime($file),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function port(): int
    {
        return max(1, (int) Setting::get('llm.port', 8082));
    }

    public static function context(): int
    {
        return max(512, (int) Setting::get('llm.context', 8192));
    }

    public static function selectedModel(): ?string
    {
        $sel = (string) Setting::get('llm.selected_model', '');
        if ($sel !== '' && is_file(self::modelsDir() . '/' . basename($sel))) {
            return basename($sel);
        }
        $models = self::models();
        return $models[0]['name'] ?? null;
    }

    public static function setSelectedModel(string $name): void
    {
        Setting::set('llm.selected_model', basename($name));
    }

    public static function binary(): ?string
    {
        $out = trim((string) shell_exec('command -v llama-server 2>/dev/null'));
        return $out !== '' ? $out : null;
    }

    private static function pidFile(): string
    {
        return BASE_PATH . '/storage/run/llama.pid';
    }

    private static function pidAlive(int $pid): bool
    {
        return (int) shell_exec('kill -0 ' . $pid . ' 2>/dev/null; echo $?') === 0;
    }

    private static function httpGet(string $url, int $timeout = 1): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw === false ? null : $raw;
    }

    public static function state(): array
    {
        $selected = self::selectedModel();
        $pid = null;
        $pidf = self::pidFile();
        if (is_file($pidf)) {
            $p = (int) trim((string) file_get_contents($pidf));
            if ($p > 0 && self::pidAlive($p)) {
                $pid = $p;
            } else {
                @unlink($pidf);
            }
        }

        $loaded = null;
        $raw = self::httpGet('http://127.0.0.1:' . self::port() . '/v1/models', 1);
        if ($raw !== null) {
            $data = json_decode($raw, true);
            $loaded = $data['data'][0]['id'] ?? null;
        }

        $status = $loaded !== null ? 'ready' : ($pid !== null ? 'loading' : 'stopped');

        return [
            'status' => $status,
            'model' => $loaded !== null ? basename((string) $loaded) : null,
            'selected' => $selected,
            'matches_selected' => $status === 'ready' && $selected !== null && self::modelMatches($loaded, $selected),
            'pid' => $pid,
            'binary' => self::binary(),
            'models' => self::models(),
        ];
    }

    private static function modelMatches(?string $loaded, string $selected): bool
    {
        $loaded = (string) $loaded;
        return $loaded === $selected
            || str_ends_with($loaded, '/' . $selected)
            || str_ends_with($loaded, '/' . basename($selected));
    }

    public static function start(): void
    {
        $bin = self::binary();
        if ($bin === null) {
            throw new RuntimeException('llama-server binary not found.');
        }
        $model = self::selectedModel();
        if ($model === null) {
            throw new RuntimeException('No .gguf model found in ' . self::modelsDir() . '. Drop a model file there first.');
        }
        $path = self::modelsDir() . '/' . $model;

        $st = self::state();
        if ($st['status'] === 'ready' && $st['matches_selected']) {
            return;
        }
        self::stop();

        if (!is_dir(dirname(self::pidFile()))) {
            @mkdir(dirname(self::pidFile()), 0775, true);
        }
        $log = BASE_PATH . '/storage/logs/llama.log';
        $cmd = sprintf(
            'nohup %s --host 127.0.0.1 --port %d -m %s -ngl 99 -c %d -np 1 --alias %s >> %s 2>&1 & echo $!',
            escapeshellarg($bin),
            self::port(),
            escapeshellarg($path),
            self::context(),
            escapeshellarg($model),
            escapeshellarg($log)
        );
        $pid = (int) trim((string) shell_exec($cmd));
        if ($pid <= 0) {
            throw new RuntimeException('Failed to launch llama-server. Check storage/logs/llama.log.');
        }
        file_put_contents(self::pidFile(), (string) $pid);
        Logger::info('llama-server started', ['pid' => $pid, 'model' => $model]);
    }

    public static function stop(): void
    {
        $st = self::state();
        if ($st['pid'] !== null) {
            shell_exec('kill ' . (int) $st['pid'] . ' 2>/dev/null');
        }
        for ($i = 0; $i < 20; $i++) {
            if (self::state()['status'] === 'stopped') {
                break;
            }
            usleep(500000);
        }
        @unlink(self::pidFile());
    }

    public static function ensureRunning(): void
    {
        $st = self::state();
        if ($st['status'] === 'ready' && $st['matches_selected']) {
            return;
        }
        self::start();
        $deadline = time() + 90;
        while (time() < $deadline) {
            $st = self::state();
            if ($st['status'] === 'ready' && $st['matches_selected']) {
                return;
            }
            if ($st['status'] === 'stopped') {
                throw new RuntimeException('llama-server exited during load. Check storage/logs/llama.log.');
            }
            sleep(2);
        }
        throw new RuntimeException('Model is still loading. Try again in a moment or choose a smaller model.');
    }
}
