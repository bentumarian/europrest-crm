-- ============================================================
-- Migration SaaS Emma — Etapa 1: tabele platformă (multi-tenant)
-- ============================================================
-- IDEMPOTENT: poate fi rulat de mai multe ori fără efect.
-- NU modifică niciun tabel existent.
-- NU șterge date.
-- ============================================================
-- Status: REVIEW ONLY — NU rula pe producție fără backup mysqldump
--         proaspăt și fără aprobarea Bentu.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Tabela `tenants` — fiecare rând = o firmă-client SaaS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL,
        -- subdomeniu: 'europrest' → europrest.emma.ro
        -- doar a-z, 0-9, '-'; lungime 3-60; unic
    legal_name VARCHAR(200) NOT NULL,
        -- nume legal complet: 'EuroPrest SRL', 'SC Pest Control Cluj SRL'
    display_name VARCHAR(200) NOT NULL,
        -- nume scurt afișat în UI: 'EuroPrest', 'Pest Control Cluj'
    cui VARCHAR(20) NULL,
        -- CUI pentru tenant (opțional; folosit la signup pentru auto-fill ANAF)
    status VARCHAR(20) NOT NULL DEFAULT 'trial',
        -- valori permise: 'trial' | 'active' | 'past_due' | 'suspended' | 'cancelled'
        -- 'trial'      → conturul nou, până la trial_ends_at
        -- 'active'     → abonament plătit, funcțional
        -- 'past_due'   → plata a eșuat, grace period 7 zile
        -- 'suspended'  → suspendat, login blocat, datele păstrate 30 zile
        -- 'cancelled'  → anulat definitiv, doar admin platformă poate vedea
    plan_code VARCHAR(40) NOT NULL DEFAULT 'emma_pro',
    trial_ends_at TIMESTAMP NULL,
    subscription_started_at TIMESTAMP NULL,
    subscription_ends_at TIMESTAMP NULL,
    support_email VARCHAR(160) NULL,
        -- email de contact al firmei (pentru reset password, notificări billing)
    support_phone VARCHAR(40) NULL,
    primary_color VARCHAR(20) NULL,
        -- hex color pentru white-label v2 (NU folosit în v1)
    logo_path VARCHAR(255) NULL,
        -- path către logo custom (NU folosit în v1)
    notes TEXT NULL,
        -- note interne super-admin Emma
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenants_slug (slug),
    INDEX idx_tenants_status (status),
    INDEX idx_tenants_plan (plan_code),
    INDEX idx_tenants_trial_end (trial_ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 2. Tabela `user_tenant_membership` — relație n:n user ↔ tenant
-- ------------------------------------------------------------
-- Un user poate aparține mai multor tenants (utilizator multi-firmă).
-- Tabela `users` rămâne nemodificată în această etapă.
CREATE TABLE IF NOT EXISTS user_tenant_membership (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'admin',
        -- 'admin' | 'team' | 'viewer' (per tenant)
    is_default TINYINT(1) NOT NULL DEFAULT 0,
        -- 1 = la login pe app.emma.ro îl redirectăm aici by default
    invited_by INT NULL,
    invited_at TIMESTAMP NULL,
    accepted_at TIMESTAMP NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_membership (user_id, tenant_id),
    INDEX idx_membership_user (user_id, active),
    INDEX idx_membership_tenant (tenant_id, active),
    INDEX idx_membership_role (tenant_id, role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 3. Tabela `tenant_plans` — catalogul de planuri Emma
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenant_plans (
    code VARCHAR(40) PRIMARY KEY,
        -- ex: 'emma_pro', 'emma_basic' (v2), 'emma_enterprise' (v2)
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    monthly_price_ron DECIMAL(10,2) NOT NULL DEFAULT 0,
    yearly_price_ron DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_users INT NULL,
        -- NULL = nelimitat
    max_appointments_per_month INT NULL,
    includes_efactura TINYINT(1) NOT NULL DEFAULT 1,
    includes_sms TINYINT(1) NOT NULL DEFAULT 1,
    sms_quota_per_month INT NULL,
        -- NULL = nelimitat (sub limita SmartSMS sau provider)
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
        -- 0 = ascuns de pe pagina de pricing public (ex: enterprise custom)
    stripe_price_id_monthly VARCHAR(100) NULL,
    stripe_price_id_yearly VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed plan unic v1 (Emma Pro)
-- INSERT IGNORE = nu suprascrie dacă există deja
INSERT IGNORE INTO tenant_plans
    (code, name, description, monthly_price_ron, yearly_price_ron,
     max_users, includes_efactura, includes_sms, sort_order, is_active, is_public)
VALUES
    ('emma_pro',
     'Emma Pro',
     'Plan unic Emma: utilizatori nelimitați, e-Factura inclusă, SMS inclus.',
     49.00, 499.00,
     NULL, 1, 1, 1, 1, 1);


-- ------------------------------------------------------------
-- 4. Tabela `tenant_subscriptions` — istoricul abonamentelor
-- ------------------------------------------------------------
-- Un tenant poate avea mai multe abonamente în timp (upgrade, cancel + resub).
CREATE TABLE IF NOT EXISTS tenant_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_code VARCHAR(40) NOT NULL,
    billing_cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
        -- 'monthly' | 'yearly'
    status VARCHAR(20) NOT NULL DEFAULT 'active',
        -- 'active' | 'past_due' | 'cancelled' | 'incomplete'
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    current_period_start TIMESTAMP NULL,
    current_period_end TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    cancel_reason VARCHAR(255) NULL,
    stripe_subscription_id VARCHAR(100) NULL,
    stripe_customer_id VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subs_tenant (tenant_id, status),
    INDEX idx_subs_period_end (current_period_end),
    INDEX idx_subs_stripe (stripe_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 5. Tabela `platform_settings` — config Emma global (NU per tenant)
-- ------------------------------------------------------------
-- Echivalentul `app_settings` dar la nivel de platformă.
-- Pentru setări per-tenant, folosim în continuare `app_settings`
-- cu prefix de key: 'tenant.{id}.smartbill.api_key' (vezi etapa 7 din plan).
CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    description VARCHAR(255) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed valori platformă inițiale
INSERT IGNORE INTO platform_settings (setting_key, setting_value, description) VALUES
    ('app.name',           'Emma',                  'Numele aplicației afișat în UI'),
    ('app.domain',         'emma.ro',               'Domeniul principal'),
    ('app.app_origin',     'https://app.emma.ro',   'Origin pentru CORS și OAuth callbacks'),
    ('app.support_email',  'office@emma.ro',        'Email contact afișat'),
    ('app.signup_enabled', '1',                     'Permite signup public pe emma.ro'),
    ('app.trial_days',     '14',                    'Zile trial gratuit pentru tenants noi'),
    ('app.suspend_grace_days', '7',                 'Zile între past_due și suspended'),
    ('app.delete_after_days',  '30',                'Zile între suspended și soft delete date');


-- ------------------------------------------------------------
-- 6. EuroPrest = tenant_id = 1 (seed)
-- ------------------------------------------------------------
-- IMPORTANT: rulează acest INSERT NUMAI pe DB-ul EuroPrest (cel migrat).
-- Pe un VPS Emma cu DB nouă, sari peste acest INSERT.
INSERT IGNORE INTO tenants
    (id, slug, legal_name, display_name, status, plan_code,
     subscription_started_at, support_email)
VALUES
    (1, 'europrest', 'EuroPrest SRL', 'EuroPrest', 'active', 'emma_pro',
     CURRENT_TIMESTAMP, 'office@pestzone.ro');

-- Seed subscription EuroPrest (perioadă lungă, "grandfathered")
INSERT IGNORE INTO tenant_subscriptions
    (tenant_id, plan_code, billing_cycle, status, started_at,
     current_period_start, current_period_end)
VALUES
    (1, 'emma_pro', 'yearly', 'active', CURRENT_TIMESTAMP,
     CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 YEAR));


-- ============================================================
-- ROLLBACK (pentru referință — NU rula automat)
-- ============================================================
-- DROP TABLE IF EXISTS tenant_subscriptions;
-- DROP TABLE IF EXISTS tenant_plans;
-- DROP TABLE IF EXISTS user_tenant_membership;
-- DROP TABLE IF EXISTS platform_settings;
-- DROP TABLE IF EXISTS tenants;
-- ============================================================
