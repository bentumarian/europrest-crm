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
    /**
     * Returnează tag-ul <img> cu logo-ul aplicației.
     *
     * @param string $class    Clasa CSS aplicată pe <img>
     * @param string $variant  'default' (albastru, pe sidebar dark) sau 'white' (alb, pentru fundaluri albastre — ex. mobile header)
     */
    function app_brand_logo(string $class = 'brand-logo', string $variant = 'default', string $colorVar = ''): string
    {
        $isWhite = ($variant === 'white' || $variant === 'light');

        // 1) Mai întâi cautăm numele canonice — comod pentru control explicit
        // Emma SaaS: prioritate explicită pe brand-emma-*; păstrăm și fallback-urile
        // istorice (brand-icon, brand-monogram, logo) pentru retrocompatibilitate.
        $logoCandidates = $isWhite ? [
            'assets/brand-emma-white-orange.svg',
            'assets/brand-emma-white-orange.png',
            'assets/brand-emma-white.svg',
            'assets/brand-emma-white.png',
            'assets/brand-icon-white.png',
            'assets/brand-icon-white.svg',
            'assets/brand-monogram-white.png',
            'assets/logo-white.png',
        ] : [
            'assets/brand-emma.svg',
            'assets/brand-emma.png',
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
                $url = $logoPath . '?v=' . $version;
                if ($colorVar !== '') {
                    return _app_brand_logo_masked_html($class, $url, $colorVar);
                }
                return '<img class="' . app_h($class) . '" src="' . app_h($url) . '" alt="">';
            }
        }

        // 2) Fallback inteligent — orice .png / .svg / .jpg din /assets/ care conține
        //    în nume unul din cuvintele cheie (case-insensitive). Util când clientul
        //    încarcă fișierul cu nume neașteptat, ex. "MONOGRAMA PEST ZONE_BLUE.png"
        //    sau "MONOGRAMA PEST ZONE_WHITE.png" pentru varianta albă.
        $assetsDir = __DIR__ . '/assets';
        if (is_dir($assetsDir)) {
            $keywords = ['monogram', 'brand', 'logo', 'pest', 'icon', 'mark'];
            $extensions = ['png', 'svg', 'jpg', 'jpeg', 'webp'];
            // Pentru variant white: preferăm fișiere care conțin "white", "alb", "light"
            // Pentru variant default: preferăm fișiere care conțin "blue", "dark", "color" — sau orice fără hint de culoare
            $variantTokens = $isWhite ? ['white', 'alb', 'light'] : ['blue', 'dark', 'color'];
            $matches = [];

            foreach (scandir($assetsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $full = $assetsDir . '/' . $entry;
                if (!is_file($full)) continue;
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($ext, $extensions, true)) continue;
                $name = strtolower($entry);

                $hasKeyword = false;
                foreach ($keywords as $kw) {
                    if (strpos($name, $kw) !== false) { $hasKeyword = true; break; }
                }
                if (!$hasKeyword) continue;

                // Scor: 0 = perfect match (conține token-ul de variantă), 1 = neutru, 2 = wrong variant
                $variantScore = 1;
                foreach ($variantTokens as $tok) {
                    if (strpos($name, $tok) !== false) { $variantScore = 0; break; }
                }
                if ($variantScore === 1) {
                    // Penalizează dacă conține token-ul variantei opuse
                    $oppositeTokens = $isWhite ? ['blue', 'dark', 'color'] : ['white', 'alb', 'light'];
                    foreach ($oppositeTokens as $tok) {
                        if (strpos($name, $tok) !== false) { $variantScore = 2; break; }
                    }
                }

                // Preferăm .png și .svg peste alte formate
                $extScore = ($ext === 'png') ? 1 : (($ext === 'svg') ? 2 : 3);

                $matches[] = [
                    'path' => 'assets/' . $entry,
                    'variant_score' => $variantScore,
                    'ext_score' => $extScore,
                    'mtime' => @filemtime($full) ?: 0,
                ];
            }

            if (!empty($matches)) {
                // Sortează: variant_score crescător, apoi ext_score crescător, apoi mtime descrescător
                usort($matches, function ($a, $b) {
                    if ($a['variant_score'] !== $b['variant_score']) return $a['variant_score'] - $b['variant_score'];
                    if ($a['ext_score'] !== $b['ext_score']) return $a['ext_score'] - $b['ext_score'];
                    return $b['mtime'] - $a['mtime'];
                });
                $pick = $matches[0];
                $version = $pick['mtime'] ?: time();
                $url = $pick['path'] . '?v=' . $version;
                if ($colorVar !== '') {
                    return _app_brand_logo_masked_html($class, $url, $colorVar);
                }
                return '<img class="' . app_h($class) . '" src="' . app_h($url) . '" alt="">';
            }
        }

        return '<span class="brand-fallback" aria-hidden="true"></span>';
    }
}

if (!function_exists('_app_brand_logo_masked_html')) {
    /**
     * Returnează un <span> care folosește PNG-ul ca CSS mask + background-color din var.
     * Asta permite recolorare 100% precisă a logo-ului — orice culoare originală
     * devine fix culoarea specificată în $colorVar (ex. 'var(--pz-gr)').
     */
    function _app_brand_logo_masked_html(string $class, string $url, string $colorVar): string
    {
        $maskUrl = app_h($url);
        $cls     = app_h($class);
        $color   = app_h($colorVar);
        $style   = "background-color: {$color}; "
                 . "-webkit-mask-image: url('{$maskUrl}'); mask-image: url('{$maskUrl}'); "
                 . "-webkit-mask-size: contain; mask-size: contain; "
                 . "-webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; "
                 . "-webkit-mask-position: center; mask-position: center; "
                 . "display: inline-block;";
        return '<span class="' . $cls . ' ' . $cls . '-masked" style="' . $style . '" aria-label="Emma" role="img"></span>';
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

