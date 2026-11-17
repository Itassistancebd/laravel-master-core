import type { CoreSelectOption } from '../../types';
type __VLS_Props = {
    id: string;
    modelValue: Array<string | number>;
    options: CoreSelectOption[];
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
};
declare const __VLS_export: import("vue").DefineComponent<__VLS_Props, {}, {}, {}, {}, import("vue").ComponentOptionsMixin, import("vue").ComponentOptionsMixin, {
    "update:modelValue": (value: (string | number)[]) => any;
}, string, import("vue").PublicProps, Readonly<__VLS_Props> & Readonly<{
    "onUpdate:modelValue"?: ((value: (string | number)[]) => any) | undefined;
}>, {
    disabled: boolean;
    modelValue: (string | number)[];
    placeholder: string;
    invalid: boolean;
}, {}, {}, {}, string, import("vue").ComponentProvideOptions, false, {}, any>;
declare const _default: typeof __VLS_export;
export default _default;
