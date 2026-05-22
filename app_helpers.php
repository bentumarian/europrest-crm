<?php

/*
|--------------------------------------------------------------------------
| app_helpers.php
|--------------------------------------------------------------------------
| Helpers globale folosite în întreaga aplicație (escape HTML, format dată).
| Texte UTF-8, cu diacritice păstrate.
|--------------------------------------------------------------------------
*/

if (!function_exists('app_h')) {
    function app_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('h')) {
    /**
     * Alias scurt pentru app_h(). Folosit pe scară largă în paginile vechi.
     * Identic ca implementare cu app_h().
     */
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Format dată — un singur format pe toată aplicația
|--------------------------------------------------------------------------
| Standard: dd.mm.yyyy (ex: 22.05.2026)
| Acceptă: timestamp Unix (int), string parsabil de strtotime (ex: "2026-05-22"),
| sau DateTime/DateTimeImmutable.
| Returnează „—" pentru valori goale / 0 / null / invalid.
*/

if (!function_exists('pz_date')) {
    /**
     * Format scurt: 22.05.2026
     */
    function pz_date($ts): string
    {
        if ($ts === null || $ts === '' || $ts === '0' || $ts === 0) return '—';
        if ($ts instanceof DateTimeInterface) return $ts->format('d.m.Y');
        $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        if (!$t || $t < 0) return '—';
        return date('d.m.Y', $t);
    }
}

if (!function_exists('pz_datetime')) {
    /**
     * Format scurt cu oră: 22.05.2026, 10:00
     */
    function pz_datetime($ts): string
    {
        if ($ts === null || $ts === '' || $ts === '0' || $ts === 0) return '—';
        if ($ts instanceof DateTimeInterface) return $ts->format('d.m.Y, H:i');
        $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        if (!$t || $t < 0) return '—';
        return date('d.m.Y, H:i', $t);
    }
}

if (!function_exists('pz_date_long')) {
    /**
     * Format lung românesc: 22 mai 2026
     * Folosit pe header-e și fișe individuale.
     */
    function pz_date_long($ts): string
    {
        if ($ts === null || $ts === '' || $ts === '0' || $ts === 0) return '—';
        if ($ts instanceof DateTimeInterface) {
            $t = (int)$ts->format('U');
        } else {
            $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        }
        if (!$t || $t < 0) return '—';
        $months = [
            'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie',
            'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie',
        ];
        return date('j', $t) . ' ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }
}

if (!function_exists('pz_time')) {
    /**
     * Doar ora: 10:00
     */
    function pz_time($ts): string
    {
        if ($ts === null || $ts === '' || $ts === '0' || $ts === 0) return '—';
        if ($ts instanceof DateTimeInterface) return $ts->format('H:i');
        // dacă e deja format „HH:MM:SS" sau „HH:MM"
        if (is_string($ts) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $ts)) {
            return substr($ts, 0, 5);
        }
        $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
        if (!$t || $t < 0) return '—';
        return date('H:i', $t);
    }
}
