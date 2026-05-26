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

/*
|--------------------------------------------------------------------------
| Identitate aplicație — nume, domeniu, email suport
|--------------------------------------------------------------------------
| Helper-ele de mai jos centralizează numele aplicației și domeniul.
| Pe SaaS Emma vor citi din `platform_settings` (tabela introdusă în
| saas_emma_01_platform_tables.sql). Până la migrare, returnează default-uri
| sigure ('Emma', 'emma.ro', 'office@emma.ro'), cu posibilitate de override
| din `config.local.php` (cheile `app_name`, `app_domain`, `app_support_email`).
|
| Folosire în paginile noi (signup, onboarding, admin platformă):
|   echo h(pz_app_name());           // "Emma"
|   echo h(pz_app_domain());         // "emma.ro"
|   echo h(pz_app_support_email());  // "office@emma.ro"
|
| Paginile vechi continuă să folosească string-urile lor existente.
| Migrarea string-urilor vizibile se face progresiv, pagină cu pagină,
| conform PLAN_SAAS_EMMA.md §4.
|--------------------------------------------------------------------------
*/

if (!function_exists('pz_platform_setting_local')) {
    /**
     * Helper intern: încearcă citirea unei setări platformă din DB,
     * apoi din $dbConfig (config.local.php), apoi default.
     * Cache static pentru request.
     */
    function pz_platform_setting_local(string $key, string $localKey, ?string $default = null): ?string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        // 1) DB platform_settings (dacă tabela există și PDO disponibil)
        global $pdo, $dbConfig;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
                $stmt->execute([$key]);
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== null && $val !== '') {
                    $cache[$key] = (string)$val;
                    return $cache[$key];
                }
            } catch (Throwable $e) {
                // tabela încă nu există — continuă spre fallback
            }
        }

        // 2) config.local.php
        if (isset($dbConfig) && is_array($dbConfig) && !empty($dbConfig[$localKey])) {
            $cache[$key] = (string)$dbConfig[$localKey];
            return $cache[$key];
        }

        // 3) Default
        $cache[$key] = $default;
        return $default;
    }
}

if (!function_exists('pz_app_name')) {
    /**
     * Numele aplicației afișat în UI (title, header, footer, email-uri).
     * Default: 'Emma'.
     */
    function pz_app_name(): string
    {
        return (string)(pz_platform_setting_local('app.name', 'app_name', 'Emma'));
    }
}

if (!function_exists('pz_app_domain')) {
    /**
     * Domeniul principal al aplicației. Default: 'emma.ro'.
     */
    function pz_app_domain(): string
    {
        return (string)(pz_platform_setting_local('app.domain', 'app_domain', 'emma.ro'));
    }
}

if (!function_exists('pz_app_origin')) {
    /**
     * Origin-ul aplicației (folosit pentru CORS, OAuth callbacks).
     * Default: 'https://app.emma.ro'.
     */
    function pz_app_origin(): string
    {
        return (string)(pz_platform_setting_local('app.app_origin', 'app_origin', 'https://app.emma.ro'));
    }
}

if (!function_exists('pz_app_support_email')) {
    /**
     * Email de contact afișat în UI. Default: 'office@emma.ro'.
     */
    function pz_app_support_email(): string
    {
        return (string)(pz_platform_setting_local('app.support_email', 'app_support_email', 'office@emma.ro'));
    }
}
