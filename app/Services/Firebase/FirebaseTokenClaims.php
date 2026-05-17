<?php

declare(strict_types=1);

namespace App\Services\Firebase;

use Lcobucci\JWT\UnencryptedToken;

final readonly class FirebaseTokenClaims
{
    private function __construct(
        public string $uid,
        public ?string $email,
        public ?string $name,
        public ?string $picture,
    ) {}

    public static function fromUnencryptedToken(UnencryptedToken $token): self
    {
        $c = $token->claims();
        $sub = $c->get('sub');

        if (! is_string($sub) || $sub === '') {
            throw new \InvalidArgumentException('Firebase ID token is missing a subject (sub) claim.');
        }

        $email = $c->get('email');
        $name = $c->get('name');
        $displayName = $c->get('display_name');
        $picture = $c->get('picture');

        return new self(
            uid: $sub,
            email: is_string($email) ? $email : null,
            name: is_string($name) ? $name : (is_string($displayName) ? $displayName : null),
            picture: is_string($picture) ? $picture : null,
        );
    }

    public function resolvedEmail(): string
    {
        return $this->email ?? "{$this->uid}@firebase.local";
    }

    public function resolvedName(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : 'Firebase User';
    }
}
