import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, rm, symlink } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { historyUnits, redactHistory, conversationMessage, listCodexHistory, extractCodexHistory, validateHistoryWindow } from './codex-history.mjs';
import { validateCandidates } from './gmail-agent.mjs';
const id = '11111111-1111-4111-8111-111111111111';
const after = Date.parse('2026-11-16T15:00:00Z') / 1000;
const scan = { after, before: after + 86400 };
const record = (role, text, timestamp = '2026-11-17T03:00:00Z', phase) => ({ type: 'response_item', timestamp, payload: { type: 'message', role, phase, content: [{ type: role === 'user' ? 'input_text' : 'output_text', text }] } });
async function fixture(t, extra = []) {
    const dir = await mkdtemp(join(tmpdir(), 'tasklist-codex-'));
    await mkdir(join(dir, 'sessions'));
    const rows = [{ type: 'session_meta', payload: { id, cwd: '/work/example', source: 'vscode' } }, ...extra];
    await writeFile(join(dir, 'sessions', 'rollout.jsonl'), rows.map(r => JSON.stringify(r)).join('\n') + '\n');
    await writeFile(join(dir, 'session_index.jsonl'), JSON.stringify({ id, thread_name: 'Example project', updated_at: '2026-11-17T12:00:00Z' }) + '\n');
    t.after(() => rm(dir, { recursive: true, force: true }));
    return dir;
}
test('yesterday is an exact Japanese calendar day, not rolling 24 hours', () => {
    validateHistoryWindow(scan);
    assert.throws(() => validateHistoryWindow({ after: after + 1, before: scan.before + 1 }), /INVALID_SCAN/);
    assert.throws(() => validateHistoryWindow({ after, before: scan.before + 86400 }), /INVALID_SCAN/);
});
test('only genuine user requests and final answers enter the model input', () => {
    assert.equal(conversationMessage(record('developer', 'secret system rules')), null);
    assert.equal(conversationMessage(record('assistant', 'internal progress', undefined, 'commentary')), null);
    assert.equal(conversationMessage(record('user', '# AGENTS.md instructions\nPrivate instructions')), null);
    assert.equal(conversationMessage(record('user', '<environment_context>private</environment_context>')), null);
    assert.equal(redactHistory('{"api_key": "private-value", "password": "two words"}'), '{"api_key": [REDACTED], "password": [REDACTED]}');
    const token = 'sk-proj-'  + 'x'.repeat(35);
    assert.ok(!conversationMessage(record('user', 'Check this API key: ' + token)).text.includes(token));
});
test('old threads with yesterday activity are included and copies are deduplicated', async t => {
    const dir = await fixture(t, [
        record('user', 'outside', '2026-11-16T14:59:59Z'),
        record('user', 'Please add a retry button.'),
        record('assistant', 'Implementation is still pending.', '2026-11-17T03:01:00Z', 'final_answer'),
        record('assistant', 'tool output is confidential', '2026-11-17T03:02:00Z', 'commentary'),
        record('user', 'today is excluded', '2026-11-17T15:00:00Z'),
    ]);
    const original = (await import('node:fs/promises')).readFile(join(dir, 'sessions', 'rollout.jsonl'));
    await writeFile(join(dir, 'sessions', 'fork.jsonl'), await original);
    await symlink('/etc/passwd', join(dir, 'sessions', 'outside.jsonl'));
    const units = await historyUnits(scan, { historyRoot: dir });
    assert.equal(units.length, 1);
    assert.ok(units[0].body.includes('Implementation is still pending.'));
    assert.ok(!units[0].body.includes('today is excluded'));
    assert.ok(!units[0].body.includes('tool output'));
    assert.equal(units[0].display_url, `codex://threads/${id}`);
    assert.equal(units[0].subject, 'Example project');
});
test('subagent logs are excluded', async t => {
    const dir = await fixture(t);
    await writeFile(join(dir, 'sessions', 'agent.jsonl'), [
        { type: 'session_meta', payload: { id, source: { subagent: { thread_spawn: {} } } } }, record('user', 'Not a user-owned task'),
    ].map(JSON.stringify).join('\n'));
    assert.deepEqual(await historyUnits(scan, { historyRoot: dir }), []);
});
test('ordinary ChatGPT project chats are excluded from development history', async t => {
    const dir = await fixture(t);
    await writeFile(join(dir, 'sessions', 'chatgpt.jsonl'), [
        { type: 'session_meta', payload: { id, source: 'app', cwd: '/home/.codex/.chatgpt-projects/example' } },
        record('user', 'Compare rental apartments.'),
    ].map(JSON.stringify).join('\n'));
    assert.deepEqual(await historyUnits(scan, { historyRoot: dir }), []);
});
test('processed turns skip AI and evidence must come from the actual selected turn', async t => {
    const dir = await fixture(t, [record('user', 'Please add a retry button.'), record('assistant', 'Not implemented yet.', '2026-11-17T03:01:00Z', 'final_answer')]);
    const previous = process.env.TASK_AGENT_CODEX_HISTORY_ROOT;
    process.env.TASK_AGENT_CODEX_HISTORY_ROOT = dir;
    t.after(() => { if (previous === undefined) delete process.env.TASK_AGENT_CODEX_HISTORY_ROOT; else process.env.TASK_AGENT_CODEX_HISTORY_ROOT = previous; });
    const listed = await listCodexHistory(scan);
    assert.equal(listed.messages.length, 1);
    assert.ok(!Object.hasOwn(listed.messages[0], 'body'));
    const input = { ...scan, messages: listed.messages, exclude_ids: [] };
    const candidate = { message_id: listed.messages[0].id, title: '再試行ボタンを追加する', description: 'Implement retry.', reason: 'Pending request.', evidence: 'Please add a retry button.', due_date: null };
    const result = await extractCodexHistory(input, async sources => { assert.ok(sources[0].completion_context.includes('Not implemented')); return { candidates: [candidate] }; }, validateCandidates);
    assert.equal(result.read_count, 1);
    assert.equal(result.candidates.length, 1);
    const skipped = await extractCodexHistory({ ...input, exclude_ids: [candidate.message_id] }, async () => { throw new Error('must not invoke AI'); }, validateCandidates);
    assert.equal(skipped.read_count, 0);
    assert.equal(skipped.skipped_count, 1);
    await assert.rejects(() => extractCodexHistory(input, async () => ({ candidates: [{ ...candidate, evidence: 'fabricated quote' }] }), validateCandidates), /CODEX_INVALID_EVIDENCE/);
});
