export function buildEmailPreview(input: {
    companyName: string;
    title: string;
    bodyHtml: string;
    priorityLabel: string;
}): { subject: string; html: string } {
    const company = input.companyName.trim() || 'Company';
    const title = input.title.trim() || 'Announcement title';
    const priority = input.priorityLabel.trim() || 'Normal';
    const publishedAt = new Date().toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

    const subject = `${priority} Announcement — ${title}`;

    const html = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .email-border { padding: 28px 32px 16px; border-bottom: 1px solid #e4e4e7; }
        .email-kicker { margin: 0 0 8px; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #71717a; }
        .email-heading { margin: 0; font-size: 20px; line-height: 1.4; color: #18181b; }
        .email-muted { margin: 8px 0 0; font-size: 13px; color: #71717a; }
        .email-text { font-size: 15px; line-height: 1.6; color: #3f3f46; }
        .email-text p { margin: 0 0 16px; }
        .email-text p:last-child { margin-bottom: 0; }
        .email-text a { color: #2563eb; text-decoration: underline; }
        .email-text ul, .email-text ol { margin: 0 0 16px; padding-left: 24px; }
        .email-text li { margin-bottom: 8px; }
    </style>
</head>
<body>
    <div class="email-container">
        <table cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr>
                <td class="email-border">
                    <p class="email-kicker">${company}</p>
                    <h1 class="email-heading">${title}</h1>
                    <p class="email-muted">Priority: ${priority} · Published ${publishedAt}</p>
                </td>
            </tr>
            <tr>
                <td style="padding: 24px 32px;">
                    <div class="email-text">
                        ${input.bodyHtml || '<p>No content provided</p>'}
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
    `.trim();

    return { subject, html };
}
