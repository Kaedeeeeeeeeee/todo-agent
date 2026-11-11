<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardDescription,
    CardFooter,
} from '@/components/ui/card';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import AppIcon from './AppIcon.vue';
import TaskDeleteDialog from './TaskDeleteDialog.vue';
import TaskCandidates from './TaskCandidates.vue';
import { graphql, taskFields } from '../graphql';

const props = defineProps({
    folders: Array,
    tasks: Array,
    folder: Object,
    routes: Object,
    csrf: String,
    today: String,
});
const tasks = ref(props.tasks);
const folderList = ref([...props.folders]);
const search = ref('');
const loading = ref(true);
const loadError = ref('');
const operationError = ref('');
const loginRequired = ref(false);
const needsRefresh = ref(false);
const notice = ref('');
const creating = ref(false);
const createOpen = ref(false);
const editingId = ref(null);
const deleteTarget = ref(null);
const deleteError = ref('');
let formOpener = null;
let deleteOpener = null;
const draft = ref({ title: '', due_date: props.today });
const fieldErrors = ref({});
const busy = computed(() => loading.value || creating.value || pendingId.value !== null || needsRefresh.value || !!loadError.value || loginRequired.value);
const statusValues = { 1: 'TODO', 2: 'DOING', 3: 'DONE' };
const statusNumbers = { TODO: 1, DOING: 2, DONE: 3 };
const request = (query, variables, name) => graphql(props.routes.graphql, props.csrf, query, variables, name);
function mapTask(task) {
    return {
        id: Number(task.id), title: task.title, status: statusNumbers[task.status],
        dueDate: task.due_date, dateLabel: task.due_date.replaceAll('-', '/'),
        editUrl: task.edit_url, deleteUrl: task.delete_url,
    };
}
function showError(error) {
    operationError.value = error.message;
    loginRequired.value = !!error.loginRequired;
    needsRefresh.value = !!error.uncertain || error.code === 'NOT_FOUND';
}
async function loadTasks() {
    if (creating.value || pendingId.value !== null) return;
    loading.value = true;
    loadError.value = '';
    try {
        let page = 1;
        let more = true;
        const rows = [];
        while (more) {
            const data = await request(`query TaskList($folder_id: ID!, $page: Int!) {
                tasks(folder_id: $folder_id, page: $page, first: 50) {
                    data { ${taskFields} }
                    paginatorInfo { hasMorePages }
                }
            }`, { folder_id: String(props.folder.id), page }, 'TaskList');
            rows.push(...data.tasks.data.map(mapTask));
            more = data.tasks.paginatorInfo.hasMorePages;
            page++;
        }
        tasks.value = rows;
        if (needsRefresh.value) {
            createOpen.value = false;
            editingId.value = null;
            notice.value = '最新の一覧を取得しました。保存結果を確認してください。';
        }
        needsRefresh.value = false;
        loginRequired.value = false;
        operationError.value = '';
    } catch (error) {
        loadError.value = error.message;
        loginRequired.value = !!error.loginRequired;
    } finally {
        loading.value = false;
    }
}
async function openCreate(event) {
    if (busy.value || createOpen.value) return;
    formOpener = event?.currentTarget;
    editingId.value = null;
    draft.value = { title: '', due_date: props.today };
    fieldErrors.value = {};
    operationError.value = '';
    createOpen.value = true;
    notice.value = '';
    await nextTick();
    document.getElementById('quick-task-title')?.focus();
}
async function openEdit(task, event) {
    if (busy.value || createOpen.value) return;
    formOpener = event?.currentTarget;
    editingId.value = task.id;
    draft.value = { title: task.title, due_date: task.dueDate };
    fieldErrors.value = {};
    operationError.value = '';
    notice.value = '';
    createOpen.value = true;
    await nextTick();
    document.getElementById('quick-task-title')?.focus();
}
async function closeForm() {
    if (creating.value) return;
    createOpen.value = false;
    editingId.value = null;
    fieldErrors.value = {};
    if (!needsRefresh.value && !loginRequired.value) operationError.value = '';
    await nextTick();
    formOpener?.focus();
}
async function saveTask() {
    if (editingId.value === null) return createTask();
    if (busy.value) return;
    const current = tasks.value.find((task) => task.id === editingId.value);
    if (!current) return;
    creating.value = true;
    fieldErrors.value = {};
    operationError.value = '';
    notice.value = '';
    try {
        const data = await request(`mutation UpdateTask($folder_id: ID!, $id: ID!, $input: UpdateTaskInput!) {
            updateTask(folder_id: $folder_id, id: $id, input: $input) { ${taskFields} }
        }`, { folder_id: String(props.folder.id), id: String(current.id), input: { ...draft.value, status: statusValues[current.status] } }, 'UpdateTask');
        if (!data.updateTask) throw Object.assign(new Error('保存結果を確認できません。一覧を再取得してください。'), { uncertain: true });
        tasks.value = tasks.value.map((task) => task.id === current.id ? mapTask(data.updateTask) : task);
        createOpen.value = false;
        editingId.value = null;
        notice.value = 'タスクを更新しました。';
    } catch (error) {
        fieldErrors.value = error.validation || {};
        showError(error);
    } finally {
        creating.value = false;
    }
    if (!createOpen.value) {
        await nextTick();
        if (formOpener?.isConnected) formOpener.focus();
        else document.querySelector('input[aria-label="タスクを検索"]')?.focus();
    }
}
function openDelete(task, event) {
    if (busy.value || createOpen.value) return;
    deleteOpener = event?.currentTarget;
    deleteTarget.value = task;
    deleteError.value = '';
    operationError.value = '';
    notice.value = '';
}
async function closeDelete() {
    if (creating.value) return;
    deleteTarget.value = null;
    await nextTick();
    deleteOpener?.focus();
}
async function refreshAfterDeleteError() {
    await closeDelete();
    await loadTasks();
}
async function deleteTask() {
    if (busy.value || !deleteTarget.value) return;
    const target = deleteTarget.value;
    creating.value = true;
    deleteError.value = '';
    try {
        const data = await request(`mutation DeleteTask($folder_id: ID!, $id: ID!, $confirm: Boolean!) {
            deleteTask(folder_id: $folder_id, id: $id, confirm: $confirm) { id deleted }
        }`, { folder_id: String(props.folder.id), id: String(target.id), confirm: true }, 'DeleteTask');
        if (!data.deleteTask?.deleted || String(data.deleteTask.id) !== String(target.id)) throw Object.assign(new Error('削除結果を確認できません。一覧を再取得してください。'), { uncertain: true });
        tasks.value = tasks.value.filter((task) => task.id !== target.id);
        deleteTarget.value = null;
        notice.value = 'タスクを削除しました。';
    } catch (error) {
        deleteError.value = error.message;
        showError(error);
    } finally {
        creating.value = false;
    }
    if (!deleteTarget.value) {
        await nextTick();
        document.querySelector('input[aria-label="タスクを検索"]')?.focus();
    }
}
async function createTask() {
    if (busy.value) return;
    creating.value = true;
    fieldErrors.value = {};
    operationError.value = '';
    notice.value = '';
    try {
        const data = await request(`mutation CreateTask($folder_id: ID!, $input: CreateTaskInput!) {
            createTask(folder_id: $folder_id, input: $input) { ${taskFields} }
        }`, { folder_id: String(props.folder.id), input: { ...draft.value } }, 'CreateTask');
        if (!data.createTask) throw Object.assign(new Error('保存結果を確認できません。一覧を再取得してください。'), { uncertain: true });
        tasks.value.push(mapTask(data.createTask));
        resetFilters();
        draft.value = { title: '', due_date: props.today };
        createOpen.value = false;
        notice.value = 'タスクを追加しました。';
        await nextTick();
        document.querySelector('.add-task-button')?.focus();
    } catch (error) {
        fieldErrors.value = error.validation || {};
        showError(error);
    } finally {
        creating.value = false;
    }
}
async function updateStatus(task, status) {
    if (busy.value || task.status === status) return;
    pendingId.value = task.id;
    operationError.value = '';
    notice.value = '';
    try {
        const data = await request(`mutation UpdateTaskStatus($folder_id: ID!, $id: ID!, $status: TaskStatus!) {
            updateTaskStatus(folder_id: $folder_id, id: $id, status: $status) { ${taskFields} }
        }`, { folder_id: String(props.folder.id), id: String(task.id), status: statusValues[status] }, 'UpdateTaskStatus');
        if (!data.updateTaskStatus) throw Object.assign(new Error('保存結果を確認できません。一覧を再取得してください。'), { uncertain: true });
        tasks.value = tasks.value.map((row) => row.id === task.id ? mapTask(data.updateTaskStatus) : row);
        notice.value = 'タスクの状態を更新しました。';
    } catch (error) {
        showError(error);
    } finally {
        pendingId.value = null;
    }
}
async function changeStatus(event, task) {
    const status = Number(event.target.value);
    event.target.value = String(task.status);
    await updateStatus(task, status);
}

const filter = ref('all');
const pendingId = ref(null);
const folderNavigation = ref(null);
function updateFolders(event) {
    if (event.detail.folders) folderList.value = event.detail.folders;
    else if (event.detail.folder && !folderList.value.some((item) => String(item.id) === String(event.detail.folder.id))) folderList.value.push(event.detail.folder);
    notice.value = event.detail.message || '';
}
const restorePage = (event) => {
    if (!event.persisted) return;
    pendingId.value = null;
    creating.value = false;
    loadTasks();
};
onMounted(() => {
    window.addEventListener('pageshow', restorePage);
    window.addEventListener('tasklist:folders-changed', updateFolders);
    loadTasks();
    folderNavigation.value
        ?.querySelector('[aria-current="page"]')
        ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});
onBeforeUnmount(() => {
    window.removeEventListener('pageshow', restorePage);
    window.removeEventListener('tasklist:folders-changed', updateFolders);
});
const statuses = {
    1: { label: '未着手', variant: 'outline' },
    2: { label: '着手中', variant: 'default' },
    3: { label: '完了', variant: 'secondary' },
};
const complete = computed(
    () => tasks.value.filter((task) => task.status === 3).length,
);
const active = computed(
    () => tasks.value.filter((task) => task.status === 2).length,
);
const overdue = computed(
    () =>
        tasks.value.filter(
            (task) => task.status !== 3 && task.dueDate < props.today,
        ).length,
);
const visibleTasks = computed(() =>
    tasks.value.filter((task) => {
        const matchesSearch = task.title
            .toLocaleLowerCase()
            .includes(search.value.trim().toLocaleLowerCase());
        const matchesStatus =
            filter.value === 'all' ||
            (filter.value === 'open' ? task.status !== 3 : task.status === 3);
        return matchesSearch && matchesStatus;
    }),
);
const completion = computed(() =>
    tasks.value.length
        ? Math.round((complete.value / tasks.value.length) * 100)
        : 0,
);
const filters = computed(() => [
    { value: 'all', label: 'すべて', count: tasks.value.length },
    {
        value: 'open',
        label: '未完了',
        count: tasks.value.length - complete.value,
    },
    { value: 'done', label: '完了', count: complete.value },
]);
function acceptCandidate(task) {
    if (!tasks.value.some((item) => String(item.id) === String(task.id))) tasks.value.push(mapTask(task));
    resetFilters();
    notice.value = 'メールの候補をタスクに追加しました。';
}
function resetFilters() {
    search.value = '';
    filter.value = 'all';
}
function toggle(task) {
    return updateStatus(task, task.status === 3 ? 1 : 3);
}
</script>

<template>
    <div class="todo-workspace">
        <aside class="workspace-sidebar" aria-label="フォルダ一覧">
            <div class="sidebar-heading">
                ワークスペース
                <Badge variant="outline">{{ folderList.length }}</Badge>
            </div>
            <nav ref="folderNavigation" class="folders-navigation">
                <Button
                    v-for="item in folderList"
                    :key="item.id"
                    as-child
                    :variant="item.id === folder.id ? 'secondary' : 'ghost'"
                    class="folder-link"
                >
                    <a
                        :href="item.url"
                        :aria-current="
                            item.id === folder.id ? 'page' : undefined
                        "
                        ><AppIcon name="folder" /><span>{{
                            item.title
                        }}</span></a
                    >
                </Button>
            </nav>
            <Button as-child variant="outline" class="new-folder-button"
                ><a data-create-folder :href="routes.createFolder"
                    ><AppIcon name="plus" />フォルダを作成</a
                ></Button
            >
        </aside>
        <section class="workspace-main" aria-labelledby="workspace-title">
            <header class="workspace-heading">
                <div>
                    <h1 id="workspace-title">{{ folder.title }}</h1>
                </div>
                <Button type="button" class="add-task-button" :disabled="busy || createOpen" :aria-expanded="createOpen" aria-controls="quick-task-form" @click="openCreate"
                    ><AppIcon name="plus" />タスクを追加</Button
                >
            </header>
            <p v-if="loading" class="workspace-message" role="status">タスクを読み込み中…</p>
            <div v-if="loadError || operationError" class="workspace-error" role="alert">
                <p>{{ loadError || operationError }}</p>
                <a v-if="loginRequired" :href="routes.login">ログインする</a>
                <Button v-else-if="loadError || needsRefresh" type="button" variant="outline" size="sm" :disabled="loading || creating || pendingId !== null" @click="loadTasks">一覧を再取得</Button>
            </div>
            <p v-if="notice" class="workspace-message" role="status">{{ notice }}</p>
            <Card v-if="createOpen" class="quick-task-card">
                <CardHeader><CardTitle>{{ editingId === null ? 'タスクを追加' : 'タスクを編集' }}</CardTitle></CardHeader>
                <CardContent>
                    <form id="quick-task-form" class="quick-task-form" novalidate @submit.prevent="saveTask" :aria-busy="creating">
                        <div class="enhanced-field">
                            <label for="quick-task-title">タスク名 <span class="required-mark">*</span></label>
                            <Input id="quick-task-title" name="title" v-model="draft.title" required maxlength="100" :disabled="creating" :aria-invalid="!!fieldErrors.title" :aria-describedby="fieldErrors.title ? 'quick-title-error' : undefined" />
                            <p v-if="fieldErrors.title" id="quick-title-error" class="field-error">{{ fieldErrors.title[0] }}</p>
                        </div>
                        <div class="enhanced-field">
                            <label for="quick-task-due-date">期限 <span class="required-mark">*</span></label>
                            <Input id="quick-task-due-date" name="due_date" type="date" v-model="draft.due_date" required :min="editingId === null ? today : undefined" :disabled="creating" :aria-invalid="!!fieldErrors.due_date" :aria-describedby="fieldErrors.due_date ? 'quick-date-error' : undefined" />
                            <p v-if="fieldErrors.due_date" id="quick-date-error" class="field-error">{{ fieldErrors.due_date[0] }}</p>
                        </div>
                        <div class="quick-task-actions">
                            <Button type="button" variant="outline" :disabled="creating" @click="closeForm">キャンセル</Button>
                            <Button type="submit" :disabled="busy"><span v-if="creating" class="pending-spinner" aria-hidden="true" />{{ creating ? '保存中…' : editingId === null ? '保存する' : '更新する' }}</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
            <TaskCandidates :endpoint="routes.graphql" :csrf="csrf" :folder="folder" :today="today" :login-url="routes.login" :blocked="busy" @accepted="acceptCandidate" @refresh="loadTasks" />
            <div class="workspace-overview" aria-label="タスクの進捗">
                <Card
                    ><CardHeader
                        ><CardDescription>完了したタスク</CardDescription
                        ><CardTitle class="stat-number"
                            >{{ complete
                            }}<span> / {{ tasks.length }}</span></CardTitle
                        ></CardHeader
                    ><CardContent
                        ><div class="progress-caption">
                            <span>完了率</span><span>{{ completion }}%</span>
                        </div>
                        <Progress
                            :model-value="completion"
                            aria-label="タスクの完了率" /></CardContent
                ></Card>
                <Card
                    ><CardHeader
                        ><CardDescription>着手中</CardDescription
                        ><CardTitle class="stat-number"
                            >{{ active }}<span> 件</span></CardTitle
                        ></CardHeader
                    ></Card
                >
                <Card
                    ><CardHeader
                        ><CardDescription>期限超過</CardDescription
                        ><CardTitle class="stat-number"
                            >{{ overdue }}<span> 件</span></CardTitle
                        ></CardHeader
                    ></Card
                >
            </div>
            <Card class="task-panel">
                <CardHeader class="grid-rows-1"
                    ><CardTitle>タスク一覧</CardTitle></CardHeader
                >
                <CardContent>
                    <Tabs v-model="filter">
                        <div class="task-toolbar">
                            <TabsList aria-label="タスクの絞り込み"
                                ><TabsTrigger
                                    v-for="item in filters"
                                    :key="item.value"
                                    :value="item.value"
                                    >{{ item.label
                                    }}<span class="filter-count">{{
                                        item.count
                                    }}</span></TabsTrigger
                                ></TabsList
                            >
                            <div class="task-search">
                                <AppIcon name="search" /><Input
                                    v-model="search"
                                    placeholder="タスクを検索"
                                    aria-label="タスクを検索"
                                />
                            </div>
                        </div>
                        <TabsContent :value="filter" class="task-tab-content">
                            <div class="task-column-head" aria-hidden="true">
                                <span>タスク名</span><span>状態</span
                                ><span>期限</span
                                ><span class="actions-label">操作</span>
                            </div>
                            <ul
                                v-if="visibleTasks.length"
                                class="task-list"
                                aria-label="タスク一覧"
                            >
                                <li
                                    v-for="task in visibleTasks"
                                    :key="task.id"
                                    :class="[
                                        'task-row',
                                        { 'task-completed': task.status === 3 },
                                    ]"
                                >
                                    <div class="task-title-cell">
                                        <Button
                                            type="button"
                                            @click="toggle(task)"
                                            :aria-label="`${task.title}を${task.status === 3 ? '未着手に戻す' : '完了にする'}`"
                                            :aria-pressed="task.status === 3"
                                            :variant="task.status === 3 ? 'default' : 'outline'"
                                            size="icon-sm"
                                            class="complete-toggle"
                                            :disabled="busy"
                                        ><span v-if="pendingId === task.id" class="pending-spinner" aria-hidden="true" /><AppIcon v-else-if="task.status === 3" name="check" /><span v-else class="empty-check" /></Button>
                                        <button
                                            type="button"
                                            class="task-name"
                                            :disabled="busy || createOpen"
                                            @click="openEdit(task, $event)"
                                            >{{ task.title }}</button
                                        >
                                    </div>
                                    <div class="task-status-cell">
                                        <select class="task-status-select" :value="task.status" :aria-label="`${task.title}の状態`" :disabled="busy" @change="changeStatus($event, task)">
                                            <option v-for="(item, value) in statuses" :key="value" :value="value">{{ item.label }}</option>
                                        </select>
                                    </div>
                                    <div
                                        :class="[
                                            'task-date',
                                            {
                                                overdue:
                                                    task.status !== 3 &&
                                                    task.dueDate < today,
                                            },
                                        ]"
                                    >
                                        <AppIcon name="calendar" /><time
                                            :datetime="task.dueDate"
                                            >{{ task.dateLabel }}</time
                                        ><span
                                            v-if="
                                                task.status !== 3 &&
                                                task.dueDate < today
                                            "
                                            class="overdue-label"
                                            >期限超過</span
                                        >
                                    </div>
                                    <div class="task-row-actions">
                                        <Button type="button" variant="ghost" size="icon-sm" :disabled="busy || createOpen" :aria-label="`${task.title}を編集`" @click="openEdit(task, $event)"><AppIcon name="edit" /></Button>
                                        <Button type="button" variant="ghost" size="icon-sm" :disabled="busy || createOpen" :aria-label="`${task.title}を削除`" @click="openDelete(task, $event)"><AppIcon name="trash" /></Button>
                                    </div>
                                </li>
                            </ul>
                            <div v-else-if="!loading && !loadError" class="task-empty" role="status">
                                <div class="empty-symbol">
                                    <AppIcon name="inbox" />
                                </div>
                                <h2>
                                    {{
                                        tasks.length
                                            ? '一致するタスクがありません'
                                            : 'タスクがありません'
                                    }}
                                </h2>
                                <Button
                                    v-if="tasks.length"
                                    type="button"
                                    variant="outline"
                                    @click="resetFilters"
                                    >絞り込みをリセット</Button
                                ><Button v-else type="button" :disabled="busy" @click="openCreate"
                                    ><AppIcon name="plus" />タスクを追加</Button
                                >
                            </div>
                        </TabsContent>
                    </Tabs>
                </CardContent>
                <CardFooter class="task-panel-footer"
                    ><span aria-live="polite"
                        >{{ visibleTasks.length }} /
                        {{ tasks.length }} 件を表示</span
                    ></CardFooter
                >
            </Card>
            <div class="folder-settings">
                <Button as-child variant="ghost" size="sm"
                    ><a :href="routes.editFolder"
                        ><AppIcon name="edit" />フォルダ名を編集</a
                    ></Button
                ><Button as-child variant="ghost" size="sm"
                    ><a :href="routes.deleteFolder" class="folder-delete"
                        ><AppIcon name="trash" />フォルダを削除</a
                    ></Button
                >
            </div>
        </section>
    </div>
    <TaskDeleteDialog v-if="deleteTarget" :task="deleteTarget" :pending="creating" :blocked="needsRefresh || loginRequired" :error="deleteError" :needs-refresh="needsRefresh" :login-required="loginRequired" :login-url="routes.login" @confirm="deleteTask" @cancel="closeDelete" @refresh="refreshAfterDeleteError" />
</template>
