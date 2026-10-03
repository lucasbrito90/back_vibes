<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Sound\CreateSoundWithUploadedFiles;
use App\Console\Commands\Support\SyntheticSoundCatalog;
use App\Models\Sound;
use App\Services\Audio\SyntheticAudioGenerator;
use App\Services\Audio\SyntheticThumbnailGenerator;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class GenerateSyntheticSoundCatalog extends Command
{
    protected $signature = 'sounds:generate-synthetic-catalog
                            {--dry-run : Generate files locally and log only; do not upload}
                            {--limit= : Maximum number of catalog entries to process}';

    protected $description = 'Synthesize WAV + PNG placeholders and create catalog Sounds via CreateSoundWithUploadedFiles.';

    public function handle(
        SyntheticAudioGenerator $audioGenerator,
        SyntheticThumbnailGenerator $thumbnailGenerator,
        CreateSoundWithUploadedFiles $createSound,
    ): int {
        if (! extension_loaded('gd')) {
            $this->error('PHP GD extension is not loaded. Enable ext-gd and retry.');

            return self::FAILURE;
        }

        if (! $this->spacesCredentialsPresent()) {
            $this->error('DO_SPACES_KEY / DO_SPACES_SECRET / DO_SPACES_BUCKET must be set in .env for uploads.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $max = $limit !== null && $limit !== '' ? max(0, (int) $limit) : null;
        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $planned = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;

        foreach (SyntheticSoundCatalog::entries() as $entry) {
            if ($max !== null && $processed >= $max) {
                break;
            }
            $processed++;

            $name = $entry['name'];
            if ($this->soundExistsByName($name)) {
                $this->line("Skip (exists): {$name}");
                $skipped++;

                continue;
            }

            $tmpDir = storage_path('app/tmp/synthetic-sounds/'.Str::slug($name));
            File::ensureDirectoryExists($tmpDir);
            $wavPath = $tmpDir.'/audio.wav';
            $pngPath = $tmpDir.'/thumb.png';

            try {
                $audioGenerator->generateToFile($entry['synthesis'], $wavPath);
                $thumbnailGenerator->generateToFile($name, $entry['category'], $pngPath);

                $wavBytes = filesize($wavPath);
                $pngBytes = filesize($pngPath);
                if ($wavBytes === false || $pngBytes === false) {
                    throw new RuntimeException('Unable to stat generated temp files.');
                }

                if ($dryRun) {
                    $this->info(sprintf(
                        '[dry-run] Would create "%s" (%s) — wav=%s bytes, png=%s bytes, %ds',
                        $name,
                        $entry['category'],
                        number_format($wavBytes),
                        number_format($pngBytes),
                        $entry['duration_seconds'],
                    ));
                    $planned++;

                    continue;
                }

                $metadata = [
                    'name' => $name,
                    'category' => $entry['category'],
                    'duration_seconds' => $entry['duration_seconds'],
                    'tags' => $entry['tags'],
                    'is_active' => true,
                ];

                $audioUpload = new UploadedFile($wavPath, 'audio.wav', 'audio/wav', null, true);
                $thumbUpload = new UploadedFile($pngPath, 'thumb.png', 'image/png', null, true);

                $sound = $createSound($metadata, $audioUpload, $thumbUpload);
                $this->info("Created sound #{$sound->id}: {$sound->name}");
                $created++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed [{$name}]: {$e->getMessage()}");
            } finally {
                if (File::isDirectory($tmpDir)) {
                    File::deleteDirectory($tmpDir);
                }
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("Done (dry-run). planned={$planned}, skipped={$skipped}, failed={$failed}");
        } else {
            $this->info("Done. created={$created}, skipped={$skipped}, failed={$failed}");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function soundExistsByName(string $name): bool
    {
        return Sound::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->exists();
    }

    private function spacesCredentialsPresent(): bool
    {
        foreach (['DO_SPACES_KEY', 'DO_SPACES_SECRET', 'DO_SPACES_BUCKET'] as $key) {
            $value = env($key);
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }
}
