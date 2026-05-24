<?php
/**
 * revenue_lib.php
 * --------------------------------------------------------------------------
 * Helper centralizat pentru categoria veniturilor (linie de business).
 *
 * Categoriile sunt valori scurte, persistate pe:
 *   - services.revenue_category          (sursa de adevăr la creare item)
 *   - billing_items.revenue_category     (snapshot la moment facturare)
 *   - smartbill_invoices.revenue_category (override manual pe factură)
 *
 * Toate funcțiile sunt idempotente și sigure de apelat repetat.
 */

if (!function_exists('pz_revenue_categories')) {
    /**
     * Returnează [code => ['label' => string, 'color' => '#hex', 'bg' => '#hex']]
     * Ordinea contează (e folosită și în UI: dashboard, filtre).
     */
    function pz_revenue_categories(): array
    {
        return [
            'ddd' => [
                'label'  => 'DDD',
                'color'  => '#0F6E56',
                'bg'     => '#ECFDF5',
                'border' => '#A7F3D0',
            ],
            'ignifugari' => [
                'label'  => 'Ignifugări',
                'color'  => '#9A3412',
                'bg'     => '#FFF7ED',
                'border' => '#FED7AA',
            ],
            'chirii' => [
                'label'  => 'Chirii',
                'color'  => '#1E40AF',
                'bg'     => '#EFF6FF',
                'border' => '#BFDBFE',
            ],
            'altele' => [
                'label'  => 'Altele',
                'color'  => '#475569',
                'bg'     => '#F8FAFC',
                'border' => '#E2E8F0',
            ],
        ];
    }
}

if (!function_exists('pz_revenue_category_keys')) {
    function pz_revenue_category_keys(): array
    {
        return array_keys(pz_revenue_categories());
    }
}

if (!function_exists('pz_revenue_category_label')) {
    function pz_revenue_category_label(string $code): string
    {
        $cats = pz_revenue_categories();
        return $cats[$code]['label'] ?? $cats['altele']['label'];
    }
}

if (!function_exists('pz_revenue_category_normalize')) {
    /**
     * Normalizează input-ul utilizatorului. Returnează default 'ddd' dacă
     * codul nu e în lista permisă.
     */
    function pz_revenue_category_normalize(?string $code, string $default = 'ddd'): string
    {
        $code = strtolower(trim((string)$code));
        $allowed = pz_revenue_category_keys();
        return in_array($code, $allowed, true) ? $code : $default;
    }
}

if (!function_exists('pz_revenue_ensure_column')) {
    /**
     * Adaugă coloana revenue_category pe tabela indicată (dacă nu există).
     * Sigur de apelat la fiecare load de pagină. Statică în pdo lifecycle.
     */
    function pz_revenue_ensure_column(PDO $pdo, string $table, string $default = 'ddd'): void
    {
        static $applied = [];
        $cacheKey = $table . '|' . $default;
        if (isset($applied[$cacheKey])) {
            return;
        }
        $applied[$cacheKey] = true;

        try {
            $tblExists = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $tblExists->execute([$table]);
            if ((int)$tblExists->fetchColumn() === 0) {
                return;
            }

            $colExists = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = 'revenue_category'"
            );
            $colExists->execute([$table]);
            if ((int)$colExists->fetchColumn() > 0) {
                return;
            }

            $default = pz_revenue_category_normalize($default, 'ddd');
            $sql = "ALTER TABLE `{$table}`
                    ADD COLUMN `revenue_category` VARCHAR(20) NOT NULL DEFAULT '{$default}'";
            $pdo->exec($sql);

            // Index pe coloană pentru filtre rapide (best-effort).
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD INDEX idx_{$table}_revenue_category (revenue_category)");
            } catch (Throwable $e) { /* ignore - index poate exista deja */ }
        } catch (Throwable $e) {
            error_log('pz_revenue_ensure_column ' . $table . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('pz_revenue_render_badge')) {
    /**
     * Helper UI: render badge inline pentru afișare categorie.
     * Generează un span cu culori + label.
     */
    function pz_revenue_render_badge(string $code, array $opts = []): string
    {
        $code = pz_revenue_category_normalize($code, 'ddd');
        $cats = pz_revenue_categories();
        $cat = $cats[$code] ?? $cats['ddd'];

        $size = (string)($opts['size'] ?? 'sm'); // sm | md
        $padding = $size === 'md' ? '4px 10px' : '2px 8px';
        $fontSize = $size === 'md' ? '12px' : '11px';

        $style = sprintf(
            'display:inline-block;padding:%s;font-size:%s;font-weight:500;'
            . 'color:%s;background:%s;border:0.5px solid %s;border-radius:999px;'
            . 'line-height:1.4;letter-spacing:0.02em;',
            $padding, $fontSize, $cat['color'], $cat['bg'], $cat['border']
        );

        return '<span class="revenue-badge revenue-badge-' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '" style="' . $style . '">'
             . htmlspecialchars($cat['label'], ENT_QUOTES, 'UTF-8')
             . '</span>';
    }
}
