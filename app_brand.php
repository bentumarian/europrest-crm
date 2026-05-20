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
        // Foloseste iconul/logo-ul existent al clientului, dacă este incarcat in /assets.
        // Ordinea permite să pui pe server oricare dintre fișierele de mai jos fără să modifici codul.
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

