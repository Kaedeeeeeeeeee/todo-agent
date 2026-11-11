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
