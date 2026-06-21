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

test('discrete credential array converts escaped newlines in PEM', function (): void {
    $pemEscaped = '-----BEGIN PRIVATE KEY-----\\nFAKEBODY\\n-----END PRIVATE KEY-----\\n';

    $creds = FirebaseCredentialsResolver::credentialsArrayFromDiscreteEnvSnapshot([
        'FIREBASE_PROJECT_ID' => 'demo-proj',
        'FIREBASE_CLIENT_EMAIL' => 'firebase-adminsdk@test.iam.gserviceaccount.com',
        'FIREBASE_PRIVATE_KEY' => $pemEscaped,
    ]);

    expect($creds['private_key'])->toContain("\n")
        ->and($creds['private_key'])->not->toContain('\\n')
        ->and($creds['type'])->toBe('service_account')
        ->and($creds['auth_uri'])->toBe('https://accounts.google.com/o/oauth2/auth')
        ->and($creds['token_uri'])->toBe('https://oauth2.googleapis.com/token')
        ->and($creds['project_id'])->toBe('demo-proj')
        ->and($creds['client_email'])->toBe('firebase-adminsdk@test.iam.gserviceaccount.com');
});

test('discrete credential array honours explicit FIREBASE_TYPE', function (): void {
    $creds = FirebaseCredentialsResolver::credentialsArrayFromDiscreteEnvSnapshot([
        'FIREBASE_TYPE' => 'service_account',
        'FIREBASE_PROJECT_ID' => 'p',
        'FIREBASE_CLIENT_EMAIL' => 'e@e.gserviceaccount.com',
        'FIREBASE_PRIVATE_KEY' => "-----BEGIN PRIVATE KEY-----\nX\n-----END PRIVATE KEY-----\n",
    ]);

    expect($creds['type'])->toBe('service_account');
});

test('classifyDiscreteEnvIntent is complete only when core triplet is present', function (): void {
    expect(FirebaseCredentialsResolver::classifyDiscreteEnvIntent([
        'FIREBASE_PROJECT_ID' => '',
        'FIREBASE_PRIVATE_KEY' => '',
        'FIREBASE_CLIENT_EMAIL' => '',
    ]))->toBe('none');

    expect(FirebaseCredentialsResolver::classifyDiscreteEnvIntent([
        'FIREBASE_PROJECT_ID' => 'p',
        'FIREBASE_PRIVATE_KEY' => '',
        'FIREBASE_CLIENT_EMAIL' => '',
    ]))->toBe('partial');

    expect(FirebaseCredentialsResolver::classifyDiscreteEnvIntent([
        'FIREBASE_PROJECT_ID' => 'p',
        'FIREBASE_PRIVATE_KEY' => "-----BEGIN PRIVATE KEY-----\nK\n-----END PRIVATE KEY-----\n",
        'FIREBASE_CLIENT_EMAIL' => 'e@svc',
    ]))->toBe('complete');
});

test('discrete credential array rejects non-PEM private material', function (): void {
    FirebaseCredentialsResolver::credentialsArrayFromDiscreteEnvSnapshot([
        'FIREBASE_PROJECT_ID' => 'p',
        'FIREBASE_CLIENT_EMAIL' => 'e@svc.test',
        'FIREBASE_PRIVATE_KEY' => 'not-a-pem-blob',
    ]);
})->throws(InvalidArgumentException::class);
