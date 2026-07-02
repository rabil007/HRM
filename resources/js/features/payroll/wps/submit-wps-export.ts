import { exportMethod as exportWps } from '@/actions/App/Http/Controllers/Payroll/WpsExportController';

export type WpsExportFormat = 'sif' | 'xlsx';

export function submitWpsExport(
    periodId: number,
    format: WpsExportFormat = 'sif',
    recordIds?: number[],
): void {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = exportWps.url();

    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (csrf) {
        const tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_token';
        tokenInput.value = csrf;
        form.appendChild(tokenInput);
    }

    const periodInput = document.createElement('input');
    periodInput.type = 'hidden';
    periodInput.name = 'period_id';
    periodInput.value = String(periodId);
    form.appendChild(periodInput);

    const formatInput = document.createElement('input');
    formatInput.type = 'hidden';
    formatInput.name = 'format';
    formatInput.value = format;
    form.appendChild(formatInput);

    if (recordIds !== undefined) {
        for (const recordId of recordIds) {
            const recordInput = document.createElement('input');
            recordInput.type = 'hidden';
            recordInput.name = 'record_ids[]';
            recordInput.value = String(recordId);
            form.appendChild(recordInput);
        }
    }

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
