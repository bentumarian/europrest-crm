<?php

/*
|--------------------------------------------------------------------------
| clients_helpers.php
|--------------------------------------------------------------------------
| Helper-i folosiți de pagina clients.php și de modulul ANAF.
| Grupați pe categorii:
|   - Encoding / text cleanup (UTF-8, diacritice, fix-uri mojibake)
|   - HTML escape specific (c_h, c_h_address, c_h_raw)
|   - DB schema introspection (c_table_exists, c_column_exists, c_ensure_column)
|   - Input cleaning (telefon, CUI, surface unit, decimal)
|   - View helpers (label-uri status, adresă, contact)
|--------------------------------------------------------------------------
*/

function c_fix_encoding_issues($value): string {
    $value = (string)$value;

    if ($value === '') {
        return '';
    }

    // Reparatie doar la afișare pentru cele mai comune texte romanesti salvate gresit in DB.
    $map = [
        'Äƒ' => 'ă', 'Ä‚' => 'Ă',
        'Ã¢' => 'â', 'Ã‚' => 'Â',
        'Ã®' => 'î', 'ÃŽ' => 'Î',
        'È™' => 'ș', 'È˜' => 'Ș',
        'È›' => 'ț', 'Èš' => 'Ț',
        'ÅŸ' => 'ș', 'Åž' => 'Ș',
        'Å£' => 'ț', 'Å¢' => 'Ț',
    ];

    $value = strtr($value, $map);
    $value = str_replace("\xC2\xA0", ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function c_normalize_ro_text($value): string {
    $value = c_fix_encoding_issues($value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function c_clean_address_display($value): string {
    $value = c_normalize_ro_text($value);

    if ($value === '' || $value === '-') {
        return $value;
    }

    // Unele adrese vechi au fost deja salvate in baza de date cu semnul ? in locul literelor romanesti.
    // Cand caracterul a fost inlocuit cu ?, litera originala nu mai poate fi recuperata 100%.
    // Reparam aici cele mai frecvente cazuri din adrese si eliminam restul semnelor vizibile.
    $value = str_replace(["�", "□", "¤"], "?", $value);

    $exactMap = [
        'CONSTAN?A' => 'CONSTANȚA',
        'Constan?a' => 'Constanța',
        'constan?a' => 'constanța',
        'N?VODARI' => 'NĂVODARI',
        'N?vodari' => 'Năvodari',
        'n?vodari' => 'năvodari',
        'VOD?' => 'VODĂ',
        'Vod?' => 'Vodă',
        'vod?' => 'vodă',
        '?TEFAN' => 'ȘTEFAN',
        '?tefan' => 'Ștefan',
        '?OS.' => 'ȘOS.',
        '?os.' => 'Șos.',
        '?OSEAUA' => 'ȘOSEAUA',
        '?oseaua' => 'Șoseaua',
    ];

    $value = strtr($value, $exactMap);

    $regexMap = [
        '/\bCONSTAN\?A\b/ui' => 'CONSTANȚA',
        '/\bN\?VODARI\b/ui' => 'NĂVODARI',
        '/\bVOD\?\b/ui' => 'VODĂ',
        '/\bM\?R\?+E\?TI\b/ui' => 'MĂRĂȘEȘTI',
        '/\bM\?R\?+S\?TI\b/ui' => 'MĂRĂȘEȘTI',
        '/\bM\?R\?SE\?TI\b/ui' => 'MĂRĂȘEȘTI',
        '/\bM\?R\?\?E\?TI\b/ui' => 'MĂRĂȘEȘTI',
        '/\bD\?MBOVI\?A\b/ui' => 'DÂMBOVIȚA',
        '/\bIALOMI\?A\b/ui' => 'IALOMIȚA',
        '/\bTULCEA\b/ui' => 'TULCEA',
    ];

    foreach ($regexMap as $pattern => $replacement) {
        $value = preg_replace($pattern, $replacement, $value) ?? $value;
    }

    // Ultima protectie: nu mai afișam niciun ? ramas in coloana adresa.
    // Este mai curat vizual decat sa apara "CONSTAN?A" / "VOD?" in lista.
    $value = str_replace('?', '', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+,/u', ',', $value) ?? $value;
    $value = preg_replace('/,\s*,+/u', ',', $value) ?? $value;

    return trim($value);
}

function c_h_address($value): string {
    return htmlspecialchars(c_clean_address_display($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function c_h($value): string {
    return htmlspecialchars(c_fix_encoding_issues($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function c_h_raw($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function c_fix_encoding_issues_recursive($value) {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = c_fix_encoding_issues_recursive($item);
        }
        return $value;
    }

    if (is_string($value)) {
        return c_fix_encoding_issues($value);
    }

    return $value;
}

function c_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function c_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['total'] ?? 0) > 0;
}

function c_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!c_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Nu blocam pagina dacă ALTER nu poate rula.
        }
    }
}

function c_clean_text(?string $value): string {
    $value = c_fix_encoding_issues(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);

    return trim((string)$value);
}

function c_clean_phone(?string $value): string {
    return trim((string)$value);
}

function c_clean_fiscal_code(?string $value): string {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^0-9A-Z]/', '', $value);
    $value = preg_replace('/^RO/', '', (string)$value);

    return trim((string)$value);
}

function c_decimal_nullable($value): ?float {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace([' ', ','], ['', '.'], $value);
    return is_numeric($value) ? (float)$value : null;
}

function c_clean_surface_unit($value): string {
    $value = trim((string)$value);
    return in_array($value, ['mp', 'ml', 'buc'], true) ? $value : 'mp';
}

function c_client_type_label(string $type): string {
    return $type === 'individual' ? 'PF' : 'PJ';
}

function c_client_address(array $client): string {
    $billingAddress = c_build_billing_address($client);
    if ($billingAddress !== '') {
        return $billingAddress;
    }

    return trim((string)($client['registered_address'] ?? '')) ?: trim((string)($client['address'] ?? ''));
}

function c_build_billing_address(array $parts): string {
    $country = trim((string)($parts['billing_country'] ?? ''));
    $county = trim((string)($parts['billing_county'] ?? ''));
    $city = trim((string)($parts['billing_city'] ?? ''));
    $sector = trim((string)($parts['billing_sector'] ?? ''));
    $line = trim((string)($parts['billing_address_line'] ?? ''));
    $postal = trim((string)($parts['billing_postal_code'] ?? ''));

    $location = trim(implode(', ', array_filter([$county, $city, $sector], static fn($v) => $v !== '')));
    $address = trim(implode(', ', array_filter([$line, $location, $country], static fn($v) => $v !== '')));
    if ($postal !== '') {
        $address .= ($address !== '' ? ', ' : '') . 'CP ' . $postal;
    }

    return $address;
}

function c_client_contact_person(array $client): string {
    $type = (string)($client['client_type'] ?? 'company');
    $name = trim((string)($client['name'] ?? ''));
    $rep = trim((string)($client['legal_representative_name'] ?? ''));

    if ($type === 'individual') {
        return $name;
    }

    return $rep !== '' ? $rep : $name;
}

function c_client_status_class($status, int $active): string {
    if ($active !== 1) {
        return 'inactive';
    }

    $status = strtolower(trim((string)$status));

    if (in_array($status, ['season', 'seasonal', 'sezonier'], true)) {
        return 'season';
    }

    return 'active';
}

function c_client_status_label($status, int $active): string {
    if ($active !== 1) {
        return 'Inactiv';
    }

    $status = strtolower(trim((string)$status));

    if (in_array($status, ['season', 'seasonal', 'sezonier'], true)) {
        return 'Sezonier';
    }

    return 'Activ';
}

