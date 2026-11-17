export type ModuleReadiness = 'not_installed' | 'dependency_missing' | 'provider_not_loaded' | 'runtime_not_verified';

export interface ModuleDependency {
    package_name: string;
    installed: boolean;
}

export interface ModuleRecord {
    key: string;
    name: string;
    description: string;
    composer_package: string;
    installation: { installed: boolean; version: string | null };
    dependencies: ModuleDependency[];
    provider_loaded: boolean;
    readiness_status: ModuleReadiness;
    documentation: string;
}

export interface ModuleFilters {
    search?: string;
    readiness?: ModuleReadiness | '';
    sort_by?: 'name' | 'key' | 'composer_package' | 'readiness_status';
    sort_direction?: 'asc' | 'desc';
    per_page?: number;
    page?: number;
}

export interface ModulePageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ModulePage {
    modules: ModuleRecord[];
    meta: ModulePageMeta;
}
