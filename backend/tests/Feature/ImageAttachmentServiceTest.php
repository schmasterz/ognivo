<?php

declare(strict_types=1);

use App\DTO\ImageSelectionDTO;
use App\Models\Host;
use App\Models\Image;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\ImageAttachmentService;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Support\Facades\DB;

test('DTO validation errors on invalid inputs and caps', function (): void {
    // Mode selected requires at least one ID
    expect(fn () => ImageSelectionDTO::selected([]))
        ->toThrow(InvalidArgumentException::class, 'Selected mode requires at least one image ID.');

    // Mode selected caps at 500 IDs
    $tooManyIds = range(1, 501);
    expect(fn () => ImageSelectionDTO::selected($tooManyIds))
        ->toThrow(InvalidArgumentException::class, 'Selected mode exceeds maximum of 500 image IDs.');

    // Mode filter rejects unknown filter keys
    expect(fn () => ImageSelectionDTO::filter(['unknown_key' => 'val']))
        ->toThrow(InvalidArgumentException::class, 'Unknown filter key: unknown_key');

    // Mode filter caps excludeIds at 500
    expect(fn () => ImageSelectionDTO::filter([], range(1, 501)))
        ->toThrow(InvalidArgumentException::class, 'Exclude IDs exceed maximum of 500.');

    // Mode delta caps filterOps at 10
    $tooManyOps = array_fill(0, 11, ['action' => 'attach', 'filter' => ['os_type' => 'linux']]);
    expect(fn () => ImageSelectionDTO::delta($tooManyOps))
        ->toThrow(InvalidArgumentException::class, 'Delta mode exceeds maximum of 10 filter operations.');

    // Mode delta validates filterOp action
    expect(fn () => ImageSelectionDTO::delta([['action' => 'invalid_action', 'filter' => []]]))
        ->toThrow(InvalidArgumentException::class, "Invalid filter op action: expected 'attach' or 'detach'.");

    // Mode delta validates filterOp filter keys
    expect(fn () => ImageSelectionDTO::delta([['action' => 'attach', 'filter' => ['bad_key' => '1']]]))
        ->toThrow(InvalidArgumentException::class, 'Unknown filter key: bad_key');

    // Mode delta caps attachIds and detachIds at 500
    expect(fn () => ImageSelectionDTO::delta(attachIds: range(1, 501)))
        ->toThrow(InvalidArgumentException::class, 'Attach IDs exceed maximum of 500.');
    expect(fn () => ImageSelectionDTO::delta(detachIds: range(1, 501)))
        ->toThrow(InvalidArgumentException::class, 'Detach IDs exceed maximum of 500.');

    // fromArray rejects invalid mode
    expect(fn () => ImageSelectionDTO::fromArray(['mode' => 'non_existent']))
        ->toThrow(InvalidArgumentException::class, 'Invalid mode: non_existent');

    // Negative or non-numeric IDs are rejected
    expect(fn () => ImageSelectionDTO::selected([0]))
        ->toThrow(InvalidArgumentException::class, 'Image IDs must be positive integers.');
    expect(fn () => ImageSelectionDTO::selected([-5]))
        ->toThrow(InvalidArgumentException::class, 'Image IDs must be positive integers.');
    expect(fn () => ImageSelectionDTO::selected(['abc']))
        ->toThrow(InvalidArgumentException::class, 'Image IDs must be positive integers.');

    // isNoop logic
    expect(ImageSelectionDTO::delta()->isNoop())->toBeTrue();
    expect(ImageSelectionDTO::all()->isNoop())->toBeFalse();
    expect(ImageSelectionDTO::selected([1])->isNoop())->toBeFalse();
    expect(ImageSelectionDTO::delta(attachIds: [1])->isNoop())->toBeFalse();
});

test('fromArray builds valid DTOs and handles snake_case keys', function (): void {
    $dtoAll = ImageSelectionDTO::fromArray(['mode' => 'all']);
    expect($dtoAll->mode)->toBe(ImageSelectionDTO::MODE_ALL);

    $dtoSelected = ImageSelectionDTO::fromArray([
        'mode' => 'selected',
        'ids' => [1, '2', 2, 3],
    ]);
    expect($dtoSelected->mode)->toBe(ImageSelectionDTO::MODE_SELECTED)
        ->and($dtoSelected->ids)->toBe([1, 2, 3]);

    $dtoFilter = ImageSelectionDTO::fromArray([
        'mode' => 'filter',
        'filter' => ['os_type' => 'linux', 'enabled' => true],
        'exclude_ids' => [10, 11],
    ]);
    expect($dtoFilter->mode)->toBe(ImageSelectionDTO::MODE_FILTER)
        ->and($dtoFilter->filter)->toBe(['os_type' => 'linux', 'enabled' => true])
        ->and($dtoFilter->excludeIds)->toBe([10, 11]);

    $dtoDelta = ImageSelectionDTO::fromArray([
        'mode' => 'delta',
        'filter_ops' => [
            ['action' => 'attach', 'filter' => ['protocol' => 'simplestreams']],
        ],
        'attach_ids' => [1, 2],
        'detach_ids' => [3, 4],
    ]);
    expect($dtoDelta->mode)->toBe(ImageSelectionDTO::MODE_DELTA)
        ->and($dtoDelta->filterOps)->toHaveCount(1)
        ->and($dtoDelta->attachIds)->toBe([1, 2])
        ->and($dtoDelta->detachIds)->toBe([3, 4]);
});

test('apply in all mode attaches every image in catalog', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    Image::factory()->count(3)->create();
    $catalogCount = DB::table('images')->count();
    expect($catalogCount)->toBeGreaterThanOrEqual(3);

    $service = app(ImageAttachmentService::class);
    $result = $service->apply($host, ImageSelectionDTO::all());

    expect($result['attached'])->toBe($catalogCount)
        ->and($result['detached'])->toBe(0)
        ->and($result['attached_ids'])->toHaveCount($catalogCount)
        ->and($result['detached_ids'])->toBeEmpty()
        ->and($host->images()->count())->toBe($catalogCount);
});

test('apply in selected mode attaches specified ids and detaches others', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $imgA = Image::factory()->create(['name' => 'test-img/a-'.fake()->lexify('????')]);
    $imgB = Image::factory()->create(['name' => 'test-img/b-'.fake()->lexify('????')]);
    $imgC = Image::factory()->create(['name' => 'test-img/c-'.fake()->lexify('????')]);

    // Initially attach A and B
    $host->images()->sync([$imgA->id, $imgB->id]);

    $service = app(ImageAttachmentService::class);
    // Target state: B and C
    $result = $service->apply($host, ImageSelectionDTO::selected([$imgB->id, $imgC->id]));

    expect($result['attached'])->toBe(1)
        ->and($result['detached'])->toBe(1)
        ->and($result['attached_ids'])->toBe([$imgC->id])
        ->and($result['detached_ids'])->toBe([$imgA->id])
        ->and($host->images()->pluck('images.id')->sort()->values()->all())->toBe([$imgB->id, $imgC->id]);
});

test('ids for non-existent images are ignored on insert', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $imgA = Image::factory()->create();

    $service = app(ImageAttachmentService::class);
    $result = $service->apply($host, ImageSelectionDTO::selected([$imgA->id, 999999, 888888]));

    expect($result['attached'])->toBe(1)
        ->and($result['detached'])->toBe(0)
        ->and($result['attached_ids'])->toBe([$imgA->id])
        ->and($host->images()->pluck('images.id')->all())->toBe([$imgA->id]);
});

test('idempotency: applying twice results in second result attached=0 detached=0', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $images = Image::factory()->count(2)->create();
    $ids = $images->pluck('id')->all();

    $service = app(ImageAttachmentService::class);
    $first = $service->apply($host, ImageSelectionDTO::selected($ids));
    expect($first['attached'])->toBe(2)->and($first['detached'])->toBe(0);

    $second = $service->apply($host, ImageSelectionDTO::selected($ids));
    expect($second['attached'])->toBe(0)
        ->and($second['detached'])->toBe(0)
        ->and($second['attached_ids'])->toBeEmpty()
        ->and($second['detached_ids'])->toBeEmpty();
});

test('apply in filter mode attaches matching images and detaches non-matching, respecting excludeIds', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $osType = 'linux-filter-'.fake()->lexify('????');
    $linux1 = Image::factory()->create(['os_type' => $osType, 'enabled' => true]);
    $linux2 = Image::factory()->create(['os_type' => $osType, 'enabled' => true]);
    $linux3 = Image::factory()->create(['os_type' => $osType, 'enabled' => true]);
    $windows = Image::factory()->create(['os_type' => 'windows-filter', 'enabled' => true]);

    // Host initially has windows attached
    $host->images()->sync([$windows->id]);

    $service = app(ImageAttachmentService::class);
    // Filter linux minus linux3
    $selection = ImageSelectionDTO::filter(['os_type' => $osType], excludeIds: [$linux3->id]);
    $result = $service->apply($host, $selection);

    expect($result['attached'])->toBe(2)
        ->and($result['detached'])->toBe(1)
        ->and($result['detached_ids'])->toBe([$windows->id])
        ->and($host->images()->pluck('images.id')->sort()->values()->all())->toBe([$linux1->id, $linux2->id]);
});

test('apply in filter mode escapes search wildcards % and _ correctly', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $suffix = fake()->lexify('????');
    $imgWithWildcard = Image::factory()->create(['name' => "ubuntu_22%special-{$suffix}"]);
    Image::factory()->create(['name' => "ubuntu-22-special-{$suffix}"]);

    $service = app(ImageAttachmentService::class);
    $selection = ImageSelectionDTO::filter(['search' => "22%special-{$suffix}"]);
    $result = $service->apply($host, $selection);

    expect($result['attached'])->toBe(1)
        ->and($result['attached_ids'])->toBe([$imgWithWildcard->id])
        ->and($host->images()->pluck('images.id')->all())->toBe([$imgWithWildcard->id]);
});

test('apply in delta mode executes filterOps in order, then attachIds, then detachIds', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $osPrefix = 'os-'.fake()->lexify('????-');
    $linux = Image::factory()->create(['os_type' => $osPrefix.'linux']);
    $bsd = Image::factory()->create(['os_type' => $osPrefix.'bsd']);
    $windows = Image::factory()->create(['os_type' => $osPrefix.'windows']);
    $solaris = Image::factory()->create(['os_type' => $osPrefix.'solaris']);

    $service = app(ImageAttachmentService::class);

    // Delta operations:
    // 1. attach os_type=linux (linux attached)
    // 2. attach os_type=bsd (bsd attached)
    // 3. detach os_type=linux (linux detached)
    // 4. attachIds [windows, solaris]
    // 5. detachIds [bsd]
    // End state should have only windows and solaris.
    $selection = ImageSelectionDTO::delta(
        filterOps: [
            ['action' => 'attach', 'filter' => ['os_type' => $osPrefix.'linux']],
            ['action' => 'attach', 'filter' => ['os_type' => $osPrefix.'bsd']],
            ['action' => 'detach', 'filter' => ['os_type' => $osPrefix.'linux']],
        ],
        attachIds: [$windows->id, $solaris->id],
        detachIds: [$bsd->id],
    );

    $result = $service->apply($host, $selection);

    expect($result['attached'])->toBe(2)
        ->and($result['detached'])->toBe(0)
        ->and($host->images()->pluck('images.id')->sort()->values()->all())->toBe([$windows->id, $solaris->id]);
});

test('other hosts rows remain completely untouched during modifications', function (): void {
    $hostA = Host::factory()->create(['auto_attach_images' => false]);
    $hostB = Host::factory()->create(['auto_attach_images' => false]);

    DB::table('host_image')->whereIn('host_id', [$hostA->id, $hostB->id])->delete();

    $img1 = Image::factory()->create();
    $img2 = Image::factory()->create();
    $img3 = Image::factory()->create();

    $hostA->images()->sync([$img1->id, $img2->id]);
    $hostB->images()->sync([$img1->id, $img2->id, $img3->id]);

    $service = app(ImageAttachmentService::class);
    // Apply selected on Host A
    $service->apply($hostA, ImageSelectionDTO::selected([$img1->id]));

    expect($hostA->images()->pluck('images.id')->all())->toBe([$img1->id])
        ->and($hostB->images()->pluck('images.id')->sort()->values()->all())->toBe([$img1->id, $img2->id, $img3->id]);
});

test('>500 detach via filter works with bulk insert', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $now = now()->toDateTimeString();
    $osType = 'bulk_test_'.fake()->lexify('????');
    $imagesData = [];
    for ($i = 1; $i <= 600; $i++) {
        $imagesData[] = [
            'name' => "bulk-img-{$osType}-{$i}",
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'os_type' => $osType,
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('images')->insert($imagesData);

    $bulkIds = DB::table('images')->where('os_type', $osType)->pluck('id');
    expect($bulkIds)->toHaveCount(600);

    $pivotData = [];
    foreach ($bulkIds as $imgId) {
        $pivotData[] = [
            'host_id' => $host->id,
            'image_id' => $imgId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($pivotData, 500) as $chunk) {
        DB::table('host_image')->insert($chunk);
    }
    expect(DB::table('host_image')->where('host_id', $host->id)->count())->toBe(600);

    $service = app(ImageAttachmentService::class);
    // Filter matching nothing -> detaches all 600 images
    $result = $service->apply($host, ImageSelectionDTO::filter(['os_type' => 'non_matching']));

    expect($result['detached'])->toBe(600)
        ->and($result['attached'])->toBe(0)
        ->and(DB::table('host_image')->where('host_id', $host->id)->count())->toBe(0);
});

test('preview returns correct counts, impact on instances, and leaves host_image unchanged', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    DB::table('host_image')->where('host_id', $host->id)->delete();

    $user = User::factory()->create();

    $suffix = fake()->lexify('????');
    $nameA = "preview-ubuntu-{$suffix}";
    $nameB = "preview-debian-{$suffix}";
    $nameC = "preview-alpine-{$suffix}";

    $imgA = Image::factory()->create(['name' => $nameA]);
    $imgB = Image::factory()->create(['name' => $nameB]);
    $imgC = Image::factory()->create(['name' => $nameC]);

    $host->images()->sync([$imgA->id, $imgB->id, $imgC->id]);

    VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'os_image' => $nameA,
        'status' => Running::class,
    ]);
    VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'os_image' => $nameA,
        'status' => Stopped::class,
    ]);
    VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'os_image' => $nameB,
        'status' => Running::class,
    ]);

    $service = app(ImageAttachmentService::class);
    // Preview detaching A and B
    $preview = $service->preview($host, ImageSelectionDTO::delta(detachIds: [$imgA->id, $imgB->id]));

    expect($preview['attached'])->toBe(0)
        ->and($preview['detached'])->toBe(2)
        ->and($preview['total_instances'])->toBe(3)
        ->and($preview['images'])->toHaveCount(2)
        ->and($preview['images'][0]['name'])->toBe($nameA)
        ->and($preview['images'][0]['instance_count'])->toBe(2)
        ->and($preview['images'][1]['name'])->toBe($nameB)
        ->and($preview['images'][1]['instance_count'])->toBe(1)
        // Verify host_image is unchanged after preview
        ->and($host->images()->count())->toBe(3);
});

test('preview ignores deleted VMs and instances on other hosts', function (): void {
    $hostA = Host::factory()->create(['auto_attach_images' => false]);
    $hostB = Host::factory()->create(['auto_attach_images' => false]);

    DB::table('host_image')->whereIn('host_id', [$hostA->id, $hostB->id])->delete();

    $user = User::factory()->create();
    $imageName = 'preview-fedora-'.fake()->lexify('????');
    $img = Image::factory()->create(['name' => $imageName]);

    $hostA->images()->sync([$img->id]);
    $hostB->images()->sync([$img->id]);

    // Deleted VM on host A (should be ignored)
    VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $hostA->id,
        'os_image' => $imageName,
        'status' => Deleted::class,
    ]);

    // Active VM on host B (should be ignored because host_id != hostA)
    VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $hostB->id,
        'os_image' => $imageName,
        'status' => Running::class,
    ]);

    $service = app(ImageAttachmentService::class);
    $preview = $service->preview($hostA, ImageSelectionDTO::delta(detachIds: [$img->id]));

    expect($preview['detached'])->toBe(1)
        ->and($preview['total_instances'])->toBe(0)
        ->and($preview['images'])->toBeEmpty();
});

test('attachToAutoAttachHosts only targets auto_attach_images=true hosts', function (): void {
    $autoHost1 = Host::factory()->create(['auto_attach_images' => true]);
    $autoHost2 = Host::factory()->create(['auto_attach_images' => true]);
    $manualHost = Host::factory()->create(['auto_attach_images' => false]);

    $newImage = Image::factory()->create();

    // Ensure host_image does not already have this newImage
    DB::table('host_image')->where('image_id', $newImage->id)->delete();

    $service = app(ImageAttachmentService::class);
    $affected = $service->attachToAutoAttachHosts($newImage);

    expect($affected)->toBe(2)
        ->and(DB::table('host_image')->where('image_id', $newImage->id)->where('host_id', $autoHost1->id)->exists())->toBeTrue()
        ->and(DB::table('host_image')->where('image_id', $newImage->id)->where('host_id', $autoHost2->id)->exists())->toBeTrue()
        ->and(DB::table('host_image')->where('image_id', $newImage->id)->where('host_id', $manualHost->id)->exists())->toBeFalse();

    // Second call is idempotent (0 rows added)
    $secondCall = $service->attachToAutoAttachHosts($newImage);
    expect($secondCall)->toBe(0);
});

test('deleting an Image cascades its host_image rows', function (): void {
    $host = Host::factory()->create(['auto_attach_images' => false]);
    $image = Image::factory()->create();

    $host->images()->syncWithoutDetaching([$image->id]);
    expect(DB::table('host_image')->where('image_id', $image->id)->exists())->toBeTrue();

    $image->delete();

    expect(DB::table('host_image')->where('image_id', $image->id)->exists())->toBeFalse();
});
