<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserQuota;
use App\Services\AuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create
                            {email : The email address of the administrator}
                            {--name= : The display name of the administrator}
                            {--password= : The account password (generated if omitted)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new administrator user account';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address '{$email}'.");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email '{$email}' already exists.");

            return self::FAILURE;
        }

        $passwordOption = $this->option('password');
        $password = is_string($passwordOption) && trim($passwordOption) !== ''
            ? trim($passwordOption)
            : null;

        $generated = false;
        if ($password === null) {
            $password = Str::password(16);
            $generated = true;
        }

        $nameOption = $this->option('name');
        $name = is_string($nameOption) && trim($nameOption) !== ''
            ? trim($nameOption)
            : 'Administrator';

        /** @var User $user */
        $user = User::create([
            'name' => $name,
            'email' => $email,
            ...app(AuthService::class)->generateCredentials($password),
            'status' => 'active',
        ]);
        $user->email_verified_at = now();
        $user->save();

        /** @var Role $adminRole */
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole($adminRole);

        UserQuota::updateOrCreate(
            ['user_id' => $user->id],
            [
                'max_vms' => -1,
                'max_cpu_cores' => -1,
                'max_ram_mb' => -1,
                'max_storage_gb' => -1,
                'max_ips' => -1,
                'max_snapshots' => -1,
            ]
        );

        $this->info("Admin user '{$email}' created successfully.");

        if ($generated) {
            $this->line("Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
