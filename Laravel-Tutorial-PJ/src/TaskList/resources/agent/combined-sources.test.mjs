import { test } from 'node:test';
import assert from 'node:assert/strict';
import { combinedCursor, historyScan, validateCombinedScan, listCombined, extractCombined } from './combined-sources.mjs';
import { scanQuery, plainText } from './gmail-agent.mjs';
import { validateHistoryWindow } from './codex-history.mjs';
const after = Date.parse('2026-11-17T03:00:00Z') / 1000;
const day = Date.parse('2026-11-16T15:00:00Z') / 1000;
const scan = { after, before: after + 86400, codex_after: day, codex_before: day + 86400, instructions: '返信と未完了作業を優先' };
test('all mail has no sender or subject filter and both source windows are independently bounded', () => {
    assert.equal(scanQuery('MAIL', scan), `-in:spam -in:trash after:${after} before:${after + 86400}`);
    validateCombinedScan(scan, scanQuery, validateHistoryWindow);
    assert.equal(historyScan(scan).after, day);
    assert.throws(() => validateCombinedScan({ ...scan, codex_before: day + 86401 }, scanQuery, validateHistoryWindow), /INVALID_SCAN/);
    assert.throws(() => validateCombinedScan({ ...scan, before: scan.before + 1 }, scanQuery, validateHistoryWindow), /INVALID_SCAN/);
    for (const bad of ['not-json', '{"source":"OTHER","token":null}', '{"source":"MAIL","token":42}']) assert.throws(() => combinedCursor(bad), /INVALID_SCAN/);
    assert.throws(() => validateCombinedScan({ ...scan, messages: [{ source_type: 'OTHER' }] }, scanQuery, validateHistoryWindow), /INVALID_SCAN/);
});
test('durable listing traverses every mail page before all Codex pages without dropping either source', async () => {
    const calls = [];
    const listMail = async (scope, options) => { calls.push([scope, options.page_token, options.instructions]); return { messages: [{ id: options.page_token ? 'b2' : 'a1' }], next_page_token: options.page_token ? null : 'next-mail' }; };
    const listHistory = async options => { calls.push(['CODEX', options.page_token, options.instructions]); assert.equal(options.after, day); return { messages: [{ id: 'c3' }], next_page_token: null }; };
    const messages = []; let token = null;
    do { const page = await listCombined({ ...scan, page_token: token }, undefined, listMail, listHistory); messages.push(...page.messages); token = page.next_page_token; } while (token);
    assert.deepEqual(messages, [{ id: 'a1', source_type: 'GMAIL' }, { id: 'b2', source_type: 'GMAIL' }, { id: 'c3', source_type: 'CODEX' }]);
    assert.deepEqual(calls.map(call => call.slice(0, 2)), [['MAIL', null], ['MAIL', 'next-mail'], ['CODEX', null]]);
    assert.ok(calls.every(call => call[2] === scan.instructions));
});
test('a mixed batch routes each source with its own dates and shared custom criteria', async () => {
    const mail = { id: 'a1', source_type: 'GMAIL' }, history = { id: 'c3', source_type: 'CODEX' };
    const result = await extractCombined({ ...scan, messages: [mail, history], exclude_ids: ['a1'] }, () => {}, undefined,
        async (scope, update, signal, options) => { assert.equal(scope, 'MAIL'); assert.equal(options.after, after); assert.deepEqual(options.messages, [mail]); assert.equal(options.instructions, scan.instructions); return { read_count: 0, skipped_count: 1, processed_ids: ['a1'], candidates: [] }; },
        async options => { assert.equal(options.after, day); assert.deepEqual(options.messages, [history]); assert.equal(options.instructions, scan.instructions); return { read_count: 1, skipped_count: 0, processed_ids: ['c3'], candidates: [{ message_id: 'c3' }] }; });
    assert.deepEqual(result, { read_count: 1, skipped_count: 1, processed_ids: ['a1', 'c3'], candidates: [{ message_id: 'c3' }], next_page_token: null });
});
test('a source failure rejects the combined batch instead of publishing partial success', async () => {
    await assert.rejects(() => extractCombined({ ...scan, messages: [{ source_type: 'GMAIL' }, { source_type: 'CODEX' }] }, () => {}, undefined,
        async () => ({ read_count: 1, skipped_count: 0, processed_ids: ['a1'], candidates: [] }), async () => { throw new Error('source unavailable'); }), /source unavailable/);
});
test('HTML-only mail is readable as text while scripts, tags and attachments stay excluded', () => {
    assert.equal(plainText({ mime_type: 'text/html', body: { content: '<style>.x{}</style><script>alert(1)</script><p>Please reply &amp; confirm &#26085;&#x672c;</p>' } }), 'Please reply & confirm 日本');
    assert.equal(plainText({ mime_type: 'multipart/mixed', parts: [{ mime_type: 'text/html', filename: 'secret.html', body: { content: '<p>attachment</p>' } }, { mime_type: 'text/html', body: { content: '<p>Message only</p>' } }] }), 'Message only');
});
