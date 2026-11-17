export interface ManagedUser {
    [key: string]: unknown;
    id: number | string;
    name: string;
    email: string;
    email_verified_at: string | null;
    is_active: boolean;
    created_at: string | null;
    deleted_at: string | null;
}

export interface UserPageMeta {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
}

export interface ApiEnvelope<T> {
    success: boolean;
    data: T;
    message?: string;
    meta?: UserPageMeta;
}

export interface UserCapabilities {
    view: boolean;
    create: boolean;
    update: boolean;
    status: boolean;
    delete: boolean;
    restore: boolean;
    force_delete: boolean;
    password_reset: boolean;
    verification: boolean;
    manage: boolean;
    manage_roles: boolean;
    export: boolean;
    import: boolean;
}

export interface UserImportRowResult {
    row: number;
    name: string;
    email: string;
    status: 'valid' | 'skipped' | 'failed' | 'imported';
    errors: string[];
}

export interface UserImportPreview {
    preview_token: string;
    expires_at: string;
    summary: { total_rows: number; valid: number; skipped: number; failed: number };
    rows: UserImportRowResult[];
}

export interface UserImportResult {
    imported: number;
    skipped: number;
    failed: number;
    rows: UserImportRowResult[];
}

export type BulkUserOperation = 'activate' | 'deactivate' | 'trash' | 'restore';
