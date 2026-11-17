import { describe, expect, it } from 'vitest';
import { selectedRowsOnPage, serializeCsv } from './csv';

const bengali = '\u09AE\u09BE\u09B9\u09AE\u09C1\u09A6';

describe('serializeCsv', () => {
    it('preserves Bengali Unicode and quotes delimiters and line breaks', () => {
        const csv = serializeCsv([{ key: 'name', label: 'Name' }, { key: 'note', label: 'Note' }], [{ name: bengali, note: 'a,"b"\nc' }]);
        expect(csv).toBe(`\uFEFF"Name","Note"\r\n"${bengali}","a,""b""\nc"`);
    });

    it('prefixes spreadsheet formulas with a text marker', () => {
        expect(serializeCsv([{ key: 'value', label: 'Value' }], [{ value: ' =1+1' }])).toBe('\uFEFF"Value"\r\n"\' =1+1"');
    });

    it('exports only selected records present on the current page', () => {
        const rows = [{ id: 1, name: 'First' }, { id: 2, name: 'Second' }];
        expect(selectedRowsOnPage(rows, [2, 99])).toEqual([{ id: 2, name: 'Second' }]);
    });
});
