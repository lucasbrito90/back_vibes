<?php

declare(strict_types=1);

use App\Services\Firebase\FirebaseCredentialsResolver;
use Tests\TestCase;

uses(TestCase::class);

test('decodeInlineServiceAccount accepts minimal valid JSON with project_id', function (): void {
    $data = FirebaseCredentialsResolver::decodeInlineServiceAccount('{"project_id":"my-app"}');

    expect($data)->toBeArray()
        ->and($data['project_id'])->toBe('my-app');
});

test('decodeInlineServiceAccount rejects invalid JSON', function (): void {
    FirebaseCredentialsResolver::decodeInlineServiceAccount('{not-json');
})->throws(InvalidArgumentException::class, 'FIREBASE_SERVICE_ACCOUNT_JSON must contain valid JSON');

test('decodeInlineServiceAccount rejects JSON array root', function (): void {
    FirebaseCredentialsResolver::decodeInlineServiceAccount('[1,2]');
})->throws(InvalidArgumentException::class, 'JSON object');

test('decodeInlineServiceAccount rejects missing project_id', function (): void {
    FirebaseCredentialsResolver::decodeInlineServiceAccount('{"type":"service_account"}');
})->throws(InvalidArgumentException::class, 'project_id');

test('decodeInlineServiceAccount rejects empty project_id', function (): void {
    FirebaseCredentialsResolver::decodeInlineServiceAccount('{"project_id":""}');
})->throws(InvalidArgumentException::class, 'project_id');
