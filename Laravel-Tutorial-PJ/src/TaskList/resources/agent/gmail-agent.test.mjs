import { test } from 'node:test';
import assert from 'node:assert/strict';
import { plainText, validateCandidates, validateMailboxSources } from './gmail-agent.mjs';
const source = { id: 'abc123', subject: 'Real review', sender: 'review@example.test', received_at: '2026-11-11', body: 'Please check this issue.' };
const candidate = { message_id: 'abc123', title: '確認する', description: '内容を確認する', due_date: null, reason: 'Review requested', evidence: 'check this issue' };
test('accepts only candidates with a real source and exact evidence', () => {
    assert.equal(validateCandidates({ candidates: [candidate] }, [source])[0].subject, source.subject);
    for (const bad of [{ message_id: 'invented' }, { evidence: 'fabricated quote' }, { due_date: 'tomorrow' }, { title: 'x'.repeat(101) }]) {
        assert.throws(() => validateCandidates({ candidates: [{ ...candidate, ...bad }] }, [source]), /INVALID_EVIDENCE/);
    }
    assert.throws(() => validateCandidates({ candidates: [candidate, candidate] }, [source]), /INVALID_EVIDENCE/);
    assert.equal(validateCandidates({ candidates: [{ ...candidate, due_date: '2026-12-31' }] }, [source])[0].due_date, null);
    assert.equal(validateCandidates({ candidates: [{ ...candidate, due_date: '2026-12-31' }] }, [{ ...source, body: source.body + '期限は2026年12月31日' }])[0].due_date, '2026-12-31');
    assert.deepEqual(validateCandidates({ candidates: [] }, [source]), []);
});
test('reads plain MIME content without executing HTML or reading attachments', () => {
    assert.equal(plainText({ parts: [
        { mime_type: 'text/html', body: { content: '<script>bad()</script>' } },
        { mime_type: 'text/plain', body: { content: 'Real message' } },
        { mime_type: 'text/plain', filename: 'attachment.txt', body: { content: 'Attachment' } },
    ] }), 'Real message');
    assert.equal(plainText({ mime_type: 'text/plain', body: { base64_url_content: Buffer.from('日本語').toString('base64url') } }), '日本語');
});

async function reader(t, options) {
    const { createServer } = await import('./gmail-agent.mjs');
    process.env.TASK_AGENT_TOKEN = 'local-test-token-that-is-at-least-32-characters';
    const server = createServer(options);
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    t.after(() => new Promise((resolve) => server.close(resolve)));
    return async (path, method = 'GET', body, authorized = true) => {
        const response = await fetch(`http://127.0.0.1:${server.address().port}${path}`, { method,
            headers: { 'Content-Type': 'application/json', ...(authorized ? { Authorization: `Bearer ${process.env.TASK_AGENT_TOKEN}` } : {}) },
            body: body === undefined ? undefined : JSON.stringify(body) });
        return { status: response.status, body: await response.json() };
    };
}
const tick = () => new Promise((resolve) => setImmediate(resolve));
const runA = '/runs/11111111-1111-4111-8111-111111111111';
const runB = '/runs/22222222-2222-4222-8222-222222222222';
test('health exposes busy state without reading emails and repeated verification is idempotent', async (t) => {
    let finish;
    let calls = 0;
    const request = await reader(t, { verifyConnection: () => { calls++; return new Promise((resolve) => { finish = resolve; }); } });
    assert.equal((await request('/health', 'GET', undefined, false)).status, 401);
    assert.deepEqual((await request('/health')).body, { available: true, busy: false, retry_after: 0 });
    assert.equal((await request(runA, 'POST', { kind: 'verify' })).status, 202);
    assert.equal((await request(runA, 'POST', { kind: 'verify' })).status, 202);
    assert.equal(calls, 1);
    assert.equal((await request('/health')).body.busy, true);
    const blocked = await request(runB, 'POST', { scope: 'GITHUB' });
    assert.equal(blocked.status, 429);
    assert.equal(blocked.body.code, 'BUSY');
    finish({ account: 'owner@example.test' });
    await tick();
    assert.equal((await request(runA)).body.status, 'succeeded');
    assert.equal((await request('/health')).body.retry_after, 0);
});
test('cooldown reports the precise remaining time instead of connection failure', async (t) => {
    let currentTime = 100000;
    const request = await reader(t, { now: () => currentTime, runExtraction: async () => ({ read_count: 0, candidates: [] }) });
    assert.equal((await request(runA, 'POST', { scope: 'GITHUB' })).status, 202);
    await tick();
    currentTime = 159999;
    assert.equal((await request('/health')).body.retry_after, 1);
    const blocked = await request(runB, 'POST', { scope: 'GITHUB' });
    assert.equal(blocked.status, 429);
    assert.equal(blocked.body.retry_after, 1);
    currentTime = 160000;
    assert.equal((await request(runB, 'POST', { scope: 'GITHUB' })).status, 202);
    await tick();
});
test('disconnect cancellation aborts an active read and cannot later publish success', async (t) => {
    let aborted = false;
    const request = await reader(t, { runExtraction: (scope, update, signal) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => { aborted = true; reject(new Error('aborted')); });
    }) });
    await request(runA, 'POST', { scope: 'GITHUB' });
    assert.equal((await request(runA, 'DELETE')).body.cancelled, true);
    await tick();
    assert.equal(aborted, true);
    assert.equal((await request(runA)).body.status, 'failed');
    assert.equal((await request('/health')).body.busy, false);
});

test('mail source identity must match the verified mailbox before bodies are read', () => {
    const mail = { display_url: 'https://mail.google.com/mail/u/?authuser=owner%40example.test#all/abc123' };
    assert.doesNotThrow(() => validateMailboxSources([mail], 'owner@example.test'));
    assert.throws(() => validateMailboxSources([mail], 'other@example.test'), /GMAIL_ACCOUNT_CHANGED/);
    assert.throws(() => validateMailboxSources([{ display_url: 'https://example.test/?authuser=owner%40example.test' }], 'owner@example.test'), /GMAIL_ACCOUNT_CHANGED/);
    assert.throws(() => validateMailboxSources([{ display_url: null }], 'owner@example.test'), /GMAIL_ACCOUNT_CHANGED/);
});

test('incremental query validates bounds and scope without allowing query injection', async () => {
    const { scanQuery } = await import('./gmail-agent.mjs');
    assert.equal(scanQuery('BIZMATES', { after: 100, before: 200 }), '-in:spam -in:trash from:bizmates.co.jp after:100 before:200');
    for (const scan of [{ after: '100 OR in:anywhere', before: 200 }, { after: 200, before: 100 }, { after: 100, before: 200, exclude_ids: ['bad-id'] }]) {
        assert.throws(() => scanQuery('BIZMATES', scan), /INVALID_SCAN/);
    }
});

test('pagination forwards frozen query and skips already processed mail before body reads', async () => {
    const { scanPage } = await import('./gmail-agent.mjs');
    process.env.TASK_AGENT_ACCOUNT = 'owner@example.test';
    const mails = ['abc123', 'def456'].map(id => ({ id, display_url: `https://mail.google.com/mail/u/?authuser=owner%40example.test#all/${id}` }));
    const result = await scanPage('BIZMATES', { after: 100, before: 200, page_token: 'page-two', exclude_ids: ['abc123'] }, undefined, async (prompt, tool) => {
        assert.equal(tool, 'search_emails');
        assert.ok(prompt.includes('"next_page_token":"page-two"'));
        assert.ok(prompt.includes('after:100 before:200'));
        return { emails: mails, next_page_token: 'page-three' };
    });
    assert.deepEqual(result.found.map(mail => mail.id), ['def456']);
    assert.deepEqual(result.metadata, { processed_ids: ['abc123', 'def456'], skipped_count: 1, next_page_token: 'page-three' });
});

test('reader forwards scan windows and rejects malformed scan before executing extraction', async t => {
    let received;
    const request = await reader(t, { runExtraction: async (scope, update, signal, scan) => { received = scan; return { read_count: 0, candidates: [] }; } });
    assert.equal((await request(runA, 'POST', { scope: 'BIZMATES', scan: { after: 'x', before: 200 } })).status, 422);
    const scan = { after: 100, before: 200, exclude_ids: ['abc123'] };
    assert.equal((await request(runA, 'POST', { scope: 'BIZMATES', scan })).status, 202);
    await tick();
    assert.deepEqual(received, scan);
});

test('daily search refuses windows beyond 24 hours', async () => {
    const { scanQuery } = await import('./gmail-agent.mjs');
    assert.throws(() => scanQuery('GITHUB', { after: 100, before: 86501 }), /INVALID_SCAN/);
    assert.ok(scanQuery('GITHUB').includes('newer_than:1d'));
});

test('listing covers a full page and rejects headers outside the frozen daily window', async () => {
    const { listPage } = await import('./gmail-agent.mjs');
    process.env.TASK_AGENT_ACCOUNT = 'owner@example.test';
    const mail = { id: 'abc123', subject: 'Review', from_: 'work@example.test', email_ts: '2026-11-18T10:00:00Z', display_url: 'https://mail.google.com/mail/u/?authuser=owner%40example.test#all/abc123' };
    const scan = { after: Date.parse('2026-11-17T12:00:00Z') / 1000, before: Date.parse('2026-11-18T12:00:00Z') / 1000, page_token: null };
    const listed = await listPage('GITHUB', scan, undefined, async prompt => {
        assert.ok(prompt.includes('"max_results":100'));
        return { emails: [mail], next_page_token: 'next' };
    });
    assert.equal(listed.messages.length, 1);
    assert.equal(listed.next_page_token, 'next');
    await assert.rejects(() => listPage('GITHUB', scan, undefined, async () => ({ emails: [{ ...mail, email_ts: '2026-11-16T10:00:00Z' }] })), /GMAIL_UNAVAILABLE/);
});

test('planned batches skip processed messages before reading any bodies', async () => {
    const { plannedBatch } = await import('./gmail-agent.mjs');
    process.env.TASK_AGENT_ACCOUNT = 'owner@example.test';
    const mail = { id: 'abc123', display_url: 'https://mail.google.com/mail/u/?authuser=owner%40example.test#all/abc123' };
    const page = plannedBatch({ messages: [mail], exclude_ids: ['abc123'] });
    assert.deepEqual(page.found, []);
    assert.equal(page.metadata.skipped_count, 1);
    assert.throws(() => plannedBatch({ messages: Array(7).fill(mail) }), /INVALID_SCAN/);
});

test('reader supports durable listing jobs without invoking body extraction', async t => {
    let listed;
    let extracted = false;
    const request = await reader(t, { runListing: async (scope, scan) => { listed = { scope, scan }; return { messages: [], next_page_token: null }; }, runExtraction: async () => { extracted = true; return {}; } });
    const scan = { after: 100, before: 200, page_token: 'listing-next' };
    assert.equal((await request(runA, 'POST', { scope: 'GITHUB', kind: 'list', scan })).status, 202);
    await tick();
    assert.deepEqual(listed, { scope: 'GITHUB', scan });
    assert.equal(extracted, false);
    assert.equal((await request(runA)).body.status, 'succeeded');
});

test('combined reader dispatches both sources and validates custom criteria before starting', async t => {
    const scan = { after: 1794884400, before: 1794970800, codex_after: 1794841200, codex_before: 1794927600,
        instructions: '返信を優先', messages: [{ id: 'abc123', source_type: 'GMAIL' }, { id: 'def456', source_type: 'CODEX' }] };
    const seen = [];
    const request = await reader(t, {
        runExtraction: async (scope, update, signal, options) => { seen.push([scope, options.instructions]); return { read_count: 1, skipped_count: 0, processed_ids: ['abc123'], candidates: [] }; },
        runHistoryExtraction: async options => { seen.push(['CODEX', options.instructions]); return { read_count: 1, skipped_count: 0, processed_ids: ['def456'], candidates: [] }; },
    });
    assert.equal((await request(runA, 'POST', { scope: 'ALL', scan: { ...scan, instructions: 'x'.repeat(2001) } })).status, 422);
    assert.equal((await request(runA, 'POST', { scope: 'ALL', scan })).status, 202);
    await tick();
    const result = (await request(runA)).body;
    assert.equal(result.status, 'succeeded');
    assert.equal(result.read_count, 2);
    assert.deepEqual(result.processed_ids, ['abc123', 'def456']);
    assert.deepEqual(seen, [['MAIL', '返信を優先'], ['CODEX', '返信を優先']]);
});
