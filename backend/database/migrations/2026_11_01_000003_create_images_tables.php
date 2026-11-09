<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('source');
            $table->string('protocol')->default('simplestreams');
            $table->string('os_type')->default('linux');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('host_image', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->foreignId('image_id')->constrained('images')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['host_id', 'image_id']);
        });

        // Seed initial default allowed images
        $now = now();
        $defaultImages = [
            [
                'name' => 'ubuntu/24.04',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'images:ubuntu/24.04',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'ubuntu:24.04',
                'source' => 'https://cloud-images.ubuntu.com/releases',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'images:debian/12',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'debian:12',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'images:alpine/3.20',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'images:alpine/3.19',
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('images')->insertOrIgnore($defaultImages);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('host_image');
        Schema::dropIfExists('images');
    }
};
