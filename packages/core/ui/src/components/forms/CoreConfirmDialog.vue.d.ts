type __VLS_Props = {
    open: boolean;
    title: string;
    message: string;
    confirmLabel?: string;
    destructive?: boolean;
};
declare const __VLS_export: import("vue").DefineComponent<__VLS_Props, {}, {}, {}, {}, import("vue").ComponentOptionsMixin, import("vue").ComponentOptionsMixin, {
    confirm: () => any;
    cancel: () => any;
}, string, import("vue").PublicProps, Readonly<__VLS_Props> & Readonly<{
    onConfirm?: (() => any) | undefined;
    onCancel?: (() => any) | undefined;
}>, {
    confirmLabel: string;
    destructive: boolean;
}, {}, {}, {}, string, import("vue").ComponentProvideOptions, false, {}, any>;
declare const _default: typeof __VLS_export;
export default _default;
