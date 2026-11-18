import { readdir, lstat } from 'node:fs/promises';
import { createReadStream } from 'node:fs';
import { createInterface } from 'node:readline';
import { join, basename } from 'node:path';
import { homedir } from 'node:os';
import { createHash } from 'node:crypto';

const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
const hash = text => createHash('sha256').update(text).digest('hex');
const root = () => process.env.TASK_AGENT_CODEX_HISTORY_ROOT || join(homedir(), '.codex');
export function validateHistoryWindow(scan) {
    if (!Number.isSafeInteger(scan.after) || !Number.isSafeInteger(scan.before) || scan.before - scan.after !== 86400
        || (scan.before + 9 * 3600) % 86400 !== 0 || scan.after < 0) throw new Error('INVALID_SCAN');
}
export function redactHistory(text) {
    return text.replace(/\b(?:sk-(?:proj-)?[A-Za-z0-9_-]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/g, '[REDACTED]')
        .replace(/((?:["']?)(?:password|secret|access_token|api_key|openai_api_key)(?:["']?)\s*[=:]\s*)(?:"[^"\n]*"|'[^'\n]*'|[^\s"'<>]+)/gi, '$1[REDACTED]')
        .replace(/(Bearer\s+)[A-Za-z0-9._-]{16,}/gi, '$1[REDACTED]');
}
export function conversationMessage(record) {
    const p = record.payload;
    if (record.type !== 'response_item' || p?.type !== 'message' || !['user', 'assistant'].includes(p.role)) return null;
    if (p.role === 'assistant' && !['final_answer', 'final'].includes(p.phase)) return null;
    let text = (p.content || []).filter(c => ['input_text', 'output_text', 'text'].includes(c.type)).map(c => c.text || '').join('\n').trim();
    if (!text || /^(?:# AGENTS\.md instructions|<environment_context>|<turn_aborted>|<system_reminder>)/.test(text)) return null;
    if (p.role === 'user' && text.includes('## My request:')) text = text.slice(text.lastIndexOf('## My request:') + 14).trim();
    return { role: p.role, timestamp: record.timestamp, text: redactHistory(text) };
}
async function* files(directory) {
    let entries;
    try { entries = await readdir(directory, { withFileTypes: true }); } catch (e) { if (e.code === 'ENOENT') return; throw e; }
    for (const e of entries.sort((a, b) => a.name.localeCompare(b.name))) {
        if (e.isSymbolicLink()) continue;
        const path = join(directory, e.name);
        if (e.isDirectory()) yield* files(path);
        else if (e.isFile() && e.name.endsWith('.jsonl')) yield path;
    }
}
export async function historyUnits(scan, { historyRoot = root() } = {}) {
    validateHistoryWindow(scan);
    const threads = new Map();
    const titles = new Map();
    try {
        const input = createInterface({ input: createReadStream(join(historyRoot, 'session_index.jsonl')), crlfDelay: Infinity });
        for await (const line of input) {
            try { const row = JSON.parse(line); if (uuid.test(row.id)) titles.set(row.id, String(row.thread_name || '').slice(0, 300)); } catch {}
        }
    } catch (e) { if (e.code !== 'ENOENT') throw e; }
    let directoryExists = false;
    for (const directory of ['sessions', 'archived_sessions']) {
        const base = join(historyRoot, directory);
        try { const stat = await lstat(base); if (stat.isSymbolicLink() || !stat.isDirectory()) continue; directoryExists = true; } catch (e) { if (e.code === 'ENOENT') continue; throw e; }
        for await (const path of files(base)) {
            if ((await lstat(path)).mtimeMs < scan.after * 1000) continue;
            let meta;
            const input = createInterface({ input: createReadStream(path), crlfDelay: Infinity });
            for await (const line of input) {
                let row;
                try { row = JSON.parse(line); } catch { continue; }
                if (row.type === 'session_meta' && !meta) {
                    meta = row.payload;
                    if (String(meta?.cwd || '').includes('/.chatgpt-projects/')) break;
                    if (!uuid.test(meta?.id) || typeof meta.source !== 'string' || !['vscode', 'cli', 'app', 'desktop'].includes(meta.source)) break;
                }
                if (!meta) continue;
                const time = Date.parse(row.timestamp) / 1000;
                if (!Number.isFinite(time) || time < scan.after || time >= scan.before) continue;
                const message = conversationMessage(row);
                if (!message) continue;
                if (!threads.has(meta.id)) threads.set(meta.id, { meta, messages: new Map() });
                threads.get(meta.id).messages.set(hash(`${message.role}:${message.timestamp}:${message.text}`), message);
            }
        }
    }
    if (!directoryExists) throw new Error('CODEX_HISTORY_UNAVAILABLE');
    const units = [];
    for (const [threadId, data] of threads) {
        const messages = [...data.messages.values()].sort((a, b) => a.timestamp.localeCompare(b.timestamp));
        const title = titles.get(threadId) || basename(data.meta.cwd || '') || 'Codex の会話';
        const lastFinal = messages.filter(m => m.role === 'assistant').at(-1);
        for (let i = 0; i < messages.length; i++) {
            const message = messages[i];
            if (message.role !== 'user') continue;
            const replies = [];
            for (let j = i + 1; j < messages.length && messages[j].role !== 'user'; j++) replies.push(messages[j]);
            const id = hash(`codex-actions-v2:${threadId}:${message.timestamp}:${message.text}`);
            const context = [message, ...replies];
            // Keep the request whole. An oversized turn is surfaced rather than silently truncated.
            const body = context.map(m => `${m.role}: ${m.text}`).join('\n\n');
            if (body.length > 60000) throw new Error('CODEX_HISTORY_TOO_LARGE');
            units.push({ id, thread_id: threadId, subject: title, from_: `Codex · ${basename(data.meta.cwd || '') || '会話'}`,
                email_ts: message.timestamp, display_url: `codex://threads/${threadId}`, body,
                completion_context: lastFinal ? lastFinal.text : '' });
        }
    }
    return units.sort((a, b) => a.email_ts.localeCompare(b.email_ts) || a.id.localeCompare(b.id));
}
export async function listCodexHistory(scan) {
    const units = await historyUnits(scan);
    const offset = scan.page_token == null ? 0 : Number(scan.page_token);
    if (!Number.isSafeInteger(offset) || offset < 0 || offset > units.length) throw new Error('INVALID_SCAN');
    return { messages: units.slice(offset, offset + 100).map(({ body, completion_context, ...meta }) => meta),
        next_page_token: offset + 100 < units.length ? String(offset + 100) : null };
}
export async function extractCodexHistory(scan, interpret, validate) {
    if (!Array.isArray(scan.messages) || scan.messages.length < 1 || scan.messages.length > 6) throw new Error('INVALID_SCAN');
    const all = new Map((await historyUnits(scan)).map(unit => [unit.id, unit]));
    const known = new Set(scan.exclude_ids || []);
    const requested = scan.messages.map(meta => {
        const unit = all.get(meta.id);
        if (!unit || unit.display_url !== meta.display_url) throw new Error('CODEX_HISTORY_CHANGED');
        return unit;
    });
    const sources = requested.filter(unit => !known.has(unit.id));
    if (sources.reduce((n, u) => n + u.body.length + u.completion_context.length, 0) > 180000) throw new Error('CODEX_HISTORY_TOO_LARGE');
    const output = sources.length ? await interpret(sources) : { candidates: [] };
    let candidates;
    try { candidates = validate(output, sources.map(u => ({ ...u, body: `${u.body}\n\n${u.completion_context}`, sender: u.from_, received_at: u.email_ts }))); }
    catch (error) { if (error.message === 'INVALID_EVIDENCE') throw Object.assign(new Error('CODEX_INVALID_EVIDENCE'), { reason: error.reason }); throw error; }
    return { read_count: sources.length, processed_ids: requested.map(u => u.id), skipped_count: requested.length - sources.length,
        candidates, next_page_token: null };
}
