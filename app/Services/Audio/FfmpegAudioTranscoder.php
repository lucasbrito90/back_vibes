<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class FfmpegAudioTranscoder implements AudioTranscoding
{
    public function probe(string $path): AudioProbe
    {
        $process = new Process([
            (string) config('audio.ffmpeg.ffprobe_binary'),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ]);
        $process->setTimeout((float) config('audio.ffmpeg.probe_timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw AudioProcessingException::transient('probe_timeout', 'ffprobe timed out.', $e);
        } catch (\Throwable $e) {
            throw AudioProcessingException::transient('ffprobe_unavailable', 'ffprobe could not be executed: '.$e->getMessage(), $e);
        }

        if (! $process->isSuccessful()) {
            throw AudioProcessingException::permanent(
                'unreadable_media',
                'ffprobe could not read the file: '.$this->tail($process->getErrorOutput()),
            );
        }

        /** @var array{streams?: list<array<string, mixed>>, format?: array<string, mixed>}|null $json */
        $json = json_decode($process->getOutput(), true);
        if (! is_array($json)) {
            throw AudioProcessingException::permanent('unreadable_media', 'ffprobe returned an unparseable response.');
        }

        $audio = [];
        $other = 0;
        foreach ($json['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'audio') {
                $audio[] = $stream;
            } elseif (($stream['codec_type'] ?? null) !== 'attachment') {
                $other++;
            }
        }

        $format = $json['format'] ?? [];
        $first = $audio[0] ?? [];
        $seconds = $format['duration'] ?? $first['duration'] ?? null;

        return new AudioProbe(
            audioStreams: count($audio),
            otherStreams: $other,
            codec: isset($first['codec_name']) ? (string) $first['codec_name'] : null,
            durationMs: is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null,
            channels: isset($first['channels']) ? (int) $first['channels'] : null,
            sampleRate: isset($first['sample_rate']) ? (int) $first['sample_rate'] : null,
            bitRate: isset($format['bit_rate']) && is_numeric($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            formatName: isset($format['format_name']) ? (string) $format['format_name'] : null,
        );
    }

    public function transcode(string $inputPath, string $outputPath): void
    {
        $extra = trim((string) config('audio.profile.extra_args'));

        $command = [
            (string) config('audio.ffmpeg.binary'),
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $inputPath,
            '-vn', '-map', '0:a:0', '-map_metadata', '-1',
            '-c:a', (string) config('audio.profile.codec'),
            '-b:a', (string) config('audio.profile.bitrate'),
            ...($extra === '' ? [] : (preg_split('/\s+/', $extra) ?: [])),
            $outputPath,
        ];

        $process = new Process($command);
        $process->setTimeout((float) config('audio.ffmpeg.transcode_timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw AudioProcessingException::transient('transcode_timeout', 'ffmpeg timed out.', $e);
        } catch (\Throwable $e) {
            throw AudioProcessingException::transient('ffmpeg_unavailable', 'ffmpeg could not be executed: '.$e->getMessage(), $e);
        }

        if (! $process->isSuccessful()) {
            throw AudioProcessingException::transient(
                'transcode_failed',
                'ffmpeg failed (exit '.$process->getExitCode().'): '.$this->tail($process->getErrorOutput()),
            );
        }
    }

    private function tail(string $output): string
    {
        $output = trim($output);

        return mb_strlen($output) > 500 ? '…'.mb_substr($output, -500) : $output;
    }
}
