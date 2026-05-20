<?php

/*
|--------------------------------------------------------------------------
| app_helpers.php
|--------------------------------------------------------------------------
| Helpers globale folosite în întreaga aplicație (escape HTML, etc).
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

