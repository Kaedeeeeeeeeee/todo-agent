<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { graphql } from '../graphql';

const props = defineProps({ endpoint: String, csrf: String, loginUrl: String });
const dialog = ref(null);
const title = ref('');
const pending = ref(false);
const error = ref('');
const titleError = ref('');
const uncertain = ref(false);
const loginRequired = ref(false);
const feedback = ref(null);
let opener;
const request = (query, variables, name) => graphql(props.endpoint, props.csrf, query, variables, name);
async function open(event) {
    const trigger = event.target.closest?.('[data-create-folder]');
    if (!trigger || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (dialog.value.open || document.querySelector('dialog[open]')) return;
    opener = trigger;
    if (!uncertain.value && !loginRequired.value) {
        title.value = '';
        error.value = '';
        titleError.value = '';
    }
    feedback.value = null;
    dialog.value.showModal();
    await nextTick();
    document.getElementById('new-folder-title')?.focus();
}
async function close() {
    if (pending.value) return;
    dialog.value.close();
    await nextTick();
    if (opener?.isConnected) opener.focus();
}
function publish(detail) {
    window.dispatchEvent(new CustomEvent('tasklist:folders-changed', { detail }));
    if (!document.querySelector('[data-task-workspace], [data-folder-starter]')) feedback.value = detail;
}
function fail(exception) {
    error.value = exception.message;
    titleError.value = exception.validation?.title?.[0] || '';
    uncertain.value = !!exception.uncertain;
    loginRequired.value = !!exception.loginRequired;
}
async function save() {
    if (pending.value || uncertain.value || loginRequired.value) return;
    pending.value = true;
    error.value = '';
    titleError.value = '';
    let saved = false;
    try {
        const data = await request('mutation CreateFolder($input: CreateFolderInput!) { createFolder(input: $input) { id title tasks_url } }', { input: { title: title.value } }, 'CreateFolder');
        if (!data.createFolder) throw Object.assign(new Error('作成結果を確認できません。フォルダ一覧を再取得してください。'), { uncertain: true });
        const folder = data.createFolder;
        publish({ folder: { id: Number(folder.id), title: folder.title, url: folder.tasks_url }, message: `「${folder.title}」を作成しました。` });
        saved = true;
    } catch (exception) { fail(exception); }
    finally { pending.value = false; }
    if (saved) await close();
}
async function reloadFolders() {
    if (pending.value) return;
    pending.value = true;
    let refreshed = false;
    try {
        const folders = [];
        let page = 1;
        let more = true;
        while (more) {
            const data = await request('query ReloadFolders($page: Int!) { folders(page: $page, first: 50) { data { id title tasks_url } paginatorInfo { hasMorePages } } }', { page }, 'ReloadFolders');
            folders.push(...data.folders.data.map((item) => ({ id: Number(item.id), title: item.title, url: item.tasks_url })));
            more = data.folders.paginatorInfo.hasMorePages;
            page++;
        }
        publish({ folders, message: 'フォルダ一覧を再取得しました。作成結果を確認してください。' });
        uncertain.value = false;
        error.value = '';
        refreshed = true;
    } catch (exception) { fail(exception); uncertain.value = true; }
    finally { pending.value = false; }
    if (refreshed) await close();
}
onMounted(() => document.addEventListener('click', open));
onBeforeUnmount(() => document.removeEventListener('click', open));
</script>

<template>
    <dialog ref="dialog" class="folder-create-dialog" aria-labelledby="new-folder-heading" :aria-busy="pending" @cancel.prevent="close">
        <h2 id="new-folder-heading">フォルダを作成</h2>
        <form novalidate @submit.prevent="save">
            <div class="enhanced-field">
                <label for="new-folder-title">フォルダ名 <span class="required-mark">*</span></label>
                <Input id="new-folder-title" v-model="title" name="title" required maxlength="20" :disabled="pending || uncertain || loginRequired" :aria-invalid="!!titleError" :aria-describedby="titleError ? 'new-folder-error' : undefined" />
                <p v-if="titleError" id="new-folder-error" class="field-error" role="alert">{{ titleError }}</p>
            </div>
            <div v-if="error && !titleError" class="workspace-error" role="alert"><p>{{ error }}</p><a v-if="loginRequired" :href="loginUrl">ログインする</a><Button v-else-if="uncertain" type="button" variant="outline" :disabled="pending" @click="reloadFolders">フォルダ一覧を再取得</Button></div>
            <div class="quick-task-actions"><Button type="button" variant="outline" :disabled="pending" @click="close">キャンセル</Button><Button type="submit" :disabled="pending || uncertain || loginRequired"><span v-if="pending" class="pending-spinner" aria-hidden="true" />{{ pending ? '保存中…' : '保存する' }}</Button></div>
        </form>
    </dialog>
    <div v-if="feedback" class="folder-create-feedback" role="status">{{ feedback.message }} <a v-if="feedback.folder" :href="feedback.folder.url">フォルダを開く</a></div>
</template>
