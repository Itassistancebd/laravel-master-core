import type { CoreDataTableColumn } from '@itassistance-bd/ui';

export interface CrudPage<T> {
    items: T[];
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
}

export interface CrudQuery {
    search: string;
    filters: Record<string, string | number | boolean>;
    sort_by: string;
    sort_direction: 'asc' | 'desc';
    page: number;
    per_page: number;
}

export type CrudField =
    | { key: string; label: string; type: 'text' | 'email' | 'number'; required?: boolean; help?: string }
    | { key: string; label: string; type: 'boolean'; required?: boolean; help?: string }
    | { key: string; label: string; type: 'select'; options: Array<{ value: string | number; label: string }>; required?: boolean; help?: string }
    | { key: string; label: string; type: 'multiselect'; options: Array<{ value: string | number; label: string }>; required?: boolean; help?: string };

export interface CrudFilterDescriptor {
    key: string;
    label: string;
    options: Array<{ value: string; label: string }>;
}

export interface CrudPageDescriptor<T> {
    title: string;
    createLabel?: string;
    messages?: { created?: string; updated?: string; deleted?: string; deleteConfirmation?: string };
    description?: string;
    columns: CoreDataTableColumn[];
    fields: CrudField[];
    filters?: CrudFilterDescriptor[];
    rowKey?: keyof T & string;
}

export interface CrudTransport<T extends { id: string | number }> {
    list(query: CrudQuery): Promise<CrudPage<T>>;
    get?(id: string | number): Promise<T>;
    create(input: Record<string, unknown>): Promise<T>;
    update(id: string | number, input: Record<string, unknown>): Promise<T>;
    remove?(id: string | number): Promise<void>;
    bulk?(operation: string, ids: Array<string | number>): Promise<number>;
}

export interface CrudCapabilities {
    view?: boolean;
    create?: boolean;
    edit?: boolean;
    delete?: boolean;
    bulkDelete?: boolean;
}
