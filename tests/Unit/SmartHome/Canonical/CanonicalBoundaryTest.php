<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/ProviderExtensibilityBoundaryTest.php';

use App\SmartHome\ActionType;
use App\SmartHome\Canonical\Access;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\CommandValidator;
use App\SmartHome\Canonical\EnumConstraint;
use App\SmartHome\Canonical\NumberConstraint;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\Canonical\Unit;

/*
|--------------------------------------------------------------------------
| CSDM-07a — permanent canonical boundary guards (ADR-037 §8, §10)
|--------------------------------------------------------------------------
|
| Source scans reuse one helper set for both production guards and deliberate
| sentinel fixtures (temp files under sys_get_temp_dir()). Comment and
| docblock text is stripped before scale/provider detection so explanatory
| prose that cites forbidden scales or provider names does not false-positive.
| Executable string literals in production code must not carry those values.
*/

/**
 * @return list<string> Absolute paths to PHP files under app/SmartHome/Canonical
 */
function canonicalLayerPhpFiles(): array
{
    $root = dirname(__DIR__, 4);
    $base = $root.'/app/SmartHome/Canonical';

    /** @var list<string> $files */
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $files[] = $fileInfo->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Removes block and line comments so prohibition docblocks never false-positive.
 */
function canonicalBoundaryCodeWithoutComments(string $contents): string
{
    $withoutBlock = preg_replace('!/\*.*?\*/!s', '', $contents) ?? $contents;

    return preg_replace('/^\s*\/\/.*$/m', '', $withoutBlock) ?? $withoutBlock;
}

/**
 * Brightness-scale literals forbidden in the canonical layer (ADR-037 §5).
 *
 * @return list<string> Human-readable violation labels
 */
function canonicalScaleViolationsIn(string $contents): array
{
    $code = canonicalBoundaryCodeWithoutComments($contents);
    $violations = [];

    if (preg_match('/\b25[45](?:\.0+)?\b/', $code) === 1) {
        $violations[] = 'numeric literal 255 or 254';
    }

    if (preg_match('/[\'"]0-25[45][\'"]/', $code) === 1) {
        $violations[] = 'range literal "0-255" or "0-254"';
    }

    return $violations;
}

function assertCanonicalLayerHasNoScaleLeak(string $contents, string $relativePath): void
{
    $violations = canonicalScaleViolationsIn($contents);

    expect($violations)->toBe(
        [],
        sprintf(
            'Canonical layer file [%s] must not carry provider brightness scales (%s).',
            $relativePath,
            implode(', ', $violations) ?: 'unknown',
        ),
    );
}

/**
 * @return list<string>
 */
function canonicalForbiddenProviderTerms(): array
{
    $terms = [
        'OnOffTrait',
        'LevelControl',
        'light.turn_on',
        'moveToLevel',
        'supported_features',
    ];

    return array_merge($terms, array_merge(['fake'], knownProviderSlugsForBoundaryCheck()));
}

/**
 * @return list<string>
 */
function canonicalProviderViolationsIn(string $contents): array
{
    $code = canonicalBoundaryCodeWithoutComments($contents);
    $violations = [];

    foreach (canonicalForbiddenProviderTerms() as $term) {
        if ($term === 'fake' || in_array($term, knownProviderSlugsForBoundaryCheck(), true)) {
            foreach (hardcodedProviderSlugPatterns($term) as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = "provider slug pattern [{$term}]";

                    break;
                }
            }

            continue;
        }

        $quoted = preg_quote($term, '/');
        if (preg_match('/\b'.$quoted.'\b/', $code) === 1) {
            $violations[] = "provider term [{$term}]";
        }
    }

    return $violations;
}

function assertCanonicalLayerHasNoProviderLeak(string $contents, string $relativePath): void
{
    $violations = canonicalProviderViolationsIn($contents);

    expect($violations)->toBe(
        [],
        sprintf(
            'Canonical layer file [%s] must not reference provider identity or traits (%s).',
            $relativePath,
            implode(', ', $violations) ?: 'unknown',
        ),
    );
}

/**
 * PHP files under SmartHome outside Adapters — domain + canonical, not mappers.
 *
 * @return list<string>
 */
function smartHomeNonAdapterPhpFiles(): array
{
    $root = dirname(__DIR__, 4);
    $base = $root.'/app/SmartHome';

    /** @var list<string> $files */
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }

        $path = $fileInfo->getPathname();

        if (str_contains($path, '/Adapters/')) {
            continue;
        }

        $files[] = $path;
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function mapperScaleConversionViolationsOutsideAllowedMappers(string $contents): array
{
    $code = canonicalBoundaryCodeWithoutComments($contents);

    if (preg_match('/\bHA_BRIGHTNESS_MAX\b|\bpercentToProviderScale\b|\bproviderScaleToPercent\b/', $code) === 1) {
        return ['brightness conversion helper outside CanonicalMapper'];
    }

    if (preg_match('/[\'"]max[\'"]\s*=>\s*25[45]\b/', $code) === 1) {
        return ['legacy brightness max 255/254 outside adapter layer'];
    }

    if (preg_match('/\*\s*25[45]\b|\/\s*25[45]\b|25[45]\s*\*/', $code) === 1) {
        return ['arithmetic brightness scale conversion'];
    }

    return [];
}

function assertMapperScaleBoundary(string $contents, string $relativePath): void
{
    $violations = mapperScaleConversionViolationsOutsideAllowedMappers($contents);

    expect($violations)->toBe(
        [],
        sprintf('Non-adapter SmartHome file [%s] must not perform brightness scale conversion (%s).', $relativePath, implode(', ', $violations)),
    );
}

/**
 * Runs a scanner against a temp fixture directory; always cleans up in finally.
 *
 * @param  callable(string): list<string>  $scanner
 */
function withCanonicalBoundaryFixture(callable $scanner, string $fixtureBody, string $filename = 'LeakFixture.php'): void
{
    $dir = sys_get_temp_dir().'/csdm07-boundary-'.uniqid('', true);
    mkdir($dir);

    try {
        file_put_contents($dir.'/'.$filename, $fixtureBody);
        $contents = (string) file_get_contents($dir.'/'.$filename);
        $violations = $scanner($contents);

        expect($violations)->not->toBeEmpty('Sentinel fixture must be detected as a boundary violation.');
    } finally {
        $path = $dir.'/'.$filename;
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}

test('canonical layer PHP files exist for the boundary scan', function () {
    expect(canonicalLayerPhpFiles())->not->toBeEmpty();
});

test('GUARD scale: canonical layer never embeds provider brightness scales in code', function () {
    $root = dirname(__DIR__, 4);

    foreach (canonicalLayerPhpFiles() as $file) {
        $relative = str_replace($root.'/', '', $file);
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse("Could not read {$relative}.");

        assertCanonicalLayerHasNoScaleLeak($contents, $relative);
    }
});

test('GUARD provider: canonical layer never references provider slugs or trait vocabulary', function () {
    $root = dirname(__DIR__, 4);

    expect(knownProviderSlugsForBoundaryCheck())->toContain('home_assistant');

    foreach (canonicalLayerPhpFiles() as $file) {
        $relative = str_replace($root.'/', '', $file);
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse("Could not read {$relative}.");

        assertCanonicalLayerHasNoProviderLeak($contents, $relative);
    }
});

test('GUARD mapper: brightness scale conversion stays inside provider adapter mappers', function () {
    $root = dirname(__DIR__, 4);

    foreach (smartHomeNonAdapterPhpFiles() as $file) {
        $relative = str_replace($root.'/', '', $file);
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse("Could not read {$relative}.");

        assertMapperScaleBoundary($contents, $relative);
    }
});

test('GUARD domain: ADR-032 D.1 file list still has no hard-coded provider slugs', function () {
    $root = dirname(__DIR__, 4);

    foreach (providerExtensibilityBoundaryFiles() as $file) {
        $relative = str_replace($root.'/', '', $file);
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse("Could not read D.1 file {$relative}.");

        assertNoHardcodedProviderSlugReferences($contents, $relative);
    }
});

test('scale sentinel detects deliberate 255 leak and ignores comment-only prohibition prose', function () {
    withCanonicalBoundaryFixture(
        canonicalScaleViolationsIn(...),
        <<<'PHP'
        <?php
        /** Documented prohibition: 0-255 must never appear in code below. */
        $max = 255;
        PHP,
    );

    $commentOnly = <<<'PHP'
        <?php
        // 0-255 and 0-254 belong only in mapper comments.
        /** Home Assistant 0-255 scale is forbidden here. */
        $percent = 100;
        PHP;

    expect(canonicalScaleViolationsIn($commentOnly))->toBe([]);
});

test('provider sentinel detects deliberate trait leak in a fake canonical file', function () {
    withCanonicalBoundaryFixture(
        canonicalProviderViolationsIn(...),
        <<<'PHP'
        <?php
        $trait = OnOffTrait::class;
        PHP,
    );

    $commentOnly = <<<'PHP'
        <?php
        // OnOffTrait and moveToLevel stay in the mapper layer.
        $operation = Operation::On;
        PHP;

    expect(canonicalProviderViolationsIn($commentOnly))->toBe([]);
});

test('mapper sentinel detects arithmetic conversion outside adapters', function () {
    withCanonicalBoundaryFixture(
        mapperScaleConversionViolationsOutsideAllowedMappers(...),
        <<<'PHP'
        <?php
        $ha = (int) round($percent / 100 * 255);
        PHP,
    );
});

test('domain sentinel reuses ProviderExtensibilityBoundary detection on a synthetic D.1 violation', function () {
    $violating = <<<'PHP'
        <?php
        if ($device->provider === 'google_home') { return; }
        PHP;

    $detected = false;

    try {
        assertNoHardcodedProviderSlugReferences($violating, 'synthetic/Leak.php');
    } catch (Throwable) {
        $detected = true;
    }

    expect($detected)->toBeTrue();
});

test('canonical envelope without legacy can_* keys still permits gated actions', function () {
    $envelope = CapabilityContract::envelope([
        Capability::fromCatalog(CapabilityId::Power),
        Capability::fromCatalog(CapabilityId::Brightness),
    ]);

    expect(ActionType::isBlockedByDeviceCapabilities($envelope, 'turn_on'))->toBeFalse()
        ->and(ActionType::isBlockedByDeviceCapabilities($envelope, 'set_brightness'))->toBeFalse()
        ->and(ActionType::isBlockedByDeviceCapabilities($envelope, 'explode'))->toBeFalse();
});

test('legacy ADR-033 device rows remain compatible for capability gating', function () {
    $legacy = [
        'can_turn_on' => [],
        'can_turn_off' => [],
        'can_toggle' => [],
        'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
    ];

    expect(ActionType::isBlockedByDeviceCapabilities($legacy, 'turn_on'))->toBeFalse()
        ->and(ActionType::isBlockedByDeviceCapabilities($legacy, 'set_brightness'))->toBeFalse()
        ->and(ActionType::isBlockedByDeviceCapabilities($legacy, 'turn_off'))->toBeFalse();
});

test('EXTENSIBILITY: CommandValidator accepts a catalog capability without provider branching', function () {
    $device = CapabilityContract::envelope([
        new Capability(
            CapabilityId::HvacMode,
            Access::ReadWrite,
            [Operation::Set],
            new EnumConstraint(['off', 'heat', 'cool']),
        ),
    ]);

    $result = (new CommandValidator)->validate(
        $device,
        CapabilityId::HvacMode,
        Operation::Set,
        ['value' => 'heat'],
    );

    expect($result->wasRejected())->toBeFalse();

    $rejected = (new CommandValidator)->validate(
        $device,
        CapabilityId::HvacMode,
        Operation::Set,
        ['value' => 'turbo'],
    );

    expect($rejected->wasRejected())->toBeTrue();
});

test('EXTENSIBILITY: future numeric capability validates via envelope fixture only', function () {
    $device = CapabilityContract::envelope([
        Capability::fromCatalog(
            CapabilityId::TargetTemperature,
            new NumberConstraint(5.0, 35.0, 0.5, Unit::Celsius),
        ),
    ]);

    expect((new CommandValidator)->validate(
        $device,
        CapabilityId::TargetTemperature,
        Operation::Set,
        ['value' => 21.5],
    )->wasRejected())->toBeFalse();
});
