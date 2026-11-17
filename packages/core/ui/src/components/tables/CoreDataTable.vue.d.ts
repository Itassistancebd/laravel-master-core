export interface CoreDataTableColumn {
    key: string;
    label: string;
    sortable?: boolean;
}
declare const __VLS_export: <TRow extends Record<string, unknown>>(__VLS_props: NonNullable<Awaited<typeof __VLS_setup>>["props"], __VLS_ctx?: __VLS_PrettifyLocal<Pick<NonNullable<Awaited<typeof __VLS_setup>>, "attrs" | "emit" | "slots">>, __VLS_exposed?: NonNullable<Awaited<typeof __VLS_setup>>["expose"], __VLS_setup?: Promise<{
    props: import("vue").PublicProps & __VLS_PrettifyLocal<{
        columns: CoreDataTableColumn[];
        rows: TRow[];
        rowKey?: string;
        page: number;
        lastPage: number;
        total: number;
        perPage: number;
        selectedIds?: Array<string | number>;
        visibleColumnKeys?: string[];
        loading?: boolean;
        error?: string;
        sortBy?: string;
        sortDirection?: "asc" | "desc";
        selectable?: boolean;
        emptyMessage?: string;
    } & {
        "onUpdate:page"?: ((page: number) => any) | undefined;
        "onUpdate:perPage"?: ((perPage: number) => any) | undefined;
        "onUpdate:sort"?: ((key: string, direction: "desc" | "asc") => any) | undefined;
        "onUpdate:selectedIds"?: ((ids: (string | number)[]) => any) | undefined;
        "onUpdate:visibleColumnKeys"?: ((keys: string[]) => any) | undefined;
    }> & (typeof globalThis extends {
        __VLS_PROPS_FALLBACK: infer P;
    } ? P : {});
    expose: (exposed: {}) => void;
    attrs: any;
    slots: {
        [x: `cell-${string}`]: ((props: {
            row: TRow;
        }) => any) | undefined;
    } & {
        actions?: (props: {
            row: TRow;
        }) => any;
    };
    emit: ((evt: "update:page", page: number) => void) & ((evt: "update:perPage", perPage: number) => void) & ((evt: "update:sort", key: string, direction: "desc" | "asc") => void) & ((evt: "update:selectedIds", ids: (string | number)[]) => void) & ((evt: "update:visibleColumnKeys", keys: string[]) => void);
}>) => import("vue").VNode & {
    __ctx?: NonNullable<Awaited<typeof __VLS_setup>>;
};
declare const _default: typeof __VLS_export;
export default _default;
type __VLS_PrettifyLocal<T> = (T extends any ? {
    [K in keyof T]: T[K];
} : {
    [K in keyof T as K]: T[K];
}) & {};
