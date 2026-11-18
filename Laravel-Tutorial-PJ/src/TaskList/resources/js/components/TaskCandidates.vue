<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { graphql, taskFields } from '../graphql';
import GmailAutomation from './GmailAutomation.vue';

const props = defineProps({ endpoint: String, csrf: String, folder: Object, today: String, loginUrl: String, blocked: Boolean });
const emit = defineEmits(['accepted', 'refresh']);
const inbox = ref(null);
const connection = ref(null);
const manageOpen = ref(false);
const disconnectConfirm = ref(false);
const opened = ref(false);
const scope = ref('ALL');
const instructions = ref('');
const criteriaEdited = ref(false);
const loading = ref(false);
const pending = ref(false);
const error = ref('');
const loginRequired = ref(false);
const validation = ref({});
const drafts = ref({});
let timer;
let disposed = false;
const scopeLabel = (value) => ({ ALL: 'メール＋Codex', MAIL: 'すべてのメール', BIZMATES: 'Bizmates（旧設定）', GITHUB: 'GitHub（旧設定）', CODEX: '前日の Codex 会話' })[value] || value;
const connectionFields = 'can_manage status account verified_at error service_status retry_after';
const runFields = 'id scope status instructions read_count candidate_count duplicate_count error stage total_count scanned_count batch_count';
const candidateFields = 'source_type source_url id message_id subject sender received_at title description reason evidence due_date status task_id';
const active = computed(() => ['reading', 'extracting'].includes(inbox.value?.run?.status));
const selectedRun = computed(() => inbox.value?.latest_run?.scope === scope.value ? inbox.value.latest_run : null);
const selectedActive = computed(() => ['reading', 'extracting'].includes(selectedRun.value?.status));
const runTime = value => new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value));
const connected = computed(() => connection.value?.status === 'connected');
const busy = computed(() => loading.value || pending.value || props.blocked);
const readBusy = computed(() => busy.value || active.value || connection.value?.service_status !== 'ready');
const connectionLabel = computed(() => ({ connected: '接続済み', disconnected: '未接続', verifying: '接続を確認中…', failed: '接続を確認してください', unavailable: '未接続' })[connection.value?.status] || '確認中…');
const serviceLabel = computed(() => ({ ready: '読み取りサービスは利用可能です', busy: '前の処理が完了するまでお待ちください', cooldown: `次の読み取りまで約 ${connection.value?.retry_after || 0} 秒`, offline: '読み取りサービスに接続できません。サービスの起動状態を確認してください。' })[connection.value?.service_status] || '');
const candidates = computed(() => selectedRun.value ? (inbox.value?.candidates || []).filter(item => item.status === 'pending') : []);
const handled = computed(() => selectedRun.value ? (inbox.value?.candidates || []).filter(item => item.status !== 'pending') : []);
const request = (query, variables, name) => graphql(props.endpoint, props.csrf, query, variables, name);
const sourceUrl = (item) => item.source_type === 'CODEX' ? item.source_url : `https://mail.google.com/mail/u/?authuser=${encodeURIComponent(inbox.value.account)}#all/${item.message_id}`;
function fail(exception) {
    error.value = exception.message;
    loginRequired.value = !!exception.loginRequired;
}
async function load() {
    clearTimeout(timer);
    if (disposed || loading.value) return;
    loading.value = true;
    const requestedScope = scope.value;
    try {
        const data = await request(`query CandidateInbox($scope: GmailScope!) { gmailConnection { ${connectionFields} } candidateInbox(scope: $scope) { enabled codex_available account run { ${runFields} } latest_run { ${runFields} created_at } candidates { ${candidateFields} } } }`, { scope: requestedScope }, 'CandidateInbox');
        connection.value = data.gmailConnection;
        inbox.value = data.candidateInbox;
        if (!criteriaEdited.value) instructions.value = data.candidateInbox.latest_run?.instructions || '';
        if (inbox.value.codex_available && connection.value.status !== 'connected') scope.value = 'CODEX';
        else if (!inbox.value.codex_available && scope.value === 'ALL') scope.value = 'MAIL';
        for (const item of inbox.value.candidates) {
            if (!drafts.value[item.id]) drafts.value[item.id] = { title: item.title, due_date: item.due_date || '' };
        }
        loginRequired.value = false;
    } catch (exception) { fail(exception); }
    finally {
        loading.value = false;
        if (!disposed && requestedScope !== scope.value) timer = setTimeout(load, 0);
        else if (!disposed && !error.value && (active.value || connection.value?.status === 'verifying' || ['busy', 'cooldown'].includes(connection.value?.service_status))) timer = setTimeout(load, 2500);
        else if (!disposed && (connected.value || inbox.value?.codex_available)) timer = setTimeout(load, 10000);
    }
}
async function refresh() {
    error.value = '';
    await load();
    emit('refresh');
}
async function connect() {
    if (pending.value || loading.value || connection.value?.status === 'verifying') return;
    pending.value = true;
    error.value = '';
    try {
        const data = await request(`mutation ConnectGmail { connectGmail { ${connectionFields} } }`, {}, 'ConnectGmail');
        if (!data.connectGmail) throw new Error('接続の状態を確認できません。状態を再確認してください。');
        connection.value = data.connectGmail;
        await load();
    } catch (exception) { fail(exception); }
    finally { pending.value = false; }
}
async function disconnect() {
    if (pending.value || loading.value) return;
    pending.value = true;
    error.value = '';
    try {
        const data = await request(`mutation DisconnectGmail($confirm: Boolean!) { disconnectGmail(confirm: $confirm) { ${connectionFields} } }`, { confirm: true }, 'DisconnectGmail');
        if (!data.disconnectGmail) throw new Error('解除の状態を確認できません。状態を再確認してください。');
        connection.value = data.disconnectGmail;
        inbox.value = { enabled: false, candidates: [], run: null };
        drafts.value = {};
        disconnectConfirm.value = false;
        manageOpen.value = true;
        clearTimeout(timer);
        await load();
    } catch (exception) { fail(exception); }
    finally { pending.value = false; }
}
async function start() {
    if (readBusy.value) return;
    pending.value = true;
    error.value = '';
    try {
        const data = await request(`mutation ExtractGmail($scope: GmailScope!, $instructions: String) { extractGmailTasks(scope: $scope, instructions: $instructions) { ${runFields} } }`, { scope: scope.value, instructions: instructions.value.trim() }, 'ExtractGmail');
        if (!data.extractGmailTasks) throw new Error('読み取りの状態を確認できません。候補を再取得してください。');
        inbox.value.run = data.extractGmailTasks;
        if (data.extractGmailTasks.scope === scope.value) {
            inbox.value.latest_run = data.extractGmailTasks;
            inbox.value.candidates = [];
        }
        await load();
    } catch (exception) { fail(exception); }
    finally { pending.value = false; }
    if (!error.value && active.value) timer = setTimeout(load, 1500);
}
async function accept(item) {
    if (busy.value) return;
    pending.value = true;
    error.value = '';
    validation.value = {};
    try {
        const data = await request(`mutation AcceptCandidate($id: ID!, $folder: ID!, $input: CreateTaskInput!) { acceptTaskCandidate(id: $id, folder_id: $folder, input: $input) { ${taskFields} } }`,
            { id: item.id, folder: String(props.folder.id), input: { ...drafts.value[item.id] } }, 'AcceptCandidate');
        if (!data.acceptTaskCandidate) throw new Error('追加結果を確認できません。候補を再取得してください。');
        emit('accepted', data.acceptTaskCandidate);
        item.status = 'accepted';
        item.title = data.acceptTaskCandidate.title;
        item.due_date = data.acceptTaskCandidate.due_date;
        item.task_id = data.acceptTaskCandidate.id;
    } catch (exception) {
        validation.value = { [item.id]: exception.validation || {} };
        fail(exception);
    } finally { pending.value = false; }
}
async function dismiss(item) {
    if (busy.value) return;
    pending.value = true;
    error.value = '';
    try {
        const data = await request('mutation DismissCandidate($id: ID!) { dismissTaskCandidate(id: $id) { id status } }', { id: item.id }, 'DismissCandidate');
        if (!data.dismissTaskCandidate) throw new Error('見送りの結果を確認できません。候補を再取得してください。');
        item.status = data.dismissTaskCandidate.status;
    } catch (exception) { fail(exception); }
    finally { pending.value = false; }
}
watch(scope, () => { criteriaEdited.value = false; error.value = ''; load(); });
onMounted(load);
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <section class="mailbox-panel" aria-labelledby="mailbox-title" :aria-busy="loading || pending || connection?.status === 'verifying'">
        <div class="candidate-heading">
            <div><h2 id="mailbox-title">メール接続</h2><p v-if="connection?.can_manage">Gmail · {{ connection.account }} · 読み取り専用</p><p v-else>メールを接続して、タスク候補を整理できます。</p></div>
            <div class="mailbox-heading-actions"><span class="mailbox-status" role="status">{{ connectionLabel }}</span><Button v-if="connected" type="button" variant="outline" :aria-expanded="manageOpen" aria-controls="mailbox-settings" @click="manageOpen = !manageOpen">接続を管理</Button></div>
        </div>
        <div v-if="connection?.can_manage && (manageOpen || !connected)" id="mailbox-settings" class="mailbox-settings">
            <p>この端末の Codex で認証済みの Gmail を、この TaskList アカウントに接続します。メールアドレスを確認してから読み取りを利用できます。</p>
            <p v-if="connection.verified_at" class="mailbox-verified">最終確認：{{ connection.verified_at.slice(0, 19).replace('T', ' ') }}</p>
            <div v-if="disconnectConfirm" class="mailbox-disconnect-confirm" role="group" aria-label="メール接続の解除を確認">
                <p>TaskList でのメール読み取りを停止します。保存済みのタスク・候補は残り、Codex 側の Gmail 認証は解除されません。</p>
                <Button type="button" variant="outline" :disabled="pending" @click="disconnectConfirm = false">キャンセル</Button>
                <Button type="button" variant="destructive" :disabled="pending" @click="disconnect">接続を解除する</Button>
            </div>
            <div v-else class="mailbox-actions">
                <Button v-if="!connected && connection.status !== 'verifying'" type="button" :disabled="pending || loading || connection.service_status !== 'ready'" @click="connect">Codex の Gmail を接続</Button>
                <Button v-if="connected || connection.status === 'verifying'" type="button" variant="outline" :disabled="pending || loading" @click="disconnectConfirm = true">接続を解除</Button>
                <Button type="button" variant="ghost" :disabled="pending || loading" @click="refresh">接続状態を再確認</Button>
            </div>
            <p v-if="connection.status === 'verifying'" class="candidate-progress" role="status"><span class="pending-spinner" aria-hidden="true" />Codex の Gmail アカウントを確認しています…</p>
            <p v-if="connection.error" class="field-error" role="alert">{{ connection.error }}</p>
        </div>
        <p v-if="connection?.can_manage && serviceLabel" :class="['mailbox-service', { 'field-error': connection.service_status === 'offline' }]" role="status">{{ serviceLabel }}<Button v-if="connection.service_status === 'offline' && !manageOpen && connected" type="button" variant="ghost" size="sm" :disabled="loading" @click="refresh">再確認</Button></p>
        <p v-if="connection && !connection.can_manage" class="mailbox-unavailable">このローカル環境の Gmail は、利用対象の TaskList アカウントで接続できます。</p>
    </section>
    <GmailAutomation :codex-available="!!inbox?.codex_available" :endpoint="endpoint" :csrf="csrf" :connection="connection" :blocked="blocked" @refresh="refresh" />
    <section v-if="inbox?.enabled" class="candidate-panel" aria-labelledby="candidate-title">
        <div class="candidate-heading">
            <div><h2 id="candidate-title">AI タスク候補</h2></div>
            <Button type="button" variant="outline" :aria-expanded="opened" aria-controls="candidate-content" @click="opened = !opened">{{ opened ? '閉じる' : `候補を確認${candidates.length ? ` (${candidates.length})` : ''}` }}</Button>
        </div>
        <div v-if="opened" id="candidate-content" class="candidate-content">
            <p class="candidate-intro">{{ scope === 'ALL' ? 'メールは過去24時間、Codex は前日（日本時間）の内容を確認します。' : scope === 'CODEX' ? '前日（日本時間）の Codex 会話を確認します。' : '過去24時間のメールを確認します（迷惑メール・ゴミ箱を除く）。' }}最新の候補を表示し、確認後にタスクへ追加します。</p>
            <form class="candidate-source-form" @submit.prevent="start">
                <label for="gmail-source-scope">読み取る内容</label>
                <select id="gmail-source-scope" v-model="scope" :disabled="busy"><option v-if="inbox.codex_available" value="ALL" :disabled="!connected">メール＋Codex をまとめて整理</option><option value="MAIL" :disabled="!connected">すべてのメール</option><option v-if="inbox.codex_available" value="CODEX">前日の Codex 会話</option></select>
                <Button type="submit" :disabled="readBusy">{{ active ? '整理中…' : pending ? '処理中…' : '読み取って候補を作成' }}</Button>
                <details class="selection-conditions"><summary>整理の条件（任意）{{ instructions.trim() ? ' · 設定あり' : '' }}</summary><label for="candidate-instructions">AI に探してほしいこと</label><textarea id="candidate-instructions" v-model="instructions" rows="2" maxlength="2000" :disabled="busy" @input="criteriaEdited = true" placeholder="例：返信が必要なメール、未完了の作業、次に進められることを優先" /><p>{{ selectedActive && instructions.trim() !== selectedRun.instructions.trim() ? '変更した条件は次回の実行に使います。実行中の内容は変わりません。' : '空欄なら、対応や継続が必要そうな内容を幅広く提案します。' }}</p></details>
            </form>
            <p v-if="selectedActive" class="candidate-progress" role="status"><span class="pending-spinner" aria-hidden="true" />{{ selectedRun.stage === 'listing' ? (selectedRun.scope === 'ALL' ? 'メールと昨日の会話を検索しています…' : selectedRun.scope === 'CODEX' ? '前日の Codex 会話を検索しています…' : '過去24時間のメールを検索しています…') : selectedRun.status === 'reading' ? '内容を読み取っています…' : 'AI がタスク候補を整理しています…' }}<span v-if="selectedRun.total_count !== null && selectedRun.stage !== 'legacy'">確認済み {{ selectedRun.scanned_count }} / {{ selectedRun.total_count }} 件 · 残り {{ Math.max(0, selectedRun.total_count - selectedRun.scanned_count) }} 件 · 新しい候補 {{ selectedRun.candidate_count }} 件</span></p>
            <p v-else-if="selectedRun?.status === 'succeeded'" class="candidate-result" role="status">{{ runTime(selectedRun.created_at) }} · {{ scopeLabel(selectedRun.scope) }}：{{ selectedRun.scope === 'ALL' ? '対象' : selectedRun.scope === 'CODEX' ? '会話内の依頼' : 'メール' }} {{ selectedRun.read_count }} 件を読み取り、新しい候補 {{ selectedRun.candidate_count }} 件を作成しました。<span v-if="selectedRun.duplicate_count">重複 {{ selectedRun.duplicate_count }} 件は追加していません。</span></p>
            <div v-if="error || selectedRun?.error" class="workspace-error" role="alert"><p>{{ error || `前回の読み取り：${selectedRun.error}` }}</p><a v-if="loginRequired" :href="loginUrl">ログインする</a><Button v-else type="button" size="sm" variant="outline" :disabled="loading || pending" @click="refresh">候補を再取得</Button></div>
            <div v-if="candidates.length" class="candidate-list">
                <article v-for="item in candidates" :key="item.id" class="candidate-card">
                    <div class="candidate-source"><span>{{ item.source_type === 'CODEX' ? 'Codex' : 'Gmail' }}</span><a :href="sourceUrl(item)" :target="item.source_type === 'CODEX' ? undefined : '_blank'" rel="noopener noreferrer">{{ item.source_type === 'CODEX' ? '元の会話を開く ↗' : '元のメール ↗' }}</a></div>
                    <form @submit.prevent="accept(item)" novalidate>
                        <label :for="`candidate-title-${item.id}`">タスク名</label>
                        <Input :id="`candidate-title-${item.id}`" v-model="drafts[item.id].title" maxlength="100" required :disabled="busy" :aria-invalid="!!validation[item.id]?.title" />
                        <p v-if="validation[item.id]?.title" class="field-error">{{ validation[item.id].title[0] }}</p>
                        <details class="candidate-details"><summary>詳細</summary><p class="candidate-subject">{{ item.subject }}</p><p class="candidate-description">{{ item.description }}</p><p>{{ item.reason }}</p><blockquote>{{ item.evidence }}</blockquote><p class="candidate-sender">{{ item.sender }} · {{ item.received_at.slice(0, 10) }}</p></details>
                        <div class="candidate-confirm">
                            <div><label :for="`candidate-date-${item.id}`">期限</label><Input :id="`candidate-date-${item.id}`" v-model="drafts[item.id].due_date" type="date" :min="today" required :disabled="busy" :aria-invalid="!!validation[item.id]?.due_date" /><p v-if="validation[item.id]?.due_date" class="field-error">{{ validation[item.id].due_date[0] }}</p></div>
                            <div class="candidate-actions"><Button type="button" variant="ghost" :disabled="busy" @click="dismiss(item)">見送る</Button><Button type="submit" :disabled="busy">追加</Button></div>
                        </div>
                    </form>
                </article>
            </div>
            <p v-else-if="!loading && !selectedActive && selectedRun?.status === 'succeeded'" class="candidate-empty">今回の実行に未確認の候補はありません。</p>
            <p v-if="!loading && !selectedRun" class="candidate-empty">この読み取り元の実行結果はまだありません。</p>
            <details v-if="handled.length" class="candidate-history"><summary>確認済み {{ handled.length }} 件</summary><ul><li v-for="item in handled" :key="item.id"><span>{{ item.status === 'accepted' ? '追加済み' : '見送り' }}</span><strong>{{ item.title }}</strong><a :href="sourceUrl(item)" :target="item.source_type === 'CODEX' ? undefined : '_blank'" rel="noopener noreferrer">{{ item.source_type === 'CODEX' ? '元の会話 ↗' : '元のメール ↗' }}</a></li></ul></details>
        </div>
    </section>
    <div v-if="error && !inbox?.enabled" class="workspace-error" role="alert"><p>{{ error }}</p><Button type="button" variant="outline" @click="refresh">候補を再取得</Button></div>
</template>
