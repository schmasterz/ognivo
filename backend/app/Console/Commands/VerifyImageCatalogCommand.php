<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Cast;
use App\Models\Image;
use App\Services\Hypervisor\RemoteImageValidatorService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class VerifyImageCatalogCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:verify-catalog {--skip-disabled : Skip disabled images in catalog} {--id= : Check only a specific image by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify allowlist image entries against their remote image servers and report status.';

    /**
     * Execute the console command.
     */
    public function handle(RemoteImageValidatorService $validator): int
    {
        $query = Image::query();

        $idOption = $this->option('id');
        if ($idOption !== null && $idOption !== '') {
            $query->where('id', Cast::int($idOption));
        }

        if ((bool) $this->option('skip-disabled')) {
            $query->where('enabled', true);
        }

        /** @var Collection<int, Image> $images */
        $images = $query->orderBy('id')->get();

        if ($images->isEmpty()) {
            $this->info('No images to verify.');

            return self::SUCCESS;
        }

        $failures = 0;
        $rows = [];

        foreach ($images as $image) {
            $result = $validator->validate($image->source, $image->name, $image->protocol, $image->os_type);

            if ($result->isValid) {
                $status = '<info>VALID</info>';
                $details = implode(', ', $result->architectures);
            } else {
                $status = '<error>INVALID</error>';
                $details = $result->error ?? 'Validation failed.';
                $failures++;
            }

            $rows[] = [
                $image->id,
                $image->alias,
                $image->name,
                $image->protocol,
                $image->source,
                $status,
                $details,
            ];
        }

        $this->table(['ID', 'Alias', 'Remote Name', 'Protocol', 'Source', 'Status', 'Details'], $rows);

        $passed = $images->count() - $failures;
        $this->info(sprintf('Verification complete: %d passed, %d failed.', $passed, $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
