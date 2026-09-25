<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\CsvImport;
use RuntimeException;

final class ImportController
{
    private function importsDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function index(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/import', [
            'title' => 'Import Assets',
            'step' => 'upload',
            'llmReady' => \App\Services\LlmServer::state()['status'] === 'ready',
        ]));
    }

    /** Validate + store the upload, parse, clean, optional LLM, render preview. */
    public function analyze(): void
    {
        $user = Auth::requireLogin();
        $path = $this->storeUpload();
        try {
            $parsed = CsvImport::parse($path);
        } catch (RuntimeException $e) {
            @unlink($path);
            $this->uploadError($e->getMessage());
            return;
        }
        // First pass (upload form) posts no mapping selects: start from the
        // known AssetTiger defaults. Re-analyze posts the edited selects.
        $mapping = Request::post('mapping') === null
            ? CsvImport::defaultMapping($parsed['header'])
            : $this->mappingFromPost($parsed['header']);
        $options = $this->optionsFromPost();
        $distinct = CsvImport::distinctValues($parsed['rows'], $mapping);
        $llm = CsvImport::llmClassify($distinct, !empty($options['ai_assist']));
        $llm = $this->applyLlmOverrides($llm, $distinct);
        $preview = CsvImport::preview($parsed['rows'], $mapping, $options, $llm);
        View::output(View::render('admin/import', [
            'title' => 'Import Assets',
            'step' => 'preview',
            'file' => basename($path),
            'path' => $path,
            'header' => $parsed['header'],
            'mapping' => $mapping,
            'targets' => CsvImport::TARGETS,
            'options' => $options,
            'llm' => $llm,
            'distinct' => $distinct,
            'preview' => $preview,
        ]));
    }

    /** Re-parse the stored file with the (possibly edited) mapping, then import. */
    public function run(): void
    {
        $user = Auth::requireLogin();
        $path = (string) Request::post('file_path', '');
        if ($path === '' || !is_file($this->importsDir() . '/' . basename($path))) {
            $this->uploadError('The import file is no longer available. Please upload it again.');
            return;
        }
        $full = $this->importsDir() . '/' . basename($path);
        $parsed = CsvImport::parse($full);
        $mapping = $this->mappingFromPost($parsed['header']);
        $options = $this->optionsFromPost();
        $distinct = CsvImport::distinctValues($parsed['rows'], $mapping);
        $llm = CsvImport::llmClassify($distinct, !empty($options['ai_assist']));
        $llm = $this->applyLlmOverrides($llm, $distinct);
        try {
            $report = CsvImport::import($parsed['rows'], $mapping, $options, $llm, $user);
        } catch (RuntimeException $e) {
            $this->uploadError($e->getMessage());
            return;
        }
        @unlink($full);
        View::output(View::render('admin/import', [
            'title' => 'Import Assets',
            'step' => 'report',
            'report' => $report,
        ]));
    }

    private function storeUpload(): string
    {
        $f = $_FILES['csv'] ?? null;
        if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->uploadError('Choose a .csv file to import.');
            return '';
        }
        if ((int) $f['size'] > CsvImport::MAX_FILE_MB * 1024 * 1024) {
            $this->uploadError('File too large (max ' . CsvImport::MAX_FILE_MB . ' MB).');
            return '';
        }
        $name = (string) ($f['name'] ?? '');
        if (!preg_match('/\.csv$/i', $name)) {
            $this->uploadError('Only .csv files are supported.');
            return '';
        }
        $dest = $this->importsDir() . '/' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file((string) $f['tmp_name'], $dest)) {
            $this->uploadError('Could not store the uploaded file.');
            return '';
        }
        return $dest;
    }

    /** mapping[<csv column>] = target, validated against TARGETS. */
    private function mappingFromPost(array $header): array
    {
        $post = Request::post('mapping') ?? [];
        if (!is_array($post)) {
            $post = [];
        }
        $map = [];
        foreach ($header as $h) {
            $t = (string) ($post[$h] ?? '');
            $map[$h] = in_array($t, CsvImport::TARGETS, true) ? $t : 'ignore';
        }
        return $map;
    }

    /**
     * Checkbox options: present ("1") = on, absent = off.
     * "Create missing persons" defaults to ON via the checked upload-form
     * checkbox, not via a POST default (an absent key means the user
     * unchecked it).
     */
    private function optionsFromPost(): array
    {
        return [
            'photos' => (bool) Request::post('opt_photos'),
            'create_persons' => (bool) Request::post('opt_create_persons'),
            'ai_assist' => (bool) Request::post('opt_ai_assist'),
        ];
    }

    /**
     * Apply the preview form's llm_override[] values on top of the LLM output.
     * Only values already present in the distinct set are accepted; classes are
     * validated the same way CsvImport::parseClassification() does.
     */
    private function applyLlmOverrides(array $llm, array $distinct): array
    {
        $post = Request::post('llm_override');
        if (!is_array($post)) {
            return $llm;
        }
        $rules = [
            'departments' => ['department', ['real-dept', 'status-note']],
            'persons' => ['person', ['person', 'non-person']],
            'brands' => ['brand', null],
        ];
        foreach ($rules as $field => [$source, $legal]) {
            if (!isset($post[$field]) || !is_array($post[$field])) {
                continue;
            }
            foreach ($post[$field] as $value => $class) {
                $value = (string) $value;
                $class = trim((string) $class);
                if ($class === '' || !isset($distinct[$source][$value])) {
                    continue;
                }
                if ($legal !== null) {
                    if (in_array($class, $legal, true)) {
                        $llm[$field][$value] = $class;
                    }
                } elseif ($this->isBrandVariant($class, array_keys($distinct['brand']))) {
                    $llm[$field][$value] = $class;
                }
            }
        }
        return $llm;
    }

    /** Trim/case variant of one of the input values (mirrors CsvImport's private check). */
    private function isBrandVariant(string $candidate, array $values): bool
    {
        $lc = strtolower($candidate);
        foreach ($values as $v) {
            if (strtolower(trim((string) $v)) === $lc) {
                return true;
            }
        }
        return false;
    }

    private function uploadError(string $msg): void
    {
        Auth::flash('error', $msg);
        Response::redirect('/admin/import');
    }
}
