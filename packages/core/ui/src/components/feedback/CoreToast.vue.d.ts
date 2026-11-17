type __VLS_Props = {
    message: string;
    variant?: 'success' | 'error' | 'info';
};
declare const __VLS_export: import("vue").DefineComponent<__VLS_Props, {}, {}, {}, {}, import("vue").ComponentOptionsMixin, import("vue").ComponentOptionsMixin, {
    dismiss: () => any;
}, string, import("vue").PublicProps, Readonly<__VLS_Props> & Readonly<{
    onDismiss?: (() => any) | undefined;
}>, {
    variant: "error" | "success" | "info";
}, {}, {}, {}, string, import("vue").ComponentProvideOptions, false, {}, any>;
declare const _default: typeof __VLS_export;
export default _default;
