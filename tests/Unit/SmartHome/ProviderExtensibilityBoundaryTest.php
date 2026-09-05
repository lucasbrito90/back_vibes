<?php

declare(strict_types=1);

/**
 * ADR-032 §D.1 structural guard (T29).
 *
 * Permanent executable proof that registering a test provider does not require
 * edits to business-layer files listed as intocável. Unlike a one-time git diff,
 * this test fails if any D.1 file gains a FakeProviderAdapter import or a
 * hard-coded fake provider slug assignment.
 *
 * Provider-slug detection uses assignment-shaped patterns only (not a bare
 * 'fake' substring) so unrelated English prose in comments cannot false-positive.
 */
function providerExtensibilityBoundaryRelativePaths(): array
{
    $paths = [
        'routes/api.php',
        'app/Http/Controllers/Api/DeviceController.php',
        'app/Http/Controllers/Api/SceneController.php',
        'app/Http/Controllers/Api/SceneActionController.php',
        'app/Http/Controllers/Api/SceneDispatchController.php',
        'app/Http/Controllers/Api/VibeSmartHomeDispatchController.php',
        'app/Jobs/SmartHome/SceneActionJob.php',
        'app/SmartHome/Services/ProviderDeviceSyncService.php',
        'app/SmartHome/Services/VibeSmartHomeDispatchService.php',
        'app/SmartHome/Services/SceneDispatchService.php',
        'app/SmartHome/Validation/ScheduleAutomationValidator.php',
        'app/Console/Commands/DispatchDueSchedulesCommand.php',
        'app/Console/Commands/DispatchSchedulesLoopCommand.php',
        'app/Models/Scene.php',
        'app/Models/SceneAction.php',
        'app/Models/Vibe.php',
        'app/Models/Device.php',
        'database/migrations/2026_08_30_192809_create_scenes_table.php',
        'database/migrations/2026_08_30_192810_create_scene_actions_table.php',
        'database/migrations/2026_09_02_230449_add_scene_id_to_vibes_table.php',
        'database/migrations/2026_09_02_232728_drop_vibe_device_actions_table.php',
    ];

    $root = dirname(__DIR__, 3);
    $schedulingDir = $root.'/app/Services/Scheduling';

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($schedulingDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $paths[] = str_replace($root.'/', '', $fileInfo->getPathname());
        }
    }

    sort($paths);

    return $paths;
}

function providerExtensibilityBoundaryFiles(): array
{
    $root = dirname(__DIR__, 3);

    return array_map(
        fn (string $relative): string => $root.'/'.$relative,
        providerExtensibilityBoundaryRelativePaths(),
    );
}

function assertNoFakeProviderReferences(string $contents, string $relativePath): void
{
    expect(str_contains($contents, 'FakeProviderAdapter'))
        ->toBeFalse("ADR-032 D.1 file [{$relativePath}] must not reference FakeProviderAdapter.");

    $providerSlugPatterns = [
        "/['\"]provider['\"]\\s*=>\\s*['\"]fake['\"]/",
        '/provider\\s*=>\\s*FakeProviderAdapter::PROVIDER_SLUG/',
        "/->provider\\s*=\\s*['\"]fake['\"]/",
        "/\\['provider'\\]\\s*=\\s*['\"]fake['\"]/",
    ];

    foreach ($providerSlugPatterns as $pattern) {
        expect(preg_match($pattern, $contents))
            ->toBe(0, "ADR-032 D.1 file [{$relativePath}] must not hard-code provider slug 'fake' (matched {$pattern}).");
    }
}

test('ADR-032 D.1 boundary files exist', function () {
    foreach (providerExtensibilityBoundaryFiles() as $file) {
        expect(file_exists($file))->toBeTrue("Expected D.1 boundary file {$file} to exist.");
    }
});

test('ADR-032 D.1 intocável files never reference FakeProviderAdapter or hard-coded fake provider slug', function () {
    $root = dirname(__DIR__, 3);

    foreach (providerExtensibilityBoundaryFiles() as $file) {
        $relativePath = str_replace($root.'/', '', $file);
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse("Could not read D.1 file {$relativePath}.");

        assertNoFakeProviderReferences($contents, $relativePath);
    }
});
