<?php
/**
 * Super Admin guard pentru PestZone / EuroPrest CRM.
 *
 * Scop:
 * - admin normal: poate lucra in platforma conform drepturilor existente;
 * - super admin: singurul care poate modifica Date companie si Reset platforma.
 *
 * Configurare recomandata in config.local.php:
 * return [
 *   ...
 *   'super_admin_user_id' => 1,
 *   // sau, optional:
 *   'super_admin_email' => 'emailul-tau@domeniu.ro',
 * ];
 *
 * Daca nu setezi nimic, contul cu users.id = 1 este super admin implicit.
 */

if (!function_exists('pz_current_user_id_safe')) {
    function pz_current_user_id_safe(): ?int {
        if (function_exists('current_user_id')) {
            return current_user_id();
        }
        return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

if (!function_exists('pz_current_user_email_safe')) {
    function pz_current_user_email_safe(): string {
        if (function_exists('current_user_email')) {
            return strtolower(trim((string)current_user_email()));
        }
        return strtolower(trim((string)($_SESSION['user_email'] ?? '')));
    }
}

if (!function_exists('pz_is_super_admin')) {
    function pz_is_super_admin(): bool {
        if (!function_exists('is_admin') || !is_admin()) {
            return false;
        }

        $userId = pz_current_user_id_safe();
        $email = pz_current_user_email_safe();

        if (defined('PZ_SUPER_ADMIN_USER_ID') && $userId !== null && (int)PZ_SUPER_ADMIN_USER_ID === $userId) {
            return true;
        }
        if (defined('PZ_SUPER_ADMIN_EMAIL') && $email !== '' && strtolower((string)PZ_SUPER_ADMIN_EMAIL) === $email) {
            return true;
        }

        $cfg = $GLOBALS['dbConfig'] ?? [];
        if (is_array($cfg)) {
            if (!empty($cfg['super_admin_user_id']) && $userId !== null && (int)$cfg['super_admin_user_id'] === $userId) {
                return true;
            }
            if (!empty($cfg['super_admin_email']) && $email !== '' && strtolower(trim((string)$cfg['super_admin_email'])) === $email) {
                return true;
            }
        }

        return $userId === 1;
    }
}

if (!function_exists('pz_require_super_admin')) {
    function pz_require_super_admin(): void {
        if (!pz_is_super_admin()) {
            http_response_code(403);
            echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acces restrictionat</title>';
            echo '<style>body{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#f6f8fb;color:#14213d;margin:0;padding:32px}.box{max-width:620px;margin:8vh auto;background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.06)}h1{margin:0 0 10px;font-size:22px}.muted{color:#64748b;line-height:1.55}.btn{display:inline-flex;margin-top:18px;background:#0071A3;color:#fff;text-decoration:none;padding:10px 14px;border-radius:12px;font-weight:700}</style></head><body>';
            echo '<div class="box"><h1>Acces permis doar super administratorului</h1><p class="muted">Aceasta zona poate fi accesata doar de contul principal al platformei. Utilizatorii administratori normali nu pot modifica datele companiei si nu pot reseta platforma.</p><a class="btn" href="settings.php">Inapoi la setari</a></div>';
            echo '</body></html>';
            exit;
        }
    }
}
