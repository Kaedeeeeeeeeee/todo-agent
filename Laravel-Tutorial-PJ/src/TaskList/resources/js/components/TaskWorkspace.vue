<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
    1: { label: '未着手', variant: 'outline' },
    2: { label: '着手中', variant: 'default' },
    3: { label: '完了', variant: 'secondary' },
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
    <div class="todo-workspace">
        <aside class="workspace-sidebar" aria-label="フォルダ一覧">
            <div class="sidebar-heading">
                ワークスペース
                <Badge variant="outline">{{ folders.length }}</Badge>
            </div>
            <nav ref="folderNavigation" class="folders-navigation">
                <Button
                    v-for="item in folders"
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
                ><a :href="routes.createFolder"
                    ><AppIcon name="plus" />フォルダを作成</a
                ></Button
            >
        </aside>
        <section class="workspace-main" aria-labelledby="workspace-title">
            <header class="workspace-heading">
                <div>
                    <h1 id="workspace-title">{{ folder.title }}</h1>
                </div>
                <Button as-child class="add-task-button"
                    ><a :href="routes.createTask"
                        ><AppIcon name="plus" />タスクを追加</a
                    ></Button
                >
            </header>
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
                                                :value="
                                                    task.status === 3 ? 1 : 3
                                                "
                                            />
                                            <Button
                                                type="submit"
                                                :aria-label="`${task.title}を${task.status === 3 ? '未着手に戻す' : '完了にする'}`"
                                                :aria-pressed="
                                                    task.status === 3
                                                "
                                                :variant="
                                                    task.status === 3
                                                        ? 'default'
                                                        : 'outline'
                                                "
                                                size="icon-sm"
                                                class="complete-toggle"
                                                :disabled="pendingId !== null"
                                                ><span
                                                    v-if="pendingId === task.id"
                                                    class="pending-spinner"
                                                    aria-hidden="true" /><AppIcon
                                                    v-else-if="
                                                        task.status === 3
                                                    "
                                                    name="check" /><span
                                                    v-else
                                                    class="empty-check"
                                            /></Button>
                                        </form>
                                        <a
                                            :href="task.editUrl"
                                            class="task-name"
                                            >{{ task.title }}</a
                                        >
                                    </div>
                                    <div class="task-status-cell">
                                        <Badge
                                            :variant="
                                                statuses[task.status]
                                                    ?.variant || 'outline'
                                            "
                                            >{{
                                                statuses[task.status]?.label ||
                                                '不明'
                                            }}</Badge
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
                                        <Button
                                            as-child
                                            variant="ghost"
                                            size="icon-sm"
                                            ><a
                                                :href="task.editUrl"
                                                :aria-label="`${task.title}を編集`"
                                                ><AppIcon
                                                    name="edit" /></a></Button
                                        ><Button
                                            as-child
                                            variant="ghost"
                                            size="icon-sm"
                                            ><a
                                                :href="task.deleteUrl"
                                                :aria-label="`${task.title}を削除`"
                                                ><AppIcon name="trash" /></a
                                        ></Button>
                                    </div>
                                </li>
                            </ul>
                            <div v-else class="task-empty" role="status">
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
                                ><Button v-else as-child
                                    ><a :href="routes.createTask"
                                        ><AppIcon name="plus" />タスクを追加</a
                                    ></Button
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
</template>
