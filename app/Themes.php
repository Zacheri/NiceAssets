<?php

declare(strict_types=1);

namespace App;

final class Themes
{
    public const DEFAULT_PRESET = 'default';

    /**
     * Curated presets: name => CSS custom property values (the color tokens
     * from the :root block in public/theme/css/app.css).
     *
     * 'default' mirrors the app.css :root block exactly, so rendering it
     * produces no override CSS at all.
     *
     * Palette rationale:
     * - forest: green-tinted light neutrals, deep forest-green primary
     *   (#2e7d4f, ~4.9:1 on white) with a darker hover, soft leaf accent for
     *   gradients, and a dark green sidebar matching the ink hue.
     * - violet: purple-tinted light neutrals, violet-700 primary (#6d28d9,
     *   ~6.3:1 on white) with violet-800 hover, violet-400 accent, dark
     *   violet sidebar.
     * - dark: near-black blue surfaces, near-white ink, primary brightened to
     *   blue-500 with a lighter (blue-400) hover for contrast on dark; status
     *   colors lifted to 400-level shades paired with dark tint backgrounds
     *   so badges stay readable.
     * Status colors (green/amber/red/purple/teal/pink) are left unchanged in
     * the light presets so badge meaning and contrast stay consistent.
     */
    public const PRESETS = [
        'default' => [
            '--bg' => '#f1f5f9',
            '--surface' => '#ffffff',
            '--ink' => '#0f172a',
            '--ink-2' => '#334155',
            '--muted' => '#64748b',
            '--line' => '#e2e8f0',
            '--primary' => '#2563eb',
            '--primary-2' => '#1d4ed8',
            '--accent' => '#38bdf8',
            '--green' => '#16a34a',
            '--green-bg' => '#dcfce7',
            '--amber' => '#d97706',
            '--amber-bg' => '#fef3c7',
            '--red' => '#dc2626',
            '--red-bg' => '#fee2e2',
            '--purple' => '#7c3aed',
            '--purple-bg' => '#ede9fe',
            '--teal' => '#0d9488',
            '--teal-bg' => '#ccfbf1',
            '--pink' => '#db2777',
            '--pink-bg' => '#fce7f3',
            '--slate-bg' => '#e2e8f0',
            '--sidebar' => '#0f172a',
            '--sidebar-2' => '#1e293b',
        ],
        'forest' => [
            '--bg' => '#eef4ee',
            '--surface' => '#ffffff',
            '--ink' => '#182720',
            '--ink-2' => '#3b4d42',
            '--muted' => '#67796d',
            '--line' => '#d8e4da',
            '--primary' => '#2e7d4f',
            '--primary-2' => '#256341',
            '--accent' => '#79b98a',
            '--green' => '#16a34a',
            '--green-bg' => '#dcfce7',
            '--amber' => '#d97706',
            '--amber-bg' => '#fef3c7',
            '--red' => '#dc2626',
            '--red-bg' => '#fee2e2',
            '--purple' => '#7c3aed',
            '--purple-bg' => '#ede9fe',
            '--teal' => '#0d9488',
            '--teal-bg' => '#ccfbf1',
            '--pink' => '#db2777',
            '--pink-bg' => '#fce7f3',
            '--slate-bg' => '#e0eae1',
            '--sidebar' => '#182720',
            '--sidebar-2' => '#24382c',
        ],
        'violet' => [
            '--bg' => '#f4f1fb',
            '--surface' => '#ffffff',
            '--ink' => '#241d36',
            '--ink-2' => '#453d5e',
            '--muted' => '#736b8d',
            '--line' => '#e3ddf1',
            '--primary' => '#6d28d9',
            '--primary-2' => '#5b21b6',
            '--accent' => '#a78bfa',
            '--green' => '#16a34a',
            '--green-bg' => '#dcfce7',
            '--amber' => '#d97706',
            '--amber-bg' => '#fef3c7',
            '--red' => '#dc2626',
            '--red-bg' => '#fee2e2',
            '--purple' => '#7c3aed',
            '--purple-bg' => '#ede9fe',
            '--teal' => '#0d9488',
            '--teal-bg' => '#ccfbf1',
            '--pink' => '#db2777',
            '--pink-bg' => '#fce7f3',
            '--slate-bg' => '#e9e4f5',
            '--sidebar' => '#241d36',
            '--sidebar-2' => '#322a4a',
        ],
        'dark' => [
            '--bg' => '#0b1120',
            '--surface' => '#141d30',
            '--ink' => '#e7edf8',
            '--ink-2' => '#b9c5da',
            '--muted' => '#8595ae',
            '--line' => '#27344b',
            '--primary' => '#3b82f6',
            '--primary-2' => '#60a5fa',
            '--accent' => '#38bdf8',
            '--green' => '#4ade80',
            '--green-bg' => '#16352a',
            '--amber' => '#fbbf24',
            '--amber-bg' => '#3a2d12',
            '--red' => '#f87171',
            '--red-bg' => '#3b1a1a',
            '--purple' => '#a78bfa',
            '--purple-bg' => '#2b2145',
            '--teal' => '#2dd4bf',
            '--teal-bg' => '#14332f',
            '--pink' => '#f472b6',
            '--pink-bg' => '#3a2033',
            '--slate-bg' => '#1e293b',
            '--sidebar' => '#0d1526',
            '--sidebar-2' => '#1c2941',
        ],
    ];

    /** All color tokens a preset may set (used to diff preset CSS). */
    public const COLOR_TOKENS = [
        '--bg',
        '--surface',
        '--ink',
        '--ink-2',
        '--muted',
        '--line',
        '--primary',
        '--primary-2',
        '--accent',
        '--green',
        '--green-bg',
        '--amber',
        '--amber-bg',
        '--red',
        '--red-bg',
        '--purple',
        '--purple-bg',
        '--teal',
        '--teal-bg',
        '--pink',
        '--pink-bg',
        '--slate-bg',
        '--sidebar',
        '--sidebar-2',
    ];

    /** The color tokens a user may override with a custom color. */
    public const OVERRIDABLE = [
        '--primary',
        '--accent',
        '--bg',
        '--sidebar',
    ];

    public static function normalizePreset(mixed $raw): string
    {
        return is_string($raw) && array_key_exists($raw, self::PRESETS)
            ? $raw
            : self::DEFAULT_PRESET;
    }

    public static function isValidColor(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    public static function normalizeColor(string $value): string
    {
        $value = strtolower(trim($value));
        return self::isValidColor($value) ? $value : '';
    }

    /**
     * Validate the admin "available presets" list (JSON string or array).
     * Unknown names are dropped, duplicates removed; an empty or invalid
     * list falls back to all presets so the menu is never empty.
     */
    public static function normalizeAvailable(mixed $raw): array
    {
        $all = array_keys(self::PRESETS);
        if ($raw === null) {
            return $all;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return $all;
        }
        $list = [];
        foreach ($raw as $name) {
            if (is_string($name) && array_key_exists($name, self::PRESETS) && !in_array($name, $list, true)) {
                $list[] = $name;
            }
        }
        return $list !== [] ? $list : $all;
    }

    /**
     * Validate a stored per-user theme (JSON string or array). Never throws:
     * any tampered or malformed input degrades to the default preset with no
     * custom colors, and color entries outside the OVERRIDABLE tokens or in
     * an invalid format are dropped.
     */
    public static function normalizeUserTheme(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return ['preset' => self::DEFAULT_PRESET, 'colors' => []];
        }
        $colors = [];
        $input = $raw['colors'] ?? null;
        if (is_array($input)) {
            foreach (self::OVERRIDABLE as $token) {
                $value = $input[$token] ?? null;
                if (is_string($value)) {
                    $value = self::normalizeColor($value);
                    if ($value !== '') {
                        $colors[$token] = $value;
                    }
                }
            }
        }
        return ['preset' => self::normalizePreset($raw['preset'] ?? null), 'colors' => $colors];
    }

    /** Preset tokens with the user's custom colors layered on top. */
    public static function merge(string $preset, array $colors): array
    {
        return array_merge(self::PRESETS[self::normalizePreset($preset)], $colors);
    }

    /**
     * Resolve the effective theme for render time. Re-validates everything
     * (settings values and the user's stored theme) so a tampered DB value
     * degrades to the default preset instead of rendering untrusted CSS.
     *
     * A null/empty stored theme means "follow the company default": the
     * effective preset is the admin's default (after the availability
     * check), distinct from an explicit {"preset":"default"} choice.
     *
     * Returns preset, colors, available, default, follow_default, and css
     * (a "body{...}" override block, or '' when nothing differs from the
     * built-in default preset).
     */
    public static function resolve(?array $user, mixed $defaultPresetRaw, mixed $availableRaw): array
    {
        $available = self::normalizeAvailable($availableRaw);
        $defaultPreset = self::normalizePreset($defaultPresetRaw);
        if (!in_array($defaultPreset, $available, true)) {
            $defaultPreset = $available[0];
        }
        $raw = $user['theme'] ?? null;
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [
                'preset' => $defaultPreset,
                'colors' => [],
                'available' => $available,
                'default' => $defaultPreset,
                'follow_default' => true,
                'css' => self::cssFor(self::merge($defaultPreset, [])),
            ];
        }
        $theme = self::normalizeUserTheme($raw);
        if (!in_array($theme['preset'], $available, true)) {
            $theme['preset'] = $defaultPreset;
        }
        return [
            'preset' => $theme['preset'],
            'colors' => $theme['colors'],
            'available' => $available,
            'default' => $defaultPreset,
            'follow_default' => false,
            'css' => self::cssFor(self::merge($theme['preset'], $theme['colors'])),
        ];
    }

    /**
     * Build the override CSS for a merged token map: only tokens that differ
     * from the default preset are emitted. Tokens come from the fixed
     * allow-list and values are re-validated as hex, so the output is safe
     * to inline without escaping.
     */
    public static function cssFor(array $merged): string
    {
        $base = self::PRESETS[self::DEFAULT_PRESET];
        $lines = [];
        foreach (self::COLOR_TOKENS as $token) {
            $value = $merged[$token] ?? null;
            if (is_string($value) && self::isValidColor($value) && ($base[$token] ?? null) !== strtolower($value)) {
                $lines[] = $token . ':' . $value;
            }
        }
        return $lines === [] ? '' : 'body{' . implode(';', $lines) . '}';
    }
}
