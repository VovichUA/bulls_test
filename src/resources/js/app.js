import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('mappingEditor', (initial, headerUrl) => ({
    rows: Array.isArray(initial) && initial.length ? initial.map(row => ({ ...row, nullable: Boolean(Number(row.nullable)), key: Boolean(Number(row.key)) })) : [],
    headers: [],
    loading: false,
    headerMessage: '',
    init() {
        if (!this.rows.length) this.addRow();
    },
    addRow() {
        if (this.rows.length < 100) this.rows.push({ source_column: '', target_column: '', data_type: 'string', nullable: true, key: false, format: '', type_options_json: '{}' });
    },
    removeRow(index) {
        if (this.rows.length > 1) this.rows.splice(index, 1);
    },
    async loadHeaders() {
        if (this.loading) return;
        this.loading = true;
        this.headerMessage = '';
        try {
            const response = await fetch(headerUrl, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Не вдалося завантажити заголовки.');
            const ambiguous = data.ambiguous || [];
            this.headers = [...new Set(data.headers.filter(header => header !== null && !ambiguous.includes(header)))];
            this.headerMessage = `Доступних заголовків: ${this.headers.length}.` + (ambiguous.length ? ` Неоднозначні заголовки не пропонуються: ${ambiguous.join(', ')}.` : '');
        } catch (error) {
            this.headerMessage = error instanceof SyntaxError ? 'Відповідь недоступна. Оновіть сторінку та перевірте вхід.' : error.message;
        } finally {
            this.loading = false;
        }
    },
}));

Alpine.start();
