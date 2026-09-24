-- Nice Assets — PostgreSQL schema
-- Idempotent: safe to re-run on an empty or existing database (CREATE IF NOT EXISTS).

BEGIN;

CREATE TABLE IF NOT EXISTS roles (
    id            serial PRIMARY KEY,
    name          text NOT NULL UNIQUE,
    description   text,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS departments (
    id            serial PRIMARY KEY,
    name          text NOT NULL UNIQUE,
    description   text,
    is_active     boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS sites (
    id            serial PRIMARY KEY,
    name          text NOT NULL UNIQUE,
    address       text,
    is_active     boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS locations (
    id            serial PRIMARY KEY,
    site_id       integer NOT NULL REFERENCES sites(id) ON DELETE RESTRICT,
    name          text NOT NULL,
    code          text,
    description   text,
    is_active     boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (site_id, name)
);

CREATE TABLE IF NOT EXISTS users (
    id                  serial PRIMARY KEY,
    username            text NOT NULL UNIQUE,
    password_hash       text NOT NULL,
    full_name           text NOT NULL,
    email               text NOT NULL DEFAULT '',
    role_id             integer NOT NULL REFERENCES roles(id) ON DELETE RESTRICT,
    department_id       integer REFERENCES departments(id) ON DELETE SET NULL,
    is_active           boolean NOT NULL DEFAULT true,
    login_failures      integer NOT NULL DEFAULT 0,
    locked_until        timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS persons (
    id            serial PRIMARY KEY,
    full_name     text NOT NULL UNIQUE,
    job_title     text NOT NULL DEFAULT '',
    personal_email text NOT NULL DEFAULT '',
    work_email    text NOT NULL DEFAULT '',
    phone         text NOT NULL DEFAULT '',
    address       text NOT NULL DEFAULT '',
    department_id integer REFERENCES departments(id) ON DELETE SET NULL,
    notes         text NOT NULL DEFAULT '',
    is_terminated boolean NOT NULL DEFAULT false,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_persons_department ON persons (department_id);
CREATE INDEX IF NOT EXISTS idx_persons_terminated ON persons (is_terminated);

CREATE TABLE IF NOT EXISTS categories (
    id                          serial PRIMARY KEY,
    name                        text NOT NULL UNIQUE,
    low_stock_threshold         integer NOT NULL DEFAULT 5,
    depreciation_alert_enabled  boolean NOT NULL DEFAULT true,
    is_active                   boolean NOT NULL DEFAULT true,
    created_at                  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS work_orders (
    id            serial PRIMARY KEY,
    wo_number     text NOT NULL UNIQUE,
    asset_id      integer,
    summary       text NOT NULL,
    details       text,
    status        text NOT NULL DEFAULT 'open'
                  CHECK (status IN ('open', 'in_progress', 'completed')),
    created_by    integer REFERENCES users(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    completed_at  timestamptz
);

CREATE TABLE IF NOT EXISTS assets (
    id                          bigserial PRIMARY KEY,
    asset_tag                   text NOT NULL UNIQUE,
    serial_number               text,
    model_number                text,
    brand                       text,
    category_id                 integer REFERENCES categories(id) ON DELETE SET NULL,
    department_id               integer REFERENCES departments(id) ON DELETE SET NULL,
    site_id                     integer REFERENCES sites(id) ON DELETE SET NULL,
    location_id                 integer REFERENCES locations(id) ON DELETE SET NULL,
    assigned_to_person_id       integer REFERENCES persons(id) ON DELETE SET NULL,
    assigned_to_department_id   integer REFERENCES departments(id) ON DELETE SET NULL,
    purchase_date               date,
    purchase_cost               numeric(14,2) NOT NULL DEFAULT 0,
    warranty_expiration         date,
    due_date                    date,
    status                      text NOT NULL DEFAULT 'available'
                                CHECK (status IN ('available','checked_out','in_repair','broken','lost','disposed','sold','donated')),
    status_reason               text,
    sub_quantity                integer NOT NULL DEFAULT 1,
    work_order_id               integer REFERENCES work_orders(id) ON DELETE SET NULL,
    disposal_location           text,
    disposal_date               date,
    disposal_remaining_cost     numeric(14,2),
    sold_to                     text,
    sold_price                  numeric(14,2),
    sold_date                   date,
    donated_to                  text,
    donated_value               numeric(14,2),
    donated_date                date,
    created_by                  integer REFERENCES users(id) ON DELETE SET NULL,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_assets_serial        ON assets (serial_number);
CREATE INDEX IF NOT EXISTS idx_assets_category      ON assets (category_id);
CREATE INDEX IF NOT EXISTS idx_assets_department    ON assets (department_id);
CREATE INDEX IF NOT EXISTS idx_assets_site          ON assets (site_id);
CREATE INDEX IF NOT EXISTS idx_assets_location      ON assets (location_id);
CREATE INDEX IF NOT EXISTS idx_assets_status        ON assets (status);
CREATE INDEX IF NOT EXISTS idx_assets_purchase_date ON assets (purchase_date);
CREATE INDEX IF NOT EXISTS idx_assets_warranty      ON assets (warranty_expiration);
CREATE INDEX IF NOT EXISTS idx_assets_created_at    ON assets (created_at);

-- Migration v1.4: asset description (CSV import).
ALTER TABLE assets ADD COLUMN IF NOT EXISTS description text;

ALTER TABLE assets DROP COLUMN IF EXISTS search_vector;
ALTER TABLE assets ADD COLUMN IF NOT EXISTS search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(asset_tag, '')),       'A') ||
        setweight(to_tsvector('english', coalesce(serial_number, '')),   'B') ||
        setweight(to_tsvector('english', coalesce(model_number, '')),    'B') ||
        setweight(to_tsvector('english', coalesce(brand, '')),           'C') ||
        setweight(to_tsvector('english', coalesce(description, '')),     'C') ||
        setweight(to_tsvector('english', coalesce(status_reason, '')),   'C')
    ) STORED;

CREATE INDEX IF NOT EXISTS idx_assets_fts ON assets USING gin (search_vector);

-- Migration v1.1: check-out targets are employees (persons), not login accounts.
-- No-op on fresh installs (created above with assigned_to_person_id already);
-- migrates databases that still carry the old assigned_to_user_id column.
DROP INDEX IF EXISTS idx_assets_user;
ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_assigned_to_user_id_fkey;
ALTER TABLE assets DROP COLUMN IF EXISTS assigned_to_user_id;
ALTER TABLE assets ADD COLUMN IF NOT EXISTS assigned_to_person_id integer REFERENCES persons(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_assets_person ON assets (assigned_to_person_id);

CREATE TABLE IF NOT EXISTS photos (
    id            serial PRIMARY KEY,
    filename      text NOT NULL UNIQUE,
    original_name text NOT NULL,
    variety       text NOT NULL DEFAULT '',
    mime          text NOT NULL DEFAULT 'image/jpeg',
    size          integer NOT NULL DEFAULT 0,
    created_by    integer REFERENCES users(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS asset_photos (
    asset_id      bigint NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    photo_id      integer NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
    position      integer NOT NULL DEFAULT 0,
    is_thumbnail  boolean NOT NULL DEFAULT false,
    added_at      timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (asset_id, photo_id)
);

CREATE INDEX IF NOT EXISTS idx_asset_photos_photo ON asset_photos (photo_id);

-- Migration v1.2: photo kinds (asset vs portrait) and person portraits.
-- IF NOT EXISTS keeps this safe to re-run on every boot (fresh or existing DB).
ALTER TABLE photos ADD COLUMN IF NOT EXISTS kind text NOT NULL DEFAULT 'asset';
ALTER TABLE persons ADD COLUMN IF NOT EXISTS portrait_photo_id integer REFERENCES photos(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_persons_portrait ON persons (portrait_photo_id);

-- Migration v1.3: per-user theme override (JSON: {"preset":"...","colors":{...}}).
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme text NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS audit_log (
    id            bigserial PRIMARY KEY,
    user_id       integer REFERENCES users(id) ON DELETE SET NULL,
    username      text NOT NULL DEFAULT 'system',
    action        text NOT NULL,
    entity        text NOT NULL DEFAULT '',
    entity_id     text NOT NULL DEFAULT '',
    details       jsonb NOT NULL DEFAULT '{}'::jsonb,
    ip            text,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_audit_entity   ON audit_log (entity, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_user     ON audit_log (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action   ON audit_log (action);
CREATE INDEX IF NOT EXISTS idx_audit_created  ON audit_log (created_at);

CREATE TABLE IF NOT EXISTS settings (
    key           text PRIMARY KEY,
    value         text NOT NULL DEFAULT '',
    updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS alert_log (
    id            bigserial PRIMARY KEY,
    dedupe_key    text NOT NULL UNIQUE,
    type          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_alert_log_created ON alert_log (created_at);

CREATE TABLE IF NOT EXISTS report_runs (
    id            serial PRIMARY KEY,
    name          text NOT NULL,
    report_type   text NOT NULL,
    params        jsonb NOT NULL DEFAULT '{}'::jsonb,
    generated_by  integer REFERENCES users(id) ON DELETE SET NULL,
    generated_at  timestamptz NOT NULL DEFAULT now(),
    version       integer NOT NULL DEFAULT 1,
    file_pdf      text,
    file_excel    text,
    row_count     integer NOT NULL DEFAULT 0,
    summary       jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_report_runs_type ON report_runs (report_type, generated_at);

CREATE TABLE IF NOT EXISTS user_prefs (
    user_id       integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key           text NOT NULL,
    value         text NOT NULL DEFAULT '',
    PRIMARY KEY (user_id, key)
);

COMMIT;
