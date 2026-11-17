<?php

namespace MasterCore\ProjectGenerator\Profile;

final readonly class CoreProfile
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    public function packageName(): string
    {
        return (string) $this->data['metapackage']['name'];
    }

    public function profileName(): string
    {
        return (string) $this->data['metapackage']['extra']['profile'];
    }

    public function schemaVersion(): int
    {
        return (int) $this->data['metapackage']['extra']['profile_schema'];
    }

    /** @return array<string, array<string, mixed>> */
    public function features(): array
    {
        return $this->data['metapackage']['extra']['optional_features'];
    }

    /** @return list<string> */
    public function baseline(): array
    {
        return $this->data['metapackage']['extra']['baseline'];
    }

    public function npmPackage(string $feature): array
    {
        return $this->data['npm_packages'][$feature];
    }

    public function composerConstraint(): string
    {
        return (string) $this->data['metapackage']['version_constraint'];
    }
}
