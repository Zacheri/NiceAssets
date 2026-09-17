-- Nice Assets — DEMO/sample data (opt-in, evaluation only)
-- Loaded only when the installer is told to (NAIMS_SEED=1 or interactive "yes").
-- Requires the base seed (db/seed.sql: roles + users) to be loaded first.
-- Do NOT use this file for a production deployment.

INSERT INTO departments (name, description) VALUES
    ('IT', 'Information technology'),
    ('Operations', 'Operations and logistics'),
    ('Marketing', 'Marketing and communications'),
    ('Finance', 'Finance and accounting')
ON CONFLICT (name) DO NOTHING;

INSERT INTO sites (name, address) VALUES
    ('Main Office', '1 Corporate Way, Suite 100'),
    ('North Warehouse', '450 Industrial Pkwy')
ON CONFLICT (name) DO NOTHING;

INSERT INTO locations (site_id, name, code, description) VALUES
    ((SELECT id FROM sites WHERE name = 'Main Office'), 'IT Lab', 'HQ-ITLAB', 'Server and device lab'),
    ((SELECT id FROM sites WHERE name = 'Main Office'), 'Main Floor', 'HQ-MAIN', 'Open office area'),
    ((SELECT id FROM sites WHERE name = 'Main Office'), 'Break Room', 'HQ-BREAK', ''),
    ((SELECT id FROM sites WHERE name = 'Main Office'), 'Storage', 'HQ-STOR', 'Overflow storage'),
    ((SELECT id FROM sites WHERE name = 'North Warehouse'), 'Aisle 1', 'NW-A1', 'Electronics shelving'),
    ((SELECT id FROM sites WHERE name = 'North Warehouse'), 'Dock', 'NW-DOCK', 'Loading dock'),
    ((SELECT id FROM sites WHERE name = 'North Warehouse'), 'Office', 'NW-OFF', 'Warehouse office')
ON CONFLICT (site_id, name) DO NOTHING;

INSERT INTO persons (full_name, job_title, personal_email, work_email, phone, address, department_id, notes, is_terminated) VALUES
    ('Jordan Reyes', 'IT Manager', 'jordan.reyes@example.com', 'jreyes@corporate.com', '555-0141',
     '12 Maple Ave, Springfield', (SELECT id FROM departments WHERE name = 'IT'), 'Handles laptop refreshes.', false),
    ('Alex Kim', 'IT Support Technician', 'alex.kim@example.com', 'akim@corporate.com', '555-0142',
     '88 Birch Lane, Springfield', (SELECT id FROM departments WHERE name = 'IT'), 'Primary helpdesk contact.', false),
    ('Priya Nair', 'Marketing Coordinator', 'priya.nair@example.com', 'pnair@corporate.com', '555-0143',
     '5 Willow Ct, Riverside', (SELECT id FROM departments WHERE name = 'Marketing'), '', false),
    ('Sam Okafor', 'Operations Lead', 'sam.okafor@example.com', 'sokafor@corporate.com', '555-0144',
     '30 Cedar Blvd, Riverside', (SELECT id FROM departments WHERE name = 'Operations'), 'Runs the North Warehouse.', false),
    ('Chris Doyle', 'Warehouse Associate', 'chris.doyle@example.com', 'cdoyle@corporate.com', '555-0145',
     '77 Elm St, Springfield', (SELECT id FROM departments WHERE name = 'Operations'), 'Left the company 2026-06-30.', true)
ON CONFLICT (full_name) DO NOTHING;

INSERT INTO categories (name, low_stock_threshold, depreciation_alert_enabled) VALUES
    ('Electronics',   3,  true),
    ('Furniture',     3,  true),
    ('Rewards Cards', 20, false),
    ('Accessories',   5,  true),
    ('Vehicles',      1,  true)
ON CONFLICT (name) DO NOTHING;

INSERT INTO work_orders (wo_number, asset_id, summary, details, status, created_by) VALUES
    ('WO-20260818-001', NULL, 'Keyboard not responding; USB replacement needed', 'Reported by Ops team. Test with known-good cable first.', 'open', (SELECT id FROM users WHERE username = 'manager'))
ON CONFLICT (wo_number) DO NOTHING;

-- Columns (27): tag, serial, model, brand, category, department, site, location,
--   assigned_person, purchase_date, cost, warranty, due, status, reason, sub_qty, work_order,
--   disp_loc, disp_date, disp_remaining, sold_to, sold_price, sold_date,
--   donated_to, donated_value, donated_date, created_by
INSERT INTO assets (asset_tag, serial_number, model_number, brand, category_id, department_id, site_id, location_id,
                    assigned_to_person_id, purchase_date, purchase_cost, warranty_expiration, due_date, status, status_reason,
                    sub_quantity, work_order_id, disposal_location, disposal_date, disposal_remaining_cost,
                    sold_to, sold_price, sold_date, donated_to, donated_value, donated_date, created_by) VALUES
    -- Available stock (Electronics available count kept low to trigger low-stock alert)
    ('IT-1001', 'SN-LAT-88231', 'Latitude 7490', 'Dell',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-ITLAB'),
        NULL, '2025-03-15', 1450.00, '2028-03-15', NULL, 'available', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('IT-1002', 'SN-MBP-44710', 'MacBook Pro 14"', 'Apple',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-ITLAB'),
        NULL, '2024-11-02', 1999.00, '2026-09-10', NULL, 'available', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('IT-1003', 'SN-MON-20931', 'UltraSharp U2723QE', 'Dell',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'North Warehouse'), (SELECT id FROM locations WHERE code = 'NW-A1'),
        NULL, '2023-06-20', 649.00, '2026-10-20', NULL, 'available', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('IT-1004', 'SN-CHR-10294', 'Chromebook 545', 'Lenovo',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-STOR'),
        NULL, '2026-01-10', 520.00, '2029-01-10', NULL, 'available', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    -- Checked out
    ('IT-1010', 'SN-MBP-44991', 'MacBook Pro 14"', 'Apple',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-MAIN'),
        (SELECT id FROM persons WHERE full_name = 'Jordan Reyes'), '2025-07-01', 1999.00, '2027-07-01', '2026-08-10', 'checked_out', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('IT-1011', 'SN-CHR-10502', 'Chromebook 545', 'Lenovo',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-MAIN'),
        (SELECT id FROM persons WHERE full_name = 'Alex Kim'), '2025-09-12', 520.00, '2028-09-12', NULL, 'checked_out', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'manager')),
    ('IT-1012', 'SN-CHR-10511', 'Chromebook 545', 'Lenovo',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-MAIN'),
        (SELECT id FROM persons WHERE full_name = 'Alex Kim'), '2025-09-12', 520.00, '2028-09-12', NULL, 'checked_out', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'manager')),
    ('MK-2001', 'PRJ-9931', 'Collab', 'Cisco',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'Marketing'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-MAIN'),
        (SELECT id FROM persons WHERE full_name = 'Priya Nair'), '2024-05-05', 415.00, '2026-11-15', NULL, 'checked_out', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    -- In repair
    ('IT-1020', 'SN-KBD-77120', 'K380 Wireless', 'Logitech',
        (SELECT id FROM categories WHERE name = 'Accessories'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-ITLAB'),
        NULL, '2024-02-14', 99.99, '2026-02-14', NULL, 'in_repair', 'USB connection intermittent', 1,
        (SELECT id FROM work_orders WHERE wo_number = 'WO-20260818-001'),
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    -- Broken / lost
    ('IT-1030', 'SN-PRN-55210', 'LaserJet Pro M404', 'HP',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'North Warehouse'), (SELECT id FROM locations WHERE code = 'NW-DOCK'),
        (SELECT id FROM persons WHERE full_name = 'Jordan Reyes'), '2023-01-30', 379.00, '2025-01-30', NULL, 'broken', 'Fuser assembly failed; not economical to repair', 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('IT-1031', 'SN-TAB-30911', 'iPad 10th Gen', 'Apple',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-MAIN'),
        (SELECT id FROM persons WHERE full_name = 'Priya Nair'), '2024-10-01', 499.00, '2026-10-01', NULL, 'lost', 'Lost off-site; reported by employee', 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    -- Terminal states
    ('IT-1040', 'SN-OLT-11872', 'OptiPlex 7080', 'Dell',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-STOR'),
        NULL, '2017-08-22', 1200.00, '2020-08-22', NULL, 'disposed', 'End of life', 1, NULL,
        'TechWaste Recycling, Bay 4', '2026-07-15', 0.00, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('IT-1041', 'SN-MON-20544', 'UltraSharp U2720Q', 'Dell',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'North Warehouse'), (SELECT id FROM locations WHERE code = 'NW-A1'),
        NULL, '2020-04-18', 899.00, '2022-04-18', NULL, 'sold', 'Sold to surplus buyer', 1, NULL,
        NULL, NULL, NULL, 'Surplus Tech Ltd', 210.00, '2026-06-30', NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('IT-1042', 'SN-DSK-66020', 'Desk, Standing 48"', 'Herman Miller',
        (SELECT id FROM categories WHERE name = 'Furniture'), (SELECT id FROM departments WHERE name = 'Operations'),
        (SELECT id FROM sites WHERE name = 'North Warehouse'), (SELECT id FROM locations WHERE code = 'NW-A1'),
        NULL, '2019-05-01', 899.00, '2021-05-01', NULL, 'donated', 'Donated — fully depreciated', 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, 'Community Action Center', 150.00, '2026-05-12', (SELECT id FROM users WHERE username = 'admin')),
    -- Rewards cards (sub-quantity)
    ('RC-3001', 'RC-BATCH-7781', 'Gift Card $50', 'Rewards',
        (SELECT id FROM categories WHERE name = 'Rewards Cards'), (SELECT id FROM departments WHERE name = 'Marketing'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-STOR'),
        NULL, '2026-02-01', 1000.00, NULL, NULL, 'available', NULL, 25, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    -- Fully depreciated, still in service
    ('IT-1050', 'SN-OLT-10001', 'OptiPlex 7060', 'Dell',
        (SELECT id FROM categories WHERE name = 'Electronics'), (SELECT id FROM departments WHERE name = 'IT'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-MAIN'),
        (SELECT id FROM persons WHERE full_name = 'Sam Okafor'), '2019-03-11', 950.00, '2021-03-11', NULL, 'checked_out', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    -- Furniture available
    ('FU-4001', 'FUR-CHAIR-2201', 'Aeron Chair B', 'Herman Miller',
        (SELECT id FROM categories WHERE name = 'Furniture'), (SELECT id FROM departments WHERE name = 'Operations'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-BREAK'),
        NULL, '2024-08-19', 1395.00, '2029-08-19', NULL, 'available', NULL, 2, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
    ('FU-4002', 'FUR-CONF-0114', 'Conference Table 12ft', 'Steelcase',
        (SELECT id FROM categories WHERE name = 'Furniture'), (SELECT id FROM departments WHERE name = 'Operations'),
        (SELECT id FROM sites WHERE name = 'Main Office'), (SELECT id FROM locations WHERE code = 'HQ-BREAK'),
        NULL, '2023-12-01', 3200.00, '2028-12-01', NULL, 'available', NULL, 1, NULL,
        NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')
) ON CONFLICT (asset_tag) DO NOTHING;

-- Point the open work order at the in-repair asset
UPDATE work_orders SET asset_id = (SELECT id FROM assets WHERE asset_tag = 'IT-1020')
WHERE wo_number = 'WO-20260818-001' AND asset_id IS NULL;

-- Sample audit trail from the previous Monday-Friday (for weekly report demo)
INSERT INTO audit_log (user_id, username, action, entity, entity_id, details, ip, created_at)
SELECT u.id, u.username, a.action, 'asset', a.tag, a.details::jsonb, '127.0.0.1', a.ts::timestamptz
FROM users u
CROSS JOIN LATERAL (VALUES
    (u.id, 'admin',   'asset.check_out',   'IT-1010', '{"asset_tag":"IT-1010","to":"Jordan Reyes"}'::jsonb, '2026-08-10 09:12:00'),
    (u.id, 'admin',   'asset.transfer',    'IT-1011', '{"asset_tag":"IT-1011","from":"Sam Okafor","to":"Alex Kim"}'::jsonb, '2026-08-11 10:05:00'),
    (u.id, 'manager', 'asset.check_in',    'IT-1020', '{"asset_tag":"IT-1020"}'::jsonb, '2026-08-12 14:30:00'),
    (u.id, 'admin',   'asset.broken',      'IT-1030', '{"asset_tag":"IT-1030","to":"broken"}'::jsonb, '2026-08-13 08:45:00'),
    (u.id, 'admin',   'asset.create',      'IT-1004', '{"asset_tag":"IT-1004"}'::jsonb, '2026-08-13 11:20:00'),
    (u.id, 'admin',   'asset.dispose',     'IT-1040', '{"asset_tag":"IT-1040"}'::jsonb, '2026-08-14 16:02:00')
) AS a(uid, uname, action, tag, details, ts)
WHERE u.username = a.uname
  AND NOT EXISTS (
      SELECT 1 FROM audit_log x
      WHERE x.action = a.action AND x.entity_id = a.tag
        AND x.created_at = (a.ts::timestamptz)
  );
