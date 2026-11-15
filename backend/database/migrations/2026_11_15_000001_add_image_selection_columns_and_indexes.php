<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hosts', 'auto_attach_images')) {
            Schema::table('hosts', function (Blueprint $table): void {
                $table->boolean('auto_attach_images')->default(true)->after('settings');
            });
        }

        if (! Schema::hasIndex('host_image', 'host_image_image_id_index')) {
            Schema::table('host_image', function (Blueprint $table): void {
                $table->index('image_id', 'host_image_image_id_index');
            });
        }

        if (! Schema::hasIndex('images', 'images_os_type_index')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->index('os_type', 'images_os_type_index');
            });
        }

        if (! Schema::hasIndex('images', 'images_protocol_index')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->index('protocol', 'images_protocol_index');
            });
        }

        if (! Schema::hasIndex('images', 'images_enabled_index')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->index('enabled', 'images_enabled_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('images', 'images_enabled_index')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->dropIndex('images_enabled_index');
            });
        }

        if (Schema::hasIndex('images', 'images_protocol_index')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->dropIndex('images_protocol_index');
            });
        }

        if (Schema::hasIndex('images', 'images_os_type_index')) {
            Schema::table('images', function (Blueprint $table): void {
                $table->dropIndex('images_os_type_index');
            });
        }

        if (Schema::hasIndex('host_image', 'host_image_image_id_index')) {
            try {
                Schema::table('host_image', function (Blueprint $table): void {
                    $table->dropIndex('host_image_image_id_index');
                });
            } catch (Throwable) {
                // Silently ignore if MySQL or storage engine requires this index for a foreign key
            }
        }

        if (Schema::hasColumn('hosts', 'auto_attach_images')) {
            Schema::table('hosts', function (Blueprint $table): void {
                $table->dropColumn('auto_attach_images');
            });
        }
    }
};
