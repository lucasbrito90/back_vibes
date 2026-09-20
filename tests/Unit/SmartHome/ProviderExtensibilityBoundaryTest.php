<?php

declare(strict_types=1);

/**
 * ADR-032 §D.1 structural guard (T29), extended by P03 (ADR-036 Decision 2/3)
 * to cover EVERY known provider slug, not just the test-only 'fake' one.
 *
 * Permanent executable proof that registering a provider — test-only
 * ('fake') or real (any slug in config('smart_home.known_providers'), the
 * provider identity superset introduced by P01) — never requires edits to
 * business-layer files listed as intocável. Unlike a one-time git diff,
 * this test fails if any D.1 file gains a FakeProviderAdapter import, or a
 * hard-coded assignment of 'fake', 'home_assistant', 'google_home', or any
 * future known provider slug.
 *
 * This is the guard that keeps ADR-036's "no provider conditionals in the
 * domain" promise honest as Google Home lands: a D.1 file that special-cases
 * `if ($provider === 'google_home')` would defeat the whole point of
 * execution-capability-driven dispatch (P08) as surely as hardcoding 'fake'
 * would defeat provider extensibility.
 *
 * Provider-slug detection uses assignment-shaped patterns only (not a bare
 * substring) so unrelated English prose in comments cannot false-positive.
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

/**
 * Regex patterns matching `$slug` hard-coded as a provider value, in the two
 * shapes that actually defeat provider neutrality.
 *
 * ASSIGNMENT (the original 'fake'-only shapes, now parameterized): writing the
 * slug into a payload, a model attribute or an array key.
 *
 * COMPARISON (P14): branching on the slug — `=== 'x'`, `!= 'x'`, `in_array`,
 * `match`, `str_contains`. This is the shape ADR-036 forbids most explicitly
 * ("o domínio consulta capability, nunca a identidade do provider") and the
 * one most likely to appear in real code: nobody breaks the architecture by
 * assigning a slug, they break it by writing an `if`. The P14 regression pass
 * proved the guard was silent on a deliberate
 * `if ($vibe->scene?->provider === 'google_home')` injected into a real D.1
 * file — a blind spot inherited from the original T29 design, not a
 * regression introduced by P01–P08.
 *
 * Both sides of a comparison are matched, since `'x' === $device->provider`
 * reads the same to PHP. Patterns stay shape-anchored (never a bare substring)
 * so prose in comments cannot false-positive — the property the original
 * design was protecting, and which this extension preserves.
 *
 * @return list<string>
 */
function hardcodedProviderSlugPatterns(string $slug): array
{
    $quoted = preg_quote($slug, '/');

    return [
        // ── Assignment shapes ────────────────────────────────────────────
        "/['\"]provider['\"]\\s*=>\\s*['\"]{$quoted}['\"]/",
        "/->provider\\s*=\\s*['\"]{$quoted}['\"]/",
        "/\\['provider'\\]\\s*=\\s*['\"]{$quoted}['\"]/",

        // ── Comparison shapes (P14) ──────────────────────────────────────
        // provider === 'slug' / !== / == / != , either side of the operator.
        "/provider(?:\\(\\))?\\s*(?:===|!==|==|!=)\\s*['\"]{$quoted}['\"]/",
        "/['\"]{$quoted}['\"]\\s*(?:===|!==|==|!=)\\s*\\\$?[A-Za-z_>\\-\\[\\]'\"]*provider/",
        // match ($x->provider) { 'slug' => ... } and match(true) { $x->provider === 'slug' => ... }
        "/['\"]{$quoted}['\"]\\s*=>\\s*(?!\\s*\\[)/",
        // in_array('slug', …) / in_array($provider, ['slug', …])
        "/in_array\\s*\\(\\s*['\"]{$quoted}['\"]/",
        "/in_array\\s*\\([^)]*provider[^)]*['\"]{$quoted}['\"]/",
        // str_contains / str_starts_with / === on a slug literal passed around
        "/str_(?:contains|starts_with|ends_with)\\s*\\([^)]*['\"]{$quoted}['\"]/",
    ];
}

/**
 * This file is a pure unit test with no Laravel bootstrap (no `uses(TestCase::class)`),
 * matching its original form — so `known_providers` is read by requiring the
 * config file directly rather than via the config() helper, which needs a
 * booted container this file deliberately does not have.
 *
 * @return list<string>
 */
function knownProviderSlugsForBoundaryCheck(): array
{
    $root = dirname(__DIR__, 3);

    /** @var array{known_providers?: list<string>} $config */
    $config = require $root.'/config/smart_home.php';

    return $config['known_providers'] ?? [];
}

function assertNoHardcodedProviderSlugReferences(string $contents, string $relativePath): void
{
    expect(str_contains($contents, 'FakeProviderAdapter'))
        ->toBeFalse("ADR-032 D.1 file [{$relativePath}] must not reference FakeProviderAdapter.");

    // 'fake' is checked unconditionally: it is the test-only provider used to
    // prove extensibility and is deliberately never added to known_providers.
    $slugsToCheck = array_merge(['fake'], knownProviderSlugsForBoundaryCheck());

    foreach ($slugsToCheck as $slug) {
        foreach (hardcodedProviderSlugPatterns($slug) as $pattern) {
            expect(preg_match($pattern, $contents))
                ->toBe(0, "ADR-032 D.1 file [{$relativePath}] must not hard-code provider slug '{$slug}' (matched {$pattern}).");
        }
    }

    expect(preg_match('/provider\\s*=>\\s*FakeProviderAdapter::PROVIDER_SLUG/', $contents))
        ->toBe(0, "ADR-032 D.1 file [{$relativePath}] must not reference FakeProviderAdapter::PROVIDER_SLUG.");
}

test('ADR-032 D.1 boundary files exist', function () {
    foreach (providerExtensibilityBoundaryFiles() as $file) {
        expect(file_exists($file))->toBeTrue("Expected D.1 boundary file {$file} to exist.");
    }
});

test('ADR-032 D.1 intocável files never reference FakeProviderAdapter or hard-code any known provider slug', function () {
    $root = dirname(__DIR__, 3);

    // Sanity check: the generalization only means something if there is more
    // than the original 'fake' provider to check — proves P03 actually
    // widened coverage, not just renamed the function.
    expect(knownProviderSlugsForBoundaryCheck())
        ->toContain('home_assistant')
        ->toContain('google_home');

    foreach (providerExtensibilityBoundaryFiles() as $file) {
        $relativePath = str_replace($root.'/', '', $file);
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse("Could not read D.1 file {$relativePath}.");

        assertNoHardcodedProviderSlugReferences($contents, $relativePath);
    }
});

/**
 * T29-pattern proof by deliberate violation and revert: rather than
 * temporarily vandalizing a real production D.1 file, this exercises the
 * SAME detection function the two tests above use, against synthetic
 * content standing in for "a D.1 file's contents". This proves the guard
 * genuinely fires for google_home (P03's new case) and home_assistant, not
 * just for 'fake' — and that it stays silent on legitimate content.
 */
test('boundary guard fires on a deliberate google_home/home_assistant hardcode and clears once reverted', function () {
    $relativePath = 'synthetic/DeliberateViolationFixture.php';

    $violating = <<<'PHP'
        <?php
        $connection = new ProviderConnection(['provider' => 'google_home']);
        PHP;

    expect(fn () => assertNoHardcodedProviderSlugReferences($violating, $relativePath))
        ->toThrow(Exception::class);

    $violatingHomeAssistant = <<<'PHP'
        <?php
        $job->provider = 'home_assistant';
        PHP;

    expect(fn () => assertNoHardcodedProviderSlugReferences($violatingHomeAssistant, $relativePath))
        ->toThrow(Exception::class);

    // Revert: the same shape, expressed through the provider-agnostic
    // pattern D.1 files are required to use instead (a variable, not a
    // literal). No exception — the guard is silent on legitimate content.
    $reverted = <<<'PHP'
        <?php
        $connection = new ProviderConnection(['provider' => $providerSlug]);
        PHP;

    assertNoHardcodedProviderSlugReferences($reverted, $relativePath);
});

/**
 * P14 — the comparison shapes. The regression pass injected
 * `if ($vibe->scene?->provider === 'google_home')` into a real D.1 file
 * (VibeSmartHomeDispatchService) and the guard stayed silent: it only knew
 * assignment shapes. Branching on the slug is precisely what ADR-036 forbids
 * — the domain must ask the capability, never the provider identity — and is
 * the far likelier way real code breaks the boundary.
 *
 * Each case below is a shape that must now be caught. If any of them stops
 * throwing, the guard has regressed back into the blind spot P14 found.
 */
test('boundary guard fires on every comparison-shaped provider slug hardcode (P14)', function () {
    $relativePath = 'synthetic/DeliberateViolationFixture.php';

    $violations = [
        'strict equality' => "<?php\nif (\$vibe->scene?->provider === 'google_home') { return null; }",
        'strict inequality' => "<?php\nif (\$device->provider !== 'home_assistant') { return; }",
        'loose equality' => "<?php\nif (\$connection->provider == 'google_home') { return; }",
        'yoda comparison' => "<?php\nif ('google_home' === \$action->device->provider) { return; }",
        'match arm on the slug' => "<?php\nreturn match (\$device->provider) { 'google_home' => null, default => \$x };",
        'in_array with slug literal' => "<?php\nif (in_array('google_home', \$slugs, true)) { return; }",
        'in_array over a slug list' => "<?php\nif (in_array(\$device->provider, ['google_home', 'other'], true)) { return; }",
        'str_contains on the slug' => "<?php\nif (str_contains(\$row->provider, 'google_home')) { return; }",
    ];

    foreach ($violations as $label => $contents) {
        expect(fn () => assertNoHardcodedProviderSlugReferences($contents, $relativePath))
            ->toThrow(Exception::class, '', "Guard must reject the {$label} shape.");
    }

    // Reverted: the same branching expressed the way D.1 files are required to
    // — asking the execution capability, never the identity. Guard stays silent.
    $reverted = <<<'PHP'
        <?php
        if (! in_array(ProviderExecutionCapability::ScheduledExecution, $descriptor->executionCapabilities, true)) {
            return null;
        }
        PHP;

    assertNoHardcodedProviderSlugReferences($reverted, $relativePath);
});
