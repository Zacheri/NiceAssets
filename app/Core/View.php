<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AlertEngine;

final class View
{
    public static function render(string $template, array $data = [], bool $useLayout = true): string
    {
        $data['title'] = $data['title'] ?? Config::get('app_name');
        ob_start();
        extract($data, EXTR_SKIP);
        include BASE_PATH . '/templates/' . $template . '.php';
        $content = ob_get_clean();

        if (!$useLayout) {
            return $content;
        }
        $data['content'] = $content;
        $data['user'] = Auth::user();
        $data['flash'] = Auth::takeFlash();
        $data['app_name'] = Config::get('app_name');
        $data['app_version'] = Config::get('app_version');
        $data['page'] = $template;
        if ($data['user'] !== null) {
            if (!isset($data['alerts'])) {
                $data['alerts'] = AlertEngine::dashboard($data['user']);
            }
            if (!isset($data['important_count']) && isset($data['alerts'])) {
                $data['important_count'] = count(array_filter($data['alerts'], static fn ($a) => $a['severity'] === 'important'));
            }
        }
        ob_start();
        extract($data, EXTR_SKIP);
        include BASE_PATH . '/templates/layout.php';
        return ob_get_clean();
    }

    public static function partial(string $name, array $data = []): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        include BASE_PATH . '/templates/partials/' . $name . '.php';
        return ob_get_clean();
    }

    public static function output(string $html): never
    {
        echo $html;
        exit;
    }
}
