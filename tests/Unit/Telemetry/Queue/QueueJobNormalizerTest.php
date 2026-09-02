<?php

use App\Telemetry\Queue\QueueJobNormalizer;
use Illuminate\Contracts\Queue\Job;

afterEach(function () {
    Mockery::close();
});

test('normalizes a resolvable job class to its basename', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveQueuedJobClass')->andReturn('App\\Jobs\\SmartHome\\SceneActionJob');

    expect((new QueueJobNormalizer)->normalize($job))->toBe('SceneActionJob');
});

test('falls back to a bounded value when resolveQueuedJobClass() throws', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveQueuedJobClass')->andThrow(new RuntimeException('malformed payload'));

    expect((new QueueJobNormalizer)->normalize($job))->toBe(QueueJobNormalizer::UNKNOWN);
});

test('falls back to a bounded value when resolveQueuedJobClass() returns an empty string', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveQueuedJobClass')->andReturn('');

    expect((new QueueJobNormalizer)->normalize($job))->toBe(QueueJobNormalizer::UNKNOWN);
});

test('never returns a serialized payload, UUID, or job ID', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveQueuedJobClass')->andReturn('App\\Jobs\\PushNotifications\\PushNotificationJob');
    $job->shouldNotReceive('getJobId');
    $job->shouldNotReceive('uuid');

    $name = (new QueueJobNormalizer)->normalize($job);

    expect($name)->toBe('PushNotificationJob')
        ->and($name)->not->toContain('{')
        ->and(strlen($name))->toBeLessThan(100);
});
