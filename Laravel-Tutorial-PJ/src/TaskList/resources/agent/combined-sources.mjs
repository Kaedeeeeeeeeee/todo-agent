export function combinedCursor(token) {
    if (token == null) return { source: 'MAIL', token: null };
    let cursor;
    try { cursor = JSON.parse(token); } catch { throw new Error('INVALID_SCAN'); }
    if (!cursor || !['MAIL', 'CODEX'].includes(cursor.source) || Object.keys(cursor).some(key => !['source', 'token'].includes(key))
        || (cursor.token !== null && (typeof cursor.token !== 'string' || cursor.token.length > 2048))) throw new Error('INVALID_SCAN');
    return cursor;
}
export const historyScan = scan => ({ ...scan, after: scan.codex_after, before: scan.codex_before, page_token: null });
export function validateCombinedScan(scan, validateMail, validateHistory) {
    combinedCursor(scan.page_token);
    validateMail('MAIL', { ...scan, page_token: null });
    validateHistory(historyScan(scan));
    if (scan.messages && (!Array.isArray(scan.messages) || !scan.messages.length || scan.messages.length > 6
        || scan.messages.some(message => !['GMAIL', 'CODEX'].includes(message.source_type)))) throw new Error('INVALID_SCAN');
}
export async function listCombined(scan, signal, listMail, listHistory) {
    const cursor = combinedCursor(scan.page_token);
    const result = cursor.source === 'MAIL'
        ? await listMail('MAIL', { ...scan, page_token: cursor.token }, signal)
        : await listHistory({ ...historyScan(scan), page_token: cursor.token });
    const next = result.next_page_token != null ? { source: cursor.source, token: result.next_page_token }
        : cursor.source === 'MAIL' ? { source: 'CODEX', token: null } : null;
    return { messages: result.messages.map(message => ({ ...message, source_type: cursor.source === 'MAIL' ? 'GMAIL' : 'CODEX' })),
        next_page_token: next ? JSON.stringify(next) : null };
}
export async function extractCombined(scan, update, signal, readMail, readHistory) {
    const mail = scan.messages.filter(message => message.source_type === 'GMAIL');
    const history = scan.messages.filter(message => message.source_type === 'CODEX');
    const results = [];
    if (mail.length) results.push(await readMail('MAIL', update, signal, { ...scan, messages: mail, page_token: null }));
    if (history.length) results.push(await readHistory({ ...historyScan(scan), messages: history }, update, signal));
    return { read_count: results.reduce((sum, result) => sum + result.read_count, 0),
        skipped_count: results.reduce((sum, result) => sum + result.skipped_count, 0),
        processed_ids: results.flatMap(result => result.processed_ids), candidates: results.flatMap(result => result.candidates), next_page_token: null };
}
