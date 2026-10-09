<?php

namespace Tests;

use App\Services\Audio\AudioTranscoding;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\Audio\FakeAudioTranscoder;

abstract class TestCase extends BaseTestCase
{
    public FakeAudioTranscoder $audioTranscoder;

    /**
     * Runs before any test trait (RefreshDatabase, ...) touches the database. The project `.env` points at the
     * real staging PostgreSQL, so a test run that is not pinned to in-memory SQLite must never start.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: database connection is "%s" (database "%s"), expected in-memory sqlite. '
                .'Check phpunit.xml and the shell environment (DB_* variables override phpunit.xml).',
                $connection,
                $database,
            ));
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No test spawns ffmpeg/ffprobe unless it opts in (see FfmpegAudioTranscoderTest).
        $this->audioTranscoder = new FakeAudioTranscoder;
        $this->app->instance(AudioTranscoding::class, $this->audioTranscoder);
    }
}
