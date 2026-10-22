<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ja } from '@nuxt/ui/locale';
import AppIcon from './AppIcon.vue';

const props = defineProps({
    folders: Array,
    tasks: Array,
    folder: Object,
    routes: Object,
    csrf: String,
    today: String,
});
const search = ref('');
const filter = ref('all');
const pendingId = ref(null);
const folderNavigation = ref(null);
const resetPending = () => {
    pendingId.value = null;
};
onMounted(() => {
    window.addEventListener('pageshow', resetPending);
    folderNavigation.value
        ?.querySelector('[aria-current="page"]')
        ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});
onBeforeUnmount(() => window.removeEventListener('pageshow', resetPending));
const statuses = {
    1: { label: '未着手', color: 'neutral' },
    2: { label: '着手中', color: 'warning' },
    3: { label: '完了', color: 'success' },
};
const complete = computed(
    () => props.tasks.filter((task) => task.status === 3).length,
);
const active = computed(
    () => props.tasks.filter((task) => task.status === 2).length,
);
const overdue = computed(
    () =>
        props.tasks.filter(
            (task) => task.status !== 3 && task.dueDate < props.today,
        ).length,
);
const visibleTasks = computed(() =>
    props.tasks.filter((task) => {
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
    props.tasks.length
        ? Math.round((complete.value / props.tasks.length) * 100)
        : 0,
);
const filters = computed(() => [
    { value: 'all', label: 'すべて', count: props.tasks.length },
    {
        value: 'open',
        label: '未完了',
        count: props.tasks.length - complete.value,
    },
    { value: 'done', label: '完了', count: complete.value },
]);
function resetFilters() {
    search.value = '';
    filter.value = 'all';
}
function toggle(event, task) {
    if (pendingId.value !== null) event.preventDefault();
    else pendingId.value = task.id;
}
</script>

<template>
    <UApp :locale="ja">
        <div class="todo-workspace">
            <aside class="workspace-sidebar" aria-label="フォルダ一覧">
                <div class="sidebar-heading">
                    <span>マイフォルダ</span
                    ><span class="sidebar-count">{{ folders.length }}</span>
                </div>
                <nav ref="folderNavigation" class="folders-navigation">
                    <a
                        v-for="item in folders"
                        :key="item.id"
                        :href="item.url"
                        :class="[
                            'folder-link',
                            { selected: item.id === folder.id },
                        ]"
                        :aria-current="
                            item.id === folder.id ? 'page' : undefined
                        "
                    >
                        <AppIcon name="folder" /><span>{{ item.title }}</span
                        ><AppIcon
                            v-if="item.id === folder.id"
                            name="arrow"
                            class="folder-arrow"
                        />
                    </a>
                </nav>
                <UButton
                    :to="routes.createFolder"
                    target="_self"
                    variant="ghost"
                    color="neutral"
                    class="new-folder-button"
                    ><AppIcon name="plus" />フォルダを作成</UButton
                >
                <div class="sidebar-note">
                    <span class="tiny-check">✓</span>
                    <p>
                        ひとつずつ、着実に。<br /><span
                            >今日の小さな一歩をここに。</span
                        >
                    </p>
                </div>
            </aside>

            <section class="workspace-main" aria-labelledby="workspace-title">
                <div class="workspace-breadcrumb">
                    ワークスペース <span>/</span> {{ folder.title }}
                </div>
                <header class="workspace-heading">
                    <div>
                        <p class="eyebrow">MAKE ROOM FOR WHAT MATTERS</p>
                        <h1 id="workspace-title">
                            {{ folder.title }}<span class="title-dot">.</span>
                        </h1>
                        <p class="workspace-description">
                            やることを整理して、次の一歩へ。
                        </p>
                    </div>
                    <UButton
                        :to="routes.createTask"
                        target="_self"
                        color="neutral"
                        size="lg"
                        class="add-task-button"
                        ><AppIcon name="plus" />タスクを追加</UButton
                    >
                </header>

                <div class="workspace-overview" aria-label="タスクの進捗">
                    <div class="overview-progress">
                        <div>
                            <span class="overview-label">完了したタスク</span
                            ><strong
                                >{{ complete
                                }}<span> / {{ tasks.length }}</span></strong
                            >
                        </div>
                        <div class="progress-track">
                            <span>{{ completion }}%</span
                            ><UProgress
                                :model-value="completion"
                                color="primary"
                                size="sm"
                                aria-label="タスクの完了率"
                            />
                        </div>
                    </div>
                    <div class="overview-stat">
                        <span class="overview-label">着手中</span
                        ><strong>{{ active }}<small>件</small></strong>
                    </div>
                    <div class="overview-stat">
                        <span class="overview-label">期限超過</span
                        ><strong :class="{ 'overdue-count': overdue > 0 }"
                            >{{ overdue }}<small>件</small></strong
                        >
                    </div>
                </div>

                <div class="task-panel">
                    <div class="task-toolbar">
                        <div
                            class="task-filters"
                            role="group"
                            aria-label="タスクの絞り込み"
                        >
                            <UButton
                                v-for="item in filters"
                                :key="item.value"
                                :variant="
                                    filter === item.value ? 'soft' : 'ghost'
                                "
                                color="neutral"
                                :aria-pressed="filter === item.value"
                                @click="filter = item.value"
                                >{{ item.label
                                }}<span class="filter-count">{{
                                    item.count
                                }}</span></UButton
                            >
                        </div>
                        <UInput
                            v-model="search"
                            placeholder="タスクを検索"
                            aria-label="タスクを検索"
                            class="task-search"
                            ><template #leading
                                ><AppIcon name="search" /></template
                        ></UInput>
                    </div>
                    <div class="task-column-head" aria-hidden="true">
                        <span>タスク</span><span>状態</span><span>期限</span
                        ><span>操作</span>
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
                                <form
                                    :action="task.editUrl"
                                    method="POST"
                                    @submit="toggle($event, task)"
                                >
                                    <input
                                        type="hidden"
                                        name="_token"
                                        :value="csrf"
                                    /><input
                                        type="hidden"
                                        name="title"
                                        :value="task.title"
                                    /><input
                                        type="hidden"
                                        name="due_date"
                                        :value="task.dueDate"
                                    /><input
                                        type="hidden"
                                        name="status"
                                        :value="task.status === 3 ? 1 : 3"
                                    />
                                    <UButton
                                        type="submit"
                                        :aria-label="`${task.title}を${task.status === 3 ? '未着手に戻す' : '完了にする'}`"
                                        :aria-pressed="task.status === 3"
                                        :class="[
                                            'complete-toggle',
                                            { checked: task.status === 3 },
                                        ]"
                                        variant="outline"
                                        color="neutral"
                                        :loading="pendingId === task.id"
                                        :disabled="pendingId !== null"
                                        ><AppIcon
                                            v-if="task.status === 3"
                                            name="check" /><span
                                            v-else
                                            class="empty-check"
                                    /></UButton>
                                </form>
                                <a :href="task.editUrl" class="task-name">{{
                                    task.title
                                }}</a>
                            </div>
                            <div class="task-status-cell">
                                <UBadge
                                    :class="`task-status-${task.status}`"
                                    :color="
                                        statuses[task.status]?.color ||
                                        'neutral'
                                    "
                                    variant="subtle"
                                    size="sm"
                                    >{{
                                        statuses[task.status]?.label || '不明'
                                    }}</UBadge
                                >
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
                                <UButton
                                    :to="task.editUrl"
                                    target="_self"
                                    color="neutral"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`${task.title}を編集`"
                                    ><AppIcon name="edit" /><span
                                        class="action-text"
                                        >編集</span
                                    ></UButton
                                ><UButton
                                    :to="task.deleteUrl"
                                    target="_self"
                                    color="neutral"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`${task.title}を削除`"
                                    ><AppIcon name="trash" /><span
                                        class="action-text"
                                        >削除</span
                                    ></UButton
                                >
                            </div>
                        </li>
                    </ul>
                    <div v-else class="task-empty" role="status">
                        <div class="empty-symbol"><AppIcon name="inbox" /></div>
                        <h2>
                            {{
                                tasks.length
                                    ? '一致するタスクがありません'
                                    : 'ここから、ひとつずつ。'
                            }}
                        </h2>
                        <p>
                            {{
                                tasks.length
                                    ? '検索する言葉や絞り込み条件を変えてみてください。'
                                    : '最初のタスクを追加して、やることを整理しましょう。'
                            }}
                        </p>
                        <UButton
                            v-if="tasks.length"
                            variant="outline"
                            color="neutral"
                            @click="resetFilters"
                            >絞り込みをリセット</UButton
                        ><UButton
                            v-else
                            :to="routes.createTask"
                            target="_self"
                            color="neutral"
                            ><AppIcon name="plus" />タスクを追加</UButton
                        >
                    </div>
                    <footer class="task-panel-footer">
                        <span aria-live="polite"
                            >{{ visibleTasks.length }} /
                            {{ tasks.length }} 件を表示</span
                        ><span>小さな完了を、積み重ねよう。</span>
                    </footer>
                </div>
                <div class="folder-settings">
                    <a :href="routes.editFolder"
                        ><AppIcon name="edit" />フォルダ名を編集</a
                    ><a :href="routes.deleteFolder" class="folder-delete"
                        ><AppIcon name="trash" />フォルダを削除</a
                    >
                </div>
            </section>
        </div>
    </UApp>
</template>
