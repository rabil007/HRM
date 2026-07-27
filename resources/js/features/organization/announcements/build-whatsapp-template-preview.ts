export function plainTextFromHtml(html: string): string {
    const withBreaks = html
        .replace(/<(?:br\s*\/?|\/(?:p|div|li|h[1-6]|blockquote))\s*>/gi, '\n')
        .replace(/<[^>]+>/g, ' ');

    const decoded = withBreaks
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&quot;/gi, '"')
        .replace(/&#39;/gi, "'");

    return decoded
        .replace(/\r\n|\r/g, '\n')
        .replace(/[^\S\n]+/g, ' ')
        .replace(/ *\n */g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

export function buildWhatsAppTemplatePreview(input: {
    bodyPreview?: string | null;
    companyName: string;
    title: string;
    bodyHtml: string;
    priorityLabel: string;
    viewLink: string;
}): string {
    const summary =
        plainTextFromHtml(input.bodyHtml).slice(0, 500) ||
        input.title.trim() ||
        'Announcement summary';
    const company = input.companyName.trim() || 'Company';
    const title = input.title.trim() || 'Announcement title';
    const priority = input.priorityLabel.trim() || 'Normal';
    const viewLink =
        input.viewLink.trim() || 'N/A';

    const template =
        input.bodyPreview?.trim() ||
        'Hello,\nA company notice from {{1}} is available for you.\n\nTitle: {{2}}\nSummary: {{3}}\nPriority: {{4}}\nView link: {{5}}';

    return template
        .replaceAll('{{company}}', company)
        .replaceAll('{{title}}', title)
        .replaceAll('{{message}}', summary)
        .replaceAll('{{priority}}', priority)
        .replaceAll('{{url}}', viewLink)
        .replaceAll('{{1}}', company)
        .replaceAll('{{2}}', title)
        .replaceAll('{{3}}', summary)
        .replaceAll('{{4}}', priority)
        .replaceAll('{{5}}', viewLink);
}
