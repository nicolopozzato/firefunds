<?php

namespace App\Update;

/** A published FireFunds version: "0.10.0" (no "v"), its release notes, when it came out. */
final readonly class Release
{
    public function __construct(
        public string $version,
        public string $url,
        public ?string $publishedAt,
    ) {
    }

    /** @param array<string, mixed> $data what UpdateCheck stored */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['version'], (string) $data['url'], isset($data['publishedAt']) ? (string) $data['publishedAt'] : null);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['version' => $this->version, 'url' => $this->url, 'publishedAt' => $this->publishedAt];
    }
}
