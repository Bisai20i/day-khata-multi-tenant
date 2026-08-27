-- MySQL credential roles for production deployment.
-- See mysql-credentials.md in this directory for the reasoning behind exactly these two roles
-- (not the three the general security-hardening doc describes — this rewrite has no trigger/view
-- DDL, so there is no third `tenant_ddl_owner` role to create).
--
-- Not run against anything yet: dev is SQLite. Run this once against a real MySQL server before the
-- first tenant is provisioned there, then set DB_PROVISIONER_USERNAME/DB_PROVISIONER_PASSWORD and
-- DB_USERNAME/DB_PASSWORD in .env to match (see mysql-credentials.md's "Not built yet" section for
-- what still has to be wired into application config before these grants actually take effect).
--
-- Replace 'change-me-provisioner' and 'change-me-runtime' with real generated secrets before running.

-- tenant_provisioner: used only by the tenant-provisioning job pipeline
-- (CreateDatabase / MigrateDatabase / SeedDatabase / CreateTenantFirstAdmin).
CREATE USER IF NOT EXISTS 'tenant_provisioner'@'%' IDENTIFIED BY 'change-me-provisioner';

-- CREATE/DROP DATABASE, scoped to the tenant-DB naming pattern only.
GRANT CREATE, DROP ON `tenant_%`.* TO 'tenant_provisioner'@'%';

-- Full schema DDL within any already-created tenant database, for migrations.
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON `tenant_%`.* TO 'tenant_provisioner'@'%';

-- Also needs ordinary DML on the tenant database it just created/migrated, to run the seeder and
-- create the tenant's first admin user.
GRANT SELECT, INSERT, UPDATE, DELETE ON `tenant_%`.* TO 'tenant_provisioner'@'%';

-- Runtime app DB user: used by the application itself for every ordinary per-request query.
-- DML + SELECT only, no DDL, no CREATE/DROP DATABASE — limits the blast radius of an
-- application-level SQLi/RCE to data manipulation, not schema or database destruction.
CREATE USER IF NOT EXISTS 'day_khata_app'@'%' IDENTIFIED BY 'change-me-runtime';

GRANT SELECT, INSERT, UPDATE, DELETE ON `tenant_%`.* TO 'day_khata_app'@'%';

-- The runtime user also needs full access to the central database (tenants/domains/platform_admins/
-- central sessions/cache/jobs tables) — it is not tenant-namespace-scoped like the two grants above.
-- Replace `day_khata_central` with the real central database name from .env (DB_DATABASE).
GRANT SELECT, INSERT, UPDATE, DELETE ON `day_khata_central`.* TO 'day_khata_app'@'%';

FLUSH PRIVILEGES;
