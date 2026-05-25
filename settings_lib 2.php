<?php
if (!function_exists('pz_settings_ensure_schema')) {
    function pz_settings_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
            setting_value LONGTEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('pz_company_defaults')) {
    function pz_company_defaults(): array
    {
        return [
            'company.logo_text' => 'EUROPREST',
            'company.display_name' => 'EUROPREST TEAM 98',
            'company.legal_name' => 'EUROPREST TEAM 98',
            'company.cui' => 'RO10135994',
            'company.reg_com' => 'J1998000627130',
            'company.address' => 'Interioara 3, Lot 2/2/6/1, Sp. 3, Constanta',
            'company.bank_name' => 'TRANSILVANIA',
            'company.bank_account' => 'RO13BTRLRONCRT0T159E8A01',
            'company.email' => 'office@euro-prest.ro',
            'company.phone' => '0786888800',
            'company.website' => 'www.euro-prest.ro',
            'company.legal_representative_name' => 'Marian Bentu',
            'company.legal_representative_role' => '',
            'company.authorizations' => 'DSV 462 / 26.02.2020',
            'company.provider_role_label' => 'FURNIZOR DE SERVICII',
            'company.notes' => '',
        ];
    }
}

if (!function_exists('pz_document_design_defaults')) {
    function pz_document_design_defaults(): array
    {
        return [
            'document.header_logo_enabled' => '1',
            'document.header_logo_path' => '',
            'document.header_logo_text' => '',
            'document.header_logo_align' => 'center',
            'document.header_logo_width_mm' => '60',
            'document.header_logo_height_mm' => '14',
            'document.header_company_text' => '',
            'document.header_height_mm' => '24',
            'document.header_strip_enabled' => '0',
            'document.footer_enabled' => '1',
            'document.footer_text' => '',
            'document.footer_height_mm' => '14',
            'document.footer_line_enabled' => '0',
            'document.pv_compact_enabled' => '1',
            'document.pv_page_margin_top_mm' => '4',
            'document.pv_page_margin_bottom_mm' => '7',
            'document.pv_header_height_mm' => '10',
            'document.pv_footer_enabled' => '0',
            'document.pv_footer_height_mm' => '0',
            'document.pv_body_font_size_pt' => '9.2',
            'document.pv_line_height' => '1.18',
            'document.company_stamp_path' => '',
            'document.company_stamp_width_mm' => '36',
            'document.company_stamp_height_mm' => '36',
        ];
    }
}

if (!function_exists('pz_settings_get_all')) {
    function pz_settings_get_all(PDO $pdo, ?array $defaults = null): array
    {
        pz_settings_ensure_schema($pdo);
        $settings = $defaults ?? [];
        foreach ($pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        return $settings;
    }
}

if (!function_exists('pz_settings_set_many')) {
    function pz_settings_set_many(PDO $pdo, array $values): void
    {
        pz_settings_ensure_schema($pdo);
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
        foreach ($values as $key => $value) {
            $stmt->execute([(string)$key, (string)$value]);
        }
    }
}

if (!function_exists('pz_company_settings')) {
    function pz_company_settings(?PDO $pdoArg = null): array
    {
        if (!$pdoArg instanceof PDO) {
            global $pdo;
            $pdoArg = $pdo ?? null;
        }
        return $pdoArg instanceof PDO ? pz_settings_get_all($pdoArg, pz_company_defaults()) : pz_company_defaults();
    }
}

if (!function_exists('pz_document_design_settings')) {
    function pz_document_design_settings(?PDO $pdoArg = null): array
    {
        if (!$pdoArg instanceof PDO) {
            global $pdo;
            $pdoArg = $pdo ?? null;
        }

        $defaults = pz_document_design_defaults();
        if ($pdoArg instanceof PDO) {
            $settings = pz_settings_get_all($pdoArg, $defaults);
        } else {
            $settings = $defaults;
        }

        $company = $pdoArg instanceof PDO ? pz_company_settings($pdoArg) : pz_company_defaults();

        if (trim((string)($settings['document.header_logo_align'] ?? '')) === '') {
            $settings['document.header_logo_align'] = 'center';
        }
        if (!in_array($settings['document.header_logo_align'], ['left', 'center', 'right'], true)) {
            $settings['document.header_logo_align'] = 'center';
        }
        if (trim((string)($settings['document.footer_text'] ?? '')) === '') {
            $settings['document.footer_text'] = pz_document_default_footer_text($pdoArg);
        }

        return $settings;
    }
}

if (!function_exists('pz_clean_join')) {
    function pz_clean_join(array $parts, string $glue = ', '): string
    {
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') $out[] = $part;
        }
        return implode($glue, $out);
    }
}

if (!function_exists('pz_company_header_text')) {
    function pz_company_header_text(?PDO $pdo = null): string
    {
        $s = pz_company_settings($pdo);
        $name = trim((string)($s['company.display_name'] ?? '')) ?: trim((string)($s['company.legal_name'] ?? ''));
        return pz_clean_join([
            $name,
            pz_clean_join([$s['company.cui'] ?? '', $s['company.reg_com'] ?? ''], ' / '),
            $s['company.address'] ?? '',
            pz_clean_join([$s['company.website'] ?? '', $s['company.email'] ?? '', $s['company.phone'] ?? ''], ' | '),
        ], "\n");
    }
}

if (!function_exists('pz_company_logo_text')) {
    function pz_company_logo_text(?PDO $pdo = null): string
    {
        $s = pz_company_settings($pdo);
        return trim((string)($s['company.logo_text'] ?? '')) ?: 'EUROPREST';
    }
}

if (!function_exists('pz_company_provider_text')) {
    function pz_company_provider_text(?PDO $pdo = null): string
    {
        $s = pz_company_settings($pdo);
        $legal = trim((string)($s['company.legal_name'] ?? '')) ?: trim((string)($s['company.display_name'] ?? ''));
        $parts = [$legal ?: '................................'];
        if (trim((string)($s['company.address'] ?? '')) !== '') $parts[] = 'cu sediul in ' . trim((string)$s['company.address']);
        if (trim((string)($s['company.reg_com'] ?? '')) !== '') $parts[] = 'inregistrata la Registrul Comertului ' . trim((string)$s['company.reg_com']);
        if (trim((string)($s['company.cui'] ?? '')) !== '') $parts[] = 'Cod de identificare fiscala ' . trim((string)$s['company.cui']);
        $iban = trim((string)($s['company.bank_account'] ?? ''));
        $bank = trim((string)($s['company.bank_name'] ?? ''));
        if ($iban !== '') $parts[] = 'cu un cont bancar ' . $iban . ($bank !== '' ? ' deschis la ' . $bank : '');
        if (trim((string)($s['company.authorizations'] ?? '')) !== '') $parts[] = 'Autorizatii: ' . trim((string)$s['company.authorizations']);
        $rep = trim((string)($s['company.legal_representative_name'] ?? ''));
        $role = trim((string)($s['company.legal_representative_role'] ?? ''));
        if ($rep !== '') $parts[] = 'reprezentant legal ' . $rep . ($role !== '' ? ', in calitate de ' . $role : '');
        $providerRole = trim((string)($s['company.provider_role_label'] ?? '')) ?: 'FURNIZOR DE SERVICII';
        return implode(', ', $parts) . ', in calitate de ' . $providerRole . '.';
    }
}

if (!function_exists('pz_document_default_footer_text')) {
    function pz_document_default_footer_text(?PDO $pdo = null): string
    {
        $s = pz_company_settings($pdo);
        $name = trim((string)($s['company.display_name'] ?? '')) ?: trim((string)($s['company.legal_name'] ?? ''));
        return pz_clean_join([
            $name,
            $s['company.website'] ?? '',
            $s['company.email'] ?? '',
            $s['company.phone'] ?? '',
        ], ' | ');
    }
}

if (!function_exists('pz_document_logo_path')) {
    function pz_document_logo_path(?PDO $pdo = null): string
    {
        $s = pz_document_design_settings($pdo);
        $path = trim((string)($s['document.header_logo_path'] ?? ''));
        if ($path === '') {
            return '';
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($path, '..') !== false) {
            return '';
        }
        if (!file_exists(__DIR__ . '/' . $path)) {
            return '';
        }
        return $path;
    }
}

if (!function_exists('pz_document_stamp_path')) {
    /**
     * Returneaza calea relativa catre ștampila firmei (PNG/JPG/WEBP) sau '' dacă nu este configurata.
     * Ștampila apare automat doar pe procesele verbale, langa semnătura emitent.
     */
    function pz_document_stamp_path(?PDO $pdo = null): string
    {
        $s = pz_document_design_settings($pdo);
        $path = trim((string)($s['document.company_stamp_path'] ?? ''));
        if ($path === '') {
            return '';
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($path, '..') !== false) {
            return '';
        }
        if (!file_exists(__DIR__ . '/' . $path)) {
            return '';
        }
        return $path;
    }
}
