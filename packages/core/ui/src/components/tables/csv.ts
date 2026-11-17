export interface CsvColumn { key: string; label: string }

function csvCell(value: unknown): string {
    const text = value === null || value === undefined ? '' : String(value);
    // Prevent spreadsheet formula execution, including when whitespace precedes the formula marker.
    const safe = /^[\t\r\n ]*[=+\-@]/.test(text) ? `'${text}` : text;
    return `"${safe.replace(/"/g, '""')}"`;
}

export function serializeCsv<T extends Record<string, unknown>>(columns: CsvColumn[], rows: T[]): string {
    const lines = [columns.map((column) => csvCell(column.label)).join(',')];
    for (const row of rows) lines.push(columns.map((column) => csvCell(row[column.key])).join(','));
    return `\uFEFF${lines.join('\r\n')}`;
}

export function selectedRowsOnPage<T extends Record<string, unknown>>(
    rows: T[],
    selectedIds: Array<string | number>,
    rowKey = 'id',
): T[] {
    const selected = new Set(selectedIds);
    return rows.filter((row) => selected.has(row[rowKey] as string | number));
}
