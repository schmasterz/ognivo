#!/bin/bash
set -e

# Initialize audit and migration users in MySQL 8.4
# Note: Table-level grants are deferred to `php artisan audit:grants`
# because MySQL rejects table-level GRANT on tables that do not exist yet.
# Note: migration_user needs SELECT on mysql.user (minimal scope) for AuditGrantsCommand
# to check user existence and resolve host lists during strict mode isolation,
# without exposing other MySQL system tables.
mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE USER IF NOT EXISTS '${AUDIT_DB_USERNAME:-audit_writer}'@'%' IDENTIFIED BY '${AUDIT_DB_PASSWORD}';
    CREATE USER IF NOT EXISTS '${AUDIT_MAINT_DB_USERNAME:-audit_maintenance}'@'%' IDENTIFIED BY '${AUDIT_MAINT_DB_PASSWORD}';
    CREATE USER IF NOT EXISTS '${DB_MIGRATION_USERNAME:-migration_user}'@'%' IDENTIFIED BY '${DB_MIGRATION_PASSWORD}';
    GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE:-ognivo}\`.* TO '${DB_MIGRATION_USERNAME:-migration_user}'@'%' WITH GRANT OPTION;
    GRANT SELECT ON mysql.user TO '${DB_MIGRATION_USERNAME:-migration_user}'@'%';
    FLUSH PRIVILEGES;
EOSQL
