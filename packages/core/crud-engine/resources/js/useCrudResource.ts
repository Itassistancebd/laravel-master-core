import { reactive, ref, shallowRef } from 'vue';
import type { Ref, ShallowRef } from 'vue';
import type { CrudPage, CrudQuery, CrudTransport } from './types';

export interface CrudResourceState<T extends { id: string | number }> {
    rows: ShallowRef<T[]>;
    meta: ShallowRef<CrudPage<T>>;
    query: CrudQuery;
    loading: Ref<boolean>;
    error: Ref<string>;
    notice: Ref<string>;
    noticeVariant: Ref<'success' | 'error'>;
    fieldErrors: Ref<Record<string, string[]>>;
    selectedIds: Ref<Array<string | number>>;
    load(): Promise<void>;
    search(term: string): Promise<void>;
    setPage(page: number): Promise<void>;
    setPerPage(size: number): Promise<void>;
    setSort(key: string, direction: 'asc' | 'desc'): Promise<void>;
    setFilter(key: string, value: string): Promise<void>;
    save(input: Record<string, unknown>, id?: string | number): Promise<T | null>;
    remove(id: string | number): Promise<void>;
    removeSelected(): Promise<void>;
}

/** Owns server query and form state while leaving validation and business rules to the resource API. */
export function useCrudResource<T extends { id: string | number }>(transport: CrudTransport<T>, sortBy: string, messages: { created?: string; updated?: string; deleted?: string } = {}): CrudResourceState<T> {
    const rows = shallowRef<T[]>([]);
    const meta = shallowRef<CrudPage<T>>({ items: [], current_page: 1, per_page: 25, total: 0, last_page: 1, from: null, to: null });
    const query = reactive<CrudQuery>({ search: '', filters: {}, sort_by: sortBy, sort_direction: 'asc', page: 1, per_page: 25 });
    const loading = ref(false);
    const error = ref('');
    const notice = ref('');
    const noticeVariant = ref<'success' | 'error'>('success');
    const fieldErrors = ref<Record<string, string[]>>({});
    const selectedIds = ref<Array<string | number>>([]);

    function message(exception: unknown): string {
        const candidate = exception as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
        fieldErrors.value = candidate.response?.data?.errors ?? {};
        if (candidate.response?.status === 401) return 'Your session has expired. Sign in again to continue.';
        if (candidate.response?.status === 403) return 'You are not authorized to perform this action.';
        return candidate.response?.data?.message ?? 'The request could not be completed. Please try again.';
    }

    async function load(): Promise<void> {
        loading.value = true;
        error.value = '';
        try {
            const result = await transport.list({ ...query, filters: { ...query.filters } });
            rows.value = result.items;
            meta.value = result;
        } catch (exception) {
            error.value = message(exception);
        } finally {
            loading.value = false;
        }
    }

    async function search(term: string): Promise<void> { query.search = term; query.page = 1; await load(); }
    async function setPage(page: number): Promise<void> { query.page = page; await load(); }
    async function setPerPage(size: number): Promise<void> { query.per_page = size; query.page = 1; await load(); }
    async function setSort(key: string, direction: 'asc' | 'desc'): Promise<void> { query.sort_by = key; query.sort_direction = direction; query.page = 1; await load(); }
    async function setFilter(key: string, value: string): Promise<void> {
        if (value === '') delete query.filters[key];
        else query.filters[key] = value;
        query.page = 1;
        await load();
    }

    async function save(input: Record<string, unknown>, id?: string | number): Promise<T | null> {
        loading.value = true;
        fieldErrors.value = {};
        try {
            const record = id === undefined ? await transport.create(input) : await transport.update(id, input);
            notice.value = id === undefined ? (messages.created ?? 'Record created.') : (messages.updated ?? 'Record updated.');
            noticeVariant.value = 'success';
            await load();
            return record;
        } catch (exception) {
            notice.value = message(exception);
            noticeVariant.value = 'error';
            return null;
        } finally {
            loading.value = false;
        }
    }

    async function remove(id: string | number): Promise<void> {
        if (!transport.remove) return;
        loading.value = true;
        try {
            await transport.remove(id);
            notice.value = messages.deleted ?? 'Record deleted.';
            noticeVariant.value = 'success';
            await load();
        } catch (exception) {
            notice.value = message(exception);
            noticeVariant.value = 'error';
        } finally {
            loading.value = false;
        }
    }

    async function removeSelected(): Promise<void> {
        if (!transport.bulk || selectedIds.value.length === 0) return;
        loading.value = true;
        try {
            const affected = await transport.bulk('delete', [...selectedIds.value]);
            notice.value = `${affected} records moved to trash.`;
            noticeVariant.value = 'success';
            selectedIds.value = [];
            await load();
        } catch (exception) {
            notice.value = message(exception);
            noticeVariant.value = 'error';
        } finally {
            loading.value = false;
        }
    }

    return { rows, meta, query, loading, error, notice, noticeVariant, fieldErrors, selectedIds, load, search, setPage, setPerPage, setSort, setFilter, save, remove, removeSelected };
}
