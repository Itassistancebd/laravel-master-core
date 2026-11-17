export interface SettingField {
    key: string;
    label: string;
    type: string;
    // Field values follow the registry's server-validated type, which varies by setting.
    value: unknown;
    default?: unknown;
    description?: string | null;
    required?: boolean;
    options?: Array<{ value: string | number; label: string }>;
}

export interface SettingsGroup {
    key: string;
    label: string;
    settings: Record<string, SettingField>;
}

export interface ApiEnvelope<T> {
    success: boolean;
    data: T;
    message?: string;
}
