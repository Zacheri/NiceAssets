-- ATR Inventory — BASE seed (idempotent, always loaded by the installer)
-- Creates roles, default logins, and app settings only.
--
-- Sample/demo inventory (departments, sites, locations, categories, assets,
-- work orders, demo audit trail) is NOT loaded here — see db/seed-demo.sql,
-- which the installer loads only when explicitly requested (ATR_SEED=1).
--
-- Default logins: admin / Admin1234, manager / Manager1234, viewer / Viewer1234
-- (change or remove these under Admin → Users after first login)

INSERT INTO roles (name, description) VALUES
    ('admin', 'Full access to all assets, locations, users, and configuration'),
    ('department_manager', 'Modify and view assets within assigned department only'),
    ('viewer', 'View-only access to assigned department assets')
ON CONFLICT (name) DO NOTHING;

INSERT INTO users (username, password_hash, full_name, email, role_id, department_id) VALUES
    ('admin',   '$2y$12$iG5u8Q/ePSnJaGG/gR8AseG1EBQbOuyKGarangG.Y6nVM6YHCnl5e', 'System Administrator', 'admin@atr.local',    (SELECT id FROM roles WHERE name = 'admin'), NULL),
    ('manager', '$2y$12$YinJqYboP8ma8oJDjLpikecLaEoTkURXdHEr8rega6yzNIptHW8qW', 'Department Manager', 'manager@atr.local',  (SELECT id FROM roles WHERE name = 'department_manager'), NULL),
    ('viewer',  '$2y$12$Nl8R5I6lVTC649k/a.muU.Q1Js49o96PZyNMf9sD/kuaADa8AmPly', 'Viewer', 'viewer@atr.local',                (SELECT id FROM roles WHERE name = 'viewer'), NULL)
ON CONFLICT (username) DO NOTHING;

INSERT INTO settings (key, value) VALUES
    ('app_version', '1.0.0'),
    ('email_role_admin', '1'),
    ('email_role_department_manager', '1'),
    ('email_role_viewer', '0'),
    ('weekly_report_enabled', '1'),
    ('multi_asset_threshold', '2')
ON CONFLICT (key) DO NOTHING;
