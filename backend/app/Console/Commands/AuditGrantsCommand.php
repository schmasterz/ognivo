<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Cast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AuditGrantsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:grants {--force : Force execution without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently configure table-level grants for audit users and enforce strict permissions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = 'migration';
        $connection = DB::connection($connectionName);

        if ($connection->getDriverName() !== 'mysql') {
            $this->info("Skipping audit:grants on non-MySQL driver ({$connection->getDriverName()}).");

            return Command::SUCCESS;
        }

        $dbName = $connection->getDatabaseName();
        $auditWriter = Cast::string(config('audit.database.writer.username', 'audit_writer'), 'audit_writer');
        $auditWriterPassword = Cast::string(config('audit.database.writer.password', ''));
        $auditMaint = Cast::string(config('audit.database.maintenance.username', 'audit_maintenance'), 'audit_maintenance');
        $auditMaintPassword = Cast::string(config('audit.database.maintenance.password', ''));

        $configuredMysqlUser = Cast::string(config('database.connections.mysql.username', 'ognivo_user'), 'ognivo_user');
        $mainUser = ($configuredMysqlUser === '' || $configuredMysqlUser === 'root') ? 'ognivo_user' : $configuredMysqlUser;
        $strictGrants = Cast::bool(config('audit.strict_grants', false));

        $this->info("Applying audit grants on schema [{$dbName}] via migration connection...");

        // 1. Ensure users exist
        try {
            if ($auditWriterPassword !== '') {
                $connection->statement("CREATE USER IF NOT EXISTS '{$auditWriter}'@'%' IDENTIFIED BY '{$auditWriterPassword}'");
            }
            if ($auditMaintPassword !== '') {
                $connection->statement("CREATE USER IF NOT EXISTS '{$auditMaint}'@'%' IDENTIFIED BY '{$auditMaintPassword}'");
            }
        } catch (Throwable $e) {
            $this->comment('User creation note: '.$e->getMessage());
        }

        // 1b. Fail fast with a clear message if audit users are missing (MySQL 8 cannot create users via GRANT)
        foreach ([$auditWriter => 'AUDIT_DB_PASSWORD', $auditMaint => 'AUDIT_MAINT_DB_PASSWORD'] as $user => $passwordEnv) {
            try {
                $exists = $connection->select('SELECT 1 FROM mysql.user WHERE User = ? LIMIT 1', [$user]) !== [];
            } catch (Throwable) {
                $exists = true; // Cannot inspect mysql.user; let GRANT report the real error
            }
            if (! $exists) {
                $this->error("MySQL user '{$user}' does not exist. Set {$passwordEnv} so audit:grants can create it, or create it manually (see docker/mysql-init/01-create-users.sh).");

                return Command::FAILURE;
            }
        }

        // 2. Grant table-level permissions to audit_writer (INSERT, SELECT on audit_logs and audit_seals)
        $connection->statement("GRANT SELECT, INSERT ON `{$dbName}`.`audit_logs` TO '{$auditWriter}'@'%'");
        $connection->statement("GRANT SELECT, INSERT ON `{$dbName}`.`audit_seals` TO '{$auditWriter}'@'%'");
        $this->info("Granted SELECT, INSERT on audit tables to [{$auditWriter}].");

        // 3. Grant table-level permissions to audit_maintenance (SELECT, UPDATE, DELETE on audit_logs; SELECT, UPDATE on audit_seals)
        $connection->statement("GRANT SELECT, UPDATE, DELETE ON `{$dbName}`.`audit_logs` TO '{$auditMaint}'@'%'");
        $connection->statement("GRANT SELECT, UPDATE ON `{$dbName}`.`audit_seals` TO '{$auditMaint}'@'%'");
        $this->info("Granted maintenance permissions on audit tables to [{$auditMaint}].");

        // 4. In strict mode, enforce main user isolation (only SELECT, INSERT on audit tables; no UPDATE or DELETE)
        if ($strictGrants) {
            $this->info("Enforcing strict mode (AUDIT_STRICT_GRANTS=true) for main user [{$mainUser}]...");

            $hosts = ['%'];
            try {
                /** @var array<int, object> $userHosts */
                $userHosts = $connection->select("SELECT DISTINCT Host FROM mysql.user WHERE User = '{$mainUser}'");
                if (! empty($userHosts)) {
                    $hosts = [];
                    foreach ($userHosts as $r) {
                        if (isset($r->Host)) {
                            $hosts[] = Cast::string($r->Host);
                        }
                    }
                    if (empty($hosts)) {
                        $hosts = ['%'];
                    }
                }
            } catch (Throwable) {
                // If mysql.user is not accessible, default to '%'
            }

            // Retrieve all tables in the database
            /** @var array<int, object> $tablesResult */
            $tablesResult = $connection->select("SHOW TABLES FROM `{$dbName}`");
            $tables = [];
            foreach ($tablesResult as $row) {
                $rowArray = array_values((array) $row);
                if (isset($rowArray[0])) {
                    $tables[] = Cast::string($rowArray[0]);
                }
            }

            foreach ($hosts as $host) {
                try {
                    // Check if user has database-level ALL PRIVILEGES (accounting for MySQL underscore wildcard escaping)
                    /** @var array<int, object> $grants */
                    $grants = $connection->select("SHOW GRANTS FOR '{$mainUser}'@'{$host}'");
                    $escapedDb = addcslashes($dbName, '_%');
                    $hasDbLevelGrant = false;
                    foreach ($grants as $g) {
                        $grantArr = array_values((array) $g);
                        $grantStr = isset($grantArr[0]) ? Cast::string($grantArr[0]) : '';
                        if (str_contains($grantStr, "ON `{$dbName}`.*") || str_contains($grantStr, "ON `{$escapedDb}`.*")) {
                            $hasDbLevelGrant = true;
                            break;
                        }
                    }

                    if ($hasDbLevelGrant) {
                        try {
                            $connection->statement("REVOKE ALL PRIVILEGES ON `{$dbName}`.* FROM '{$mainUser}'@'{$host}'");
                        } catch (Throwable) {
                        }
                        try {
                            $connection->statement("REVOKE ALL PRIVILEGES ON `{$escapedDb}`.* FROM '{$mainUser}'@'{$host}'");
                        } catch (Throwable) {
                        }

                        // Re-grant all privileges on non-audit tables
                        foreach ($tables as $table) {
                            if ($table !== 'audit_logs' && $table !== 'audit_seals') {
                                $connection->statement("GRANT ALL PRIVILEGES ON `{$dbName}`.`{$table}` TO '{$mainUser}'@'{$host}'");
                            }
                        }
                    } else {
                        // Ensure all non-audit tables are accessible
                        foreach ($tables as $table) {
                            if ($table !== 'audit_logs' && $table !== 'audit_seals') {
                                try {
                                    $connection->statement("GRANT ALL PRIVILEGES ON `{$dbName}`.`{$table}` TO '{$mainUser}'@'{$host}'");
                                } catch (Throwable) {
                                    // Ignore if already granted
                                }
                            }
                        }
                    }

                    // Revoke UPDATE, DELETE on audit tables and ensure SELECT, INSERT only
                    foreach (['audit_logs', 'audit_seals'] as $auditTable) {
                        try {
                            $connection->statement("REVOKE UPDATE, DELETE ON `{$dbName}`.`{$auditTable}` FROM '{$mainUser}'@'{$host}'");
                        } catch (Throwable) {
                            // Ignore if grant was not explicitly present
                        }
                        $connection->statement("GRANT SELECT, INSERT ON `{$dbName}`.`{$auditTable}` TO '{$mainUser}'@'{$host}'");
                    }
                } catch (Throwable $e) {
                    $this->warn("Could not adjust grants for '{$mainUser}'@'{$host}': ".$e->getMessage());
                }
            }
            $this->info('Strict mode grants configured: main user cannot UPDATE or DELETE audit tables.');
        } else {
            $this->info("Strict mode is disabled (AUDIT_STRICT_GRANTS=false): main user [{$mainUser}] retains standard privileges.");
        }

        try {
            $connection->statement('FLUSH PRIVILEGES');
        } catch (Throwable) {
            // FLUSH PRIVILEGES requires RELOAD privilege and is not needed for GRANT/REVOKE DDL
        }
        $this->info('Audit grants applied successfully.');

        return Command::SUCCESS;
    }
}
