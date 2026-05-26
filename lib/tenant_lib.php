<?php
/*
|--------------------------------------------------------------------------
| lib/tenant_lib.php — multi-tenancy helpers Emma
|--------------------------------------------------------------------------
| STATUS: DRAFT — NU este încă inclus în config.php.
|         Vezi PLAN_SAAS_EMMA.md §6 pentru integrare.
|
| Rol: identifică tenantul curent din subdomeniu și oferă scope
|      automat pentru toate query-urile de business.
|
| Reguli:
| - Dacă subdomeniul nu corespunde unui tenant valid → 404.
| - Dacă tenantul există dar e suspendat/cancelled → 403.
| - Helper-ele cache-uiesc rezultatele pe durata request-ului.
| - Acest fișier NU forțează autentificare; combinat cu require_login()
|   din config.php asigură: user autentificat + tenant valid + match.
|--------------------------------------------------------------------------
*/

if (!function_exists('pz_extract_subdomain')) {
    /**
     * Extrage subdomeniul din $_SERVER['HTTP_HOST'].
     * Returnează:
     *   - null pentru emma.ro / www.emma.ro (landing)
     *   - 'app' pentru app.emma.ro (login global)
     *   - 'admin' pentru admin.emma.ro (super-admin)
     *   - 'api' pentru api.emma.ro (rezervat v2)
     *   - slug-ul tenantului pentru {slug}.emma.ro
     *   - null pentru orice altceva (host invalid)
     */
    function pz_extract_subdomain(string $host): ?string
    {
        $host = strtolower(trim($host));
        // Strip port (ex: emma.ro:8080)
        $host = preg_replace('/:\d+$/', '', $host);

        // Landing
        if ($host === 'emma.ro' || $host === 'www.emma.ro') {
            return null;
        }

        // Subdomenii rezervate platformă
        $reserved = ['app', 'admin', 'api', 'static', 'cdn', 'mail'];
        foreach ($reserved as $r) {
            if ($host === $r . '.emma.ro') {
                return $r;
            }
        }

        // Subdomeniu tenant: {slug}.emma.ro
        // slug: a-z, 0-9, '-'; lungime 3-60; nu poate începe/termina cu '-'
        if (preg_match('/^([a-z0-9][a-z0-9-]{1,58}[a-z0-9])\.emma\.ro$/', $host, $m)) {
            return $m[1];
        }

        return null;
    }
}

if (!function_exists('pz_is_platform_host')) {
    /**
     * True dacă subdomeniul curent este rezervat platformei (app, admin, api).
     */
    function pz_is_platform_host(?string $sub = null): bool
    {
        $sub = $sub ?? pz_extract_subdomain($_SERVER['HTTP_HOST'] ?? '');
        return in_array($sub, ['app', 'admin', 'api', 'static', 'cdn', 'mail'], true);
    }
}

if (!function_exists('pz_current_tenant')) {
    /**
     * Returnează rândul din `tenants` corespunzător subdomeniului curent.
     * Cache pe durata request-ului. Returnează null dacă:
     *   - suntem pe landing (emma.ro)
     *   - suntem pe host rezervat platformă (app.emma.ro etc.)
     *   - subdomeniul nu există în DB
     */
    function pz_current_tenant(PDO $pdo): ?array
    {
        static $cache = '__unset__';
        if ($cache !== '__unset__') {
            return is_array($cache) ? $cache : null;
        }

        $sub = pz_extract_subdomain($_SERVER['HTTP_HOST'] ?? '');
        if (!$sub || pz_is_platform_host($sub)) {
            $cache = null;
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? LIMIT 1");
            $stmt->execute([$sub]);
            $row = $stmt->fetch();
            $cache = $row ?: null;
            return $cache;
        } catch (Throwable $e) {
            // Tabela `tenants` nu există încă (înainte de migrare).
            error_log('pz_current_tenant: ' . $e->getMessage());
            $cache = null;
            return null;
        }
    }
}

if (!function_exists('pz_current_tenant_id')) {
    function pz_current_tenant_id(PDO $pdo): ?int
    {
        $t = pz_current_tenant($pdo);
        return $t ? (int)$t['id'] : null;
    }
}

if (!function_exists('pz_require_tenant')) {
    /**
     * Apel la începutul fiecărei pagini de tenant (după require_login).
     * Eșuează cu 404 dacă subdomeniul nu corespunde unui tenant.
     * Eșuează cu 403 dacă tenantul este suspended/cancelled.
     */
    function pz_require_tenant(PDO $pdo): array
    {
        $t = pz_current_tenant($pdo);
        if (!$t) {
            http_response_code(404);
            header('Content-Type: text/html; charset=UTF-8');
            $supportEmail = pz_platform_setting($pdo, 'app.support_email', 'office@emma.ro');
            echo '<!DOCTYPE html><html lang="ro"><head><meta charset="UTF-8"><title>Cont inexistent — Emma</title></head><body style="font-family:system-ui;max-width:600px;margin:80px auto;padding:0 20px;color:#334155">';
            echo '<h1 style="color:#0F172A">Cont inexistent</h1>';
            echo '<p>Subdomeniul accesat nu corespunde unui cont Emma activ. Verifică linkul sau contactează <a href="mailto:' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '</a>.</p>';
            echo '<p><a href="https://emma.ro" style="color:#2563EB">← Înapoi la emma.ro</a></p>';
            echo '</body></html>';
            exit;
        }

        $allowedStatuses = ['trial', 'active', 'past_due'];
        if (!in_array($t['status'], $allowedStatuses, true)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            $supportEmail = pz_platform_setting($pdo, 'app.support_email', 'office@emma.ro');
            echo '<!DOCTYPE html><html lang="ro"><head><meta charset="UTF-8"><title>Cont suspendat — Emma</title></head><body style="font-family:system-ui;max-width:600px;margin:80px auto;padding:0 20px;color:#334155">';
            echo '<h1 style="color:#0F172A">Cont suspendat</h1>';
            echo '<p>Contul <strong>' . htmlspecialchars($t['display_name'], ENT_QUOTES, 'UTF-8') . '</strong> este suspendat. Contactează <a href="mailto:' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '</a> pentru reactivare.</p>';
            echo '</body></html>';
            exit;
        }

        return $t;
    }
}

if (!function_exists('pz_platform_setting')) {
    /**
     * Citește o cheie din `platform_settings`. Cache pe request.
     * Folosit pentru valori globale Emma (numele aplicației, email suport etc.).
     */
    function pz_platform_setting(PDO $pdo, string $key, ?string $default = null): ?string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $rows = $pdo->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll();
                foreach ($rows as $r) {
                    $cache[$r['setting_key']] = $r['setting_value'];
                }
            } catch (Throwable $e) {
                // Tabela încă nu există — folosește default-uri.
            }
        }
        return $cache[$key] ?? $default;
    }
}

if (!function_exists('pz_tenant_scope_sql')) {
    /**
     * Returnează clauza SQL gata de inserat: 'AND `alias`.tenant_id = :tenant_id'
     * (sau 'AND tenant_id = :tenant_id' dacă alias e gol).
     * Pentru folosire în query-uri compuse:
     *
     *   $sql = "SELECT * FROM clients WHERE id = :id " . pz_tenant_scope_sql();
     *   $stmt = $pdo->prepare($sql);
     *   $stmt->execute([':id' => $id, ':tenant_id' => CURRENT_TENANT_ID]);
     */
    function pz_tenant_scope_sql(string $alias = ''): string
    {
        $col = $alias ? "`{$alias}`.tenant_id" : "tenant_id";
        return " AND {$col} = :tenant_id ";
    }
}

if (!function_exists('pz_user_has_tenant_access')) {
    /**
     * Verifică dacă un user are acces la un tenant specific
     * (prin tabela user_tenant_membership).
     */
    function pz_user_has_tenant_access(PDO $pdo, int $userId, int $tenantId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM user_tenant_membership
                 WHERE user_id = ? AND tenant_id = ? AND active = 1"
            );
            $stmt->execute([$userId, $tenantId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('pz_user_has_tenant_access: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('pz_user_tenants')) {
    /**
     * Returnează lista de tenants la care un user are acces.
     * Folosit pe app.emma.ro (selector de tenant post-login).
     */
    function pz_user_tenants(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT t.id, t.slug, t.display_name, t.status, m.role, m.is_default
                   FROM user_tenant_membership m
                   JOIN tenants t ON t.id = m.tenant_id
                  WHERE m.user_id = ? AND m.active = 1
                  ORDER BY m.is_default DESC, t.display_name ASC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('pz_user_tenants: ' . $e->getMessage());
            return [];
        }
    }
}
