-- MySQL 8.0.16+. Incremental migration for Extension E7 (Account lifecycle, Permission Audit Logs, Safe Catalog Deactivation).
-- Run ONCE after 001_inventory_access.sql. Back up the database first.

ALTER TABLE admins
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER full_name;

CREATE TABLE IF NOT EXISTS permission_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    target_type ENUM('user','role','user_roles','user_permissions','role_permissions') NOT NULL,
    target_id INT NOT NULL,
    target_label VARCHAR(150) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_perm_audit_created(created_at),
    FOREIGN KEY(actor_user_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE units
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER name;

ALTER TABLE warehouses
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER name;

ALTER TABLE warehouse_locations
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER bin;

ALTER TABLE product_lots
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER expires_on;

INSERT INTO schema_migrations(version) VALUES ('002_e7_access_and_catalog');
