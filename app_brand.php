<?php

/*
|--------------------------------------------------------------------------
| app_brand.php
|--------------------------------------------------------------------------
| Identitatea de brand a aplicației: logo + header mobil.
| Apel: echo app_brand_logo();
|       render_mobile_app_header();
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/app_helpers.php';

if (!function_exists('app_brand_logo')) {
    function app_brand_logo(string $class = 'brand-logo'): string
    {
        // 1) Mai întâi cautăm numele canonice — comod pentru control explicit
        $logoCandidates = [
            'assets/brand-icon.png',
            'assets/brand-icon.svg',
            'assets/brand-monogram.png',
            'assets/brand-monogram.svg',
            'assets/logo.png',
            'assets/logo.svg',
            'assets/icon.png',
            'assets/favicon.png',
            'assets/favicon.ico',
        ];

        foreach ($logoCandidates as $logoPath) {
            $logoFile = __DIR__ . '/' . $logoPath;
            if (is_file($logoFile)) {
                $version = @filemtime($logoFile) ?: time();
                return '<img class="' . app_h($class) . '" src="' . app_h($logoPath . '?v=' . $version) . '" alt="">';
            }
        }

        // 2) Fallback inteligent — orice .png / .svg / .jpg din /assets/ care conține
        //    în nume unul din cuvintele cheie (case-insensitive). Util când clientul
        //    încarcă fișierul cu nume neașteptat, ex. "MONOGRAMA PEST ZONE_BLUE.png".
        $assetsDir = __DIR__ . '/assets';
        if (is_dir($assetsDir)) {
            $keywords = ['monogram', 'brand', 'logo', 'pest', 'icon', 'mark'];
            $extensions = ['png', 'svg', 'jpg', 'jpeg', 'webp'];
            $matches = [];

            foreach (scandir($assetsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $full = $assetsDir . '/' . $entry;
                if (!is_file($full)) continue;
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($ext, $extensions, true)) continue;
                $name = strtolower($entry);
                foreach ($keywords as $kw) {
                    if (strpos($name, $kw) !== false) {
                        // Preferăm .svg și .png peste alte formate
                        $priority = ($ext === 'png') ? 1 : (($ext === 'svg') ? 2 : 3);
                        $matches[] = ['path' => 'assets/' . $entry, 'priority' => $priority, 'mtime' => @filemtime($full) ?: 0];
                        break;
                    }
                }
            }

            if (!empty($matches)) {
                // Sortează după priority crescător, apoi mtime descrescător (cel mai nou primul)
                usort($matches, function ($a, $b) {
                    if ($a['priority'] !== $b['priority']) return $a['priority'] - $b['priority'];
                    return $b['mtime'] - $a['mtime'];
                });
                $pick = $matches[0];
                $version = $pick['mtime'] ?: time();
                return '<img class="' . app_h($class) . '" src="' . app_h($pick['path'] . '?v=' . $version) . '" alt="">';
            }
        }

        return '<span class="brand-fallback" aria-hidden="true"></span>';
    }
}

if (!function_exists('render_mobile_app_header')) {
    function render_mobile_app_header(): void
    {
        ?>
        <div class="app-mobile-header">
            <?= app_brand_logo('app-mobile-logo') ?>
        </div>
        <?php
    }
}

