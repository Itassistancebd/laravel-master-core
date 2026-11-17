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

    public function withDistributionIdentity(string $composerVendor, string $npmScope): self
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $composerVendor)
            || ! preg_match('/^@[a-z0-9_.-]+$/', $npmScope)
            || $composerVendor === 'example'
            || $npmScope === '@master-core') {
            throw new ProfileException('Distribution identity must use a non-placeholder Composer vendor and npm scope.');
        }

        [$sourceVendor] = explode('/', $this->packageName(), 2);
        [$sourceScope] = explode('/', (string) ($this->data['shared_npm']['name'] ?? ''), 2);
        if (! preg_match('/^[a-z0-9_.-]+$/', $sourceVendor) || ! preg_match('/^@[a-z0-9_.-]+$/', $sourceScope)) {
            throw new ProfileException('Bundled profile does not declare valid source package namespaces.');
        }

        $vendorPattern = '/^'.preg_quote($sourceVendor, '/').'(?=\/laravel-master-core(?:[-\/]|$))/';
        $data = $this->replaceStrings($this->data, static function (string $value) use ($composerVendor, $npmScope, $sourceScope, $vendorPattern): string {
            $value = preg_replace($vendorPattern, $composerVendor, $value) ?? $value;

            return str_starts_with($value, $sourceScope.'/')
                ? $npmScope.substr($value, strlen($sourceScope))
                : $value;
        });

        return new self($data);
    }

    private function replaceStrings(array $values, callable $replace): array
    {
        $replaced = [];
        foreach ($values as $key => $value) {
            $mappedKey = is_string($key) ? $replace($key) : $key;
            if (is_array($value)) {
                $replaced[$mappedKey] = $this->replaceStrings($value, $replace);
            } elseif (is_string($value)) {
                $replaced[$mappedKey] = $replace($value);
            } else {
                $replaced[$mappedKey] = $value;
            }
        }

        return $replaced;
    }
}
