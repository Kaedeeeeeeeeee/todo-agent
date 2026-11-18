<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { graphql } from '../graphql';

const props = defineProps({ endpoint: String, csrf: String, connection: Object, blocked: Boolean, codexAvailable: Boolean });
const emit = defineEmits(['refresh']);
const settings = ref(null);
const history = ref([]);
const expanded = ref(false);
const draft = ref({ enabled: false, time: '09:00', scope: 'ALL', instructions: '' });
const dirty = ref(false);
const loading = ref(false);
const pending = ref(false);
const error = ref('');
const saved = ref(false);
let timer;
let disposed = false;
const fields = 'enabled time scope instructions timezone next_run_at last_run_at worker_online';
const runFields = 'id scope status trigger attempt retry_of retry_at created_at completed_at read_count candidate_count duplicate_count skipped_count has_more stage total_count scanned_count batch_count error';
const request = (query, variables, name) => graphql(props.endpoint, props.csrf, query, variables, name);
const active = computed(() => history.value.some(run => ['reading', 'extracting'].includes(run.status)));
const connected = computed(() => draft.value.scope === 'CODEX' ? props.codexAvailable : draft.value.scope === 'ALL' ? props.codexAvailable && props.connection?.status === 'connected' : props.connection?.status === 'connected');
const canManage = computed(() => props.connection?.can_manage || props.codexAvailable);
const fmt = value => value ? new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date(value)) : '—';
const statusLabel = status => ({ reading: '内容を読み取り中', extracting: 'AI が候補を作成中', succeeded: '完了', failed: '失敗' })[status] || status;
const triggerLabel = run => run.retry_of ? `再試行 ${run.attempt - 1} 回目` : run.trigger === 'scheduled' ? '自動実行' : '手動実行';
function edit() { dirty.value = true; saved.value = false; }
async function load() {
    clearTimeout(timer);
    if (disposed || loading.value) return;
    loading.value = true;
    try {
        const data = await request(`query GmailAutomation { gmailAutomation { ${fields} } gmailExtractionHistory { ${runFields} } }`, {}, 'GmailAutomation');
        settings.value = data.gmailAutomation;
        history.value = data.gmailExtractionHistory;
        if (!dirty.value) draft.value = { enabled: settings.value.enabled, time: settings.value.time, scope: settings.value.scope, instructions: settings.value.instructions || '' };
    } catch (exception) { error.value = exception.message; }
    finally {
        loading.value = false;
        if (!disposed) timer = setTimeout(load, 10000);
    }
}
async function save() {
    if (pending.value || props.blocked || !connected.value) return;
    pending.value = true;
    error.value = '';
    saved.value = false;
    try {
        const data = await request(`mutation UpdateGmailAutomation($input: GmailAutomationInput!) { updateGmailAutomation(input: $input) { ${fields} } }`, { input: draft.value }, 'UpdateGmailAutomation');
        if (!data.updateGmailAutomation) throw new Error('保存結果を確認できません。再取得してください。');
        settings.value = data.updateGmailAutomation;
        dirty.value = false;
        saved.value = true;
        await load();
    } catch (exception) { error.value = exception.message; }
    finally { pending.value = false; }
}
async function retry(run) {
    if (pending.value || active.value || props.blocked || props.connection?.service_status !== 'ready') return;
    pending.value = true;
    error.value = '';
    try {
        const data = await request(`mutation RetryGmailExtraction($id: ID!) { retryGmailExtraction(id: $id) { ${runFields} } }`, { id: run.id }, 'RetryGmailExtraction');
        if (!data.retryGmailExtraction) throw new Error('再試行の結果を確認できません。再取得してください。');
        await load();
        emit('refresh');
    } catch (exception) { error.value = exception.message; }
    finally { pending.value = false; }
}
async function refresh() { error.value = ''; await load(); emit('refresh'); }
onMounted(load);
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <section v-if="canManage" class="mailbox-panel automation-panel" aria-labelledby="automation-title">
        <div class="candidate-heading">
            <div><h2 id="automation-title">自動整理</h2><p>{{ settings?.enabled ? `毎日 ${settings.time}（日本時間）に候補を作成` : '決まった時間にタスク候補を作成できます。' }}</p></div>
            <div class="mailbox-heading-actions"><span class="mailbox-status">{{ settings?.enabled ? '有効' : '停止中' }}</span><Button type="button" variant="outline" :aria-expanded="expanded" aria-controls="automation-content" @click="expanded = !expanded">{{ expanded ? '閉じる' : '設定・実行履歴' }}</Button></div>
        </div>
        <div v-if="expanded" id="automation-content" class="automation-content">
            <form class="automation-form" @submit.prevent="save">
                <label class="automation-toggle"><input v-model="draft.enabled" type="checkbox" :disabled="pending || !connected || blocked" @change="edit">自動整理を有効にする</label>
                <div class="automation-fields"><div><label for="automation-time">毎日の実行時刻（日本時間）</label><Input id="automation-time" v-model="draft.time" type="time" required :disabled="pending || !connected || blocked" @input="edit" /></div>
                    <div><label for="automation-scope">読み取る内容</label><select id="automation-scope" v-model="draft.scope" :disabled="pending || !canManage || blocked" @change="edit"><option v-if="codexAvailable" value="ALL" :disabled="connection?.status !== 'connected'">メール＋Codex をまとめて整理</option><option value="MAIL" :disabled="connection?.status !== 'connected'">すべてのメール</option><option v-if="codexAvailable" value="CODEX">前日の Codex 会話</option><option v-if="['BIZMATES', 'GITHUB'].includes(draft.scope)" :value="draft.scope">{{ draft.scope === 'BIZMATES' ? 'Bizmates' : 'GitHub' }}（旧設定）</option></select></div>
                    <Button type="submit" :disabled="pending || !connected || blocked || !dirty">{{ pending ? '保存中…' : '設定を保存' }}</Button>
                </div>
                <details class="selection-conditions"><summary>整理の条件（任意）{{ draft.instructions.trim() ? ' · 設定あり' : '' }}</summary><label for="automation-instructions">AI に探してほしいこと</label><textarea id="automation-instructions" v-model="draft.instructions" rows="2" maxlength="2000" :disabled="pending || blocked" @input="edit" placeholder="例：返信が必要な内容や、続きの作業があるものを提案" /><p>保存した条件で、毎日自動整理します。空欄なら幅広く提案します。</p></details>
            </form>
            <p class="automation-note">{{ draft.scope === 'ALL' ? '過去24時間のメールと前日（日本時間）の Codex 会話をまとめて整理します。' : draft.scope === 'CODEX' ? '前日（日本時間）の Codex 会話を整理します。' : '過去24時間のメールを整理します（迷惑メール・ゴミ箱を除く）。' }}候補は確認後にタスクへ追加します。</p>
            <dl class="automation-summary"><div><dt>次回の自動実行</dt><dd>{{ settings?.enabled ? fmt(settings.next_run_at) : '停止中' }}</dd></div><div><dt>前回の実行</dt><dd>{{ fmt(settings?.last_run_at) }}</dd></div><div><dt>バックグラウンド処理</dt><dd>{{ settings?.worker_online ? '稼働中' : '停止中' }}</dd></div></dl>
            <p v-if="!connected" class="field-error" role="status">読み取り元を接続・選択してから設定してください。</p>
            <p v-else-if="settings && !settings.worker_online" class="automation-note" role="status">自動整理は現在停止しています。ローカル環境のバックグラウンド処理を起動すると再開します。</p>
            <p v-if="saved" class="automation-saved" role="status">{{ settings?.enabled ? '設定を保存しました。次回の時刻に自動で読み取ります。' : '自動整理を停止しました。実行中の読み取りは完了まで続きます。' }}</p>
            <div v-if="error" class="workspace-error" role="alert"><p>{{ error }}</p><Button type="button" variant="outline" size="sm" :disabled="loading || pending" @click="refresh">再取得</Button></div>
            <div class="automation-history-heading"><h3>実行履歴</h3><Button type="button" variant="ghost" size="sm" :disabled="loading || pending" @click="refresh">履歴を更新</Button></div>
            <p v-if="!history.length" class="automation-note">実行履歴はまだありません。</p>
            <ol v-else class="automation-history">
                <li v-for="run in history" :key="run.id">
                    <div class="automation-run-heading"><div><span class="mailbox-status">{{ triggerLabel(run) }}</span><time>{{ fmt(run.created_at) }}</time><span>{{ run.scope === 'ALL' ? 'メール＋Codex' : run.scope === 'MAIL' ? 'メール' : run.scope === 'CODEX' ? 'Codex' : run.scope === 'BIZMATES' ? 'Bizmates' : 'GitHub' }}</span></div><strong :class="{ 'field-error': run.status === 'failed' }">{{ statusLabel(run.status) }}</strong></div>
                    <p v-if="run.status === 'succeeded'">{{ run.scope === 'ALL' ? '対象' : run.scope === 'CODEX' ? '会話内の依頼' : 'メール' }} {{ run.read_count }} 件 ／ 新しい候補 {{ run.candidate_count }} 件<span v-if="run.skipped_count || run.duplicate_count"> ／ 処理済み {{ run.skipped_count + run.duplicate_count }} 件をスキップ</span><span v-if="run.stage === 'complete'"> ／ 対象 {{ run.total_count }} 件すべて確認済み（残り0件）</span><span v-else-if="run.has_more">（旧方式の一部読み取り）</span></p>
                    <p v-else-if="run.status === 'failed'" class="automation-run-error">{{ run.error }}</p>
                    <p v-else class="automation-note" role="status">{{ run.stage === 'listing' ? (run.scope === 'ALL' ? 'メールと昨日の会話を検索しています…' : run.scope === 'CODEX' ? '前日の会話を検索しています…' : '過去24時間のメールを検索しています…') : 'ページを閉じても、全件が完了するまで処理を続けます。' }}</p>
                    <p v-if="run.total_count !== null && run.stage !== 'legacy' && run.status !== 'succeeded'" class="automation-progress" role="status">確認済み {{ run.scanned_count }} / {{ run.total_count }} 件 · 残り {{ Math.max(0, run.total_count - run.scanned_count) }} 件 · 新しい候補 {{ run.candidate_count }} 件</p>
                    <div v-if="run.status === 'failed'" class="automation-retry"><span v-if="run.retry_at">自動再試行：{{ fmt(run.retry_at) }}（最大2回）</span><Button v-if="!history.some(item => item.retry_of === run.id || (item.scope === run.scope && item.status === 'succeeded' && new Date(item.created_at) > new Date(run.created_at)))" type="button" size="sm" variant="outline" :disabled="pending || active || !connected || blocked || connection?.service_status !== 'ready'" @click="retry(run)">この読み取りを再試行</Button></div>
                </li>
            </ol>
        </div>
    </section>
</template>
