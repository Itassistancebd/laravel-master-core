<?php

namespace MasterCore\Settings\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MasterCore\Settings\Models\Setting;

final class SettingsManager
{
    public function __construct(private readonly CacheRepository $cache) {}

    public function groups(bool $publicOnly = false): array
    {
        $groups = [];

        foreach (array_keys($this->definitions()) as $group) {
            if ($publicOnly && ! $this->hasPublicFields($group)) {
                continue;
            }

            $groups[$group] = $this->group($group, $publicOnly);
        }

        return $groups;
    }

    public function group(string $group, bool $publicOnly = false): array
    {
        $groupDefinition = $this->groupDefinition($group);
        $overrides = $this->remember($this->groupCacheKey($group), fn () => $this->storedValues($group));
        $fields = [];

        foreach ($groupDefinition['fields'] as $key => $definition) {
            $this->assertSafeDefinition($group, $key, $definition);

            if ($publicOnly && ! ($definition['public'] ?? false)) {
                continue;
            }

            $field = [
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'value' => array_key_exists($key, $overrides) ? $overrides[$key] : $definition['default'],
                'required' => in_array('required', $definition['rules'] ?? [], true),
            ];

            if (! $publicOnly) {
                $field['default'] = $definition['default'];
                $field['description'] = $definition['description'] ?? null;
                $field['options'] = $this->presentOptions($this->options($definition['options'] ?? null));
            }

            $fields[$key] = $field;
        }

        return [
            'key' => $group,
            'label' => $groupDefinition['label'],
            'settings' => $fields,
        ];
    }

    public function publicSettings(): array
    {
        return $this->remember($this->publicCacheKey(), function (): array {
            $public = [];

            foreach ($this->groups(publicOnly: true) as $group => $data) {
                foreach ($data['settings'] as $key => $field) {
                    $public[$group][$key] = $field['value'];
                }
            }

            return $public;
        });
    }

    public function validationRules(string $group): array
    {
        $definition = $this->groupDefinition($group);
        $keys = array_keys($definition['fields']);
        $rules = [
            'settings' => ['required', 'array:'.implode(',', $keys), 'min:1'],
        ];

        foreach ($definition['fields'] as $key => $field) {
            $this->assertSafeDefinition($group, $key, $field);
            $rules['settings.'.$key] = $field['rules'];

            if (($field['type'] ?? null) === 'searchable_multiselect') {
                $options = $this->options($field['options'] ?? null);
                $rules['settings.'.$key.'.*'] = array_merge(
                    $field['item_rules'] ?? ['required', 'string'],
                    ['in:'.implode(',', array_keys($options))],
                );
            } elseif (isset($field['options'])) {
                $options = $this->options($field['options']);
                $rules['settings.'.$key][] = 'in:'.implode(',', array_keys($options));
            }
        }

        if (isset($definition['fields']['decimal_separator'], $definition['fields']['thousands_separator'])) {
            $rules['settings.decimal_separator'] = array_merge(
                $rules['settings.decimal_separator'],
                ['different:settings.thousands_separator'],
            );
            $rules['settings.thousands_separator'] = array_values(array_filter(
                $rules['settings.thousands_separator'],
                fn (string $rule): bool => ! str_starts_with($rule, 'different:'),
            ));
            $rules['settings.thousands_separator'][] = 'different:settings.decimal_separator';
        }

        return $rules;
    }

    public function updateGroup(string $group, array $values): array
    {
        $definition = $this->groupDefinition($group);
        $unknown = array_diff(array_keys($values), array_keys($definition['fields']));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => ['Unknown setting keys: '.implode(', ', $unknown).'.'],
            ]);
        }

        DB::transaction(function () use ($group, $values, $definition): void {
            $changedKeys = [];
            foreach ($values as $key => $value) {
                $this->assertSafeDefinition($group, $key, $definition['fields'][$key]);

                Setting::query()->updateOrCreate(
                    ['group_name' => $group, 'setting_key' => $key],
                    ['value' => $this->normalizeValue($definition['fields'][$key], $value)],
                );
                $changedKeys[] = $key;
            }

            // The audit listener writes on this connection; failure rolls back the settings transaction.
            event('master-core.audit.record.v1', [[
                'event_name' => 'settings.group_updated',
                'metadata' => ['group' => $group, 'changed_keys' => $changedKeys],
            ]]);
        });

        $this->invalidate($group);

        return $this->group($group);
    }

    public function resetGroup(string $group): array
    {
        $this->groupDefinition($group);

        DB::transaction(function () use ($group): void {
            $changedKeys = Setting::query()->where('group_name', $group)->pluck('setting_key')->all();
            Setting::query()->where('group_name', $group)->delete();
            event('master-core.audit.record.v1', [[
                'event_name' => 'settings.group_reset',
                'metadata' => ['group' => $group, 'changed_keys' => $changedKeys],
            ]]);
        });
        $this->invalidate($group);

        return $this->group($group);
    }

    public function storeBrandAsset(string $assetType, string $path): array
    {
        $key = match ($assetType) {
            'logo' => 'logo_path',
            'favicon' => 'favicon_path',
            default => throw ValidationException::withMessages([
                'asset_type' => ['Choose either a logo or favicon asset.'],
            ]),
        };

        return $this->updateGroup('branding', [$key => $path]);
    }

    private function definitions(): array
    {
        return (array) config('master-core-settings.groups', []);
    }

    private function groupDefinition(string $group): array
    {
        $definition = $this->definitions()[$group] ?? null;

        if (! is_array($definition) || ! isset($definition['label'], $definition['fields'])) {
            abort(404, 'Unknown settings group.');
        }

        return $definition;
    }

    private function storedValues(string $group): array
    {
        return Setting::query()
            ->where('group_name', $group)
            ->get(['setting_key', 'value'])
            ->mapWithKeys(fn (Setting $setting): array => [$setting->setting_key => $setting->value])
            ->all();
    }

    private function hasPublicFields(string $group): bool
    {
        return collect($this->groupDefinition($group)['fields'])
            ->contains(fn (array $field): bool => (bool) ($field['public'] ?? false));
    }

    private function options(string|array|null $options): array
    {
        if (is_string($options)) {
            if ($options === 'timezones') {
                return array_combine($zones = \DateTimeZone::listIdentifiers(), $zones) ?: [];
            }

            return (array) config('master-core-settings.options.'.$options, []);
        }

        return (array) $options;
    }

    private function presentOptions(array $options): array
    {
        $presented = [];

        foreach ($options as $value => $option) {
            $presented[] = [
                'value' => is_int($value) ? $value : (string) $value,
                'label' => is_array($option) ? (string) ($option['label'] ?? $value) : (string) $option,
            ];
        }

        return $presented;
    }

    private function normalizeValue(array $definition, mixed $value): mixed
    {
        return match ($definition['type'] ?? null) {
            'number' => (int) $value,
            'searchable_multiselect' => array_values((array) $value),
            default => $value,
        };
    }

    private function assertSafeDefinition(string $group, string $key, array $definition): void
    {
        $forbidden = '/(?:password|secret|token|credential|api[_-]?key|access[_-]?key|private[_-]?key)/i';

        if (($definition['sensitive'] ?? false) || preg_match($forbidden, $group.'.'.$key)) {
            throw new \LogicException("Sensitive setting [{$group}.{$key}] cannot be stored in ordinary settings.");
        }

        if (($definition['public'] ?? false) && ($definition['sensitive'] ?? false)) {
            throw new \LogicException("Sensitive setting [{$group}.{$key}] cannot be public.");
        }
    }

    private function remember(string $key, \Closure $callback): mixed
    {
        if (! config('master-core-settings.cache.enabled', true)) {
            return $callback();
        }

        $ttl = max(0, (int) config('master-core-settings.cache.ttl_seconds', 300));

        return $ttl === 0 ? $callback() : $this->cache->remember($key, $ttl, $callback);
    }

    private function invalidate(string $group): void
    {
        if (! config('master-core-settings.cache.enabled', true)) {
            return;
        }

        $this->cache->forget($this->groupCacheKey($group));
        $this->cache->forget($this->publicCacheKey());
    }

    private function groupCacheKey(string $group): string
    {
        return trim((string) config('master-core-settings.cache.prefix', 'master-core:settings:v1'), ':').':group:'.$group;
    }

    private function publicCacheKey(): string
    {
        return trim((string) config('master-core-settings.cache.prefix', 'master-core:settings:v1'), ':').':public';
    }
}
