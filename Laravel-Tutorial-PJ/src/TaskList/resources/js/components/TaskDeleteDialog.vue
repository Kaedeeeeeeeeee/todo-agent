<script setup>
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps({
    task: { type: Object, required: true },
    pending: Boolean,
    blocked: Boolean,
    error: String,
    needsRefresh: Boolean,
    loginRequired: Boolean,
    loginUrl: String,
});
const emit = defineEmits(['confirm', 'cancel', 'refresh']);
const dialog = ref(null);
onMounted(() => dialog.value.showModal());
</script>

<template>
    <dialog ref="dialog" class="task-delete-dialog" aria-labelledby="delete-task-heading" aria-describedby="delete-task-description" :aria-busy="pending" @cancel.prevent="!pending && emit('cancel')">
        <h2 id="delete-task-heading">タスクを削除しますか？</h2>
        <p class="delete-task-name">{{ task.title }}</p>
        <p id="delete-task-description">この操作は取り消せません。</p>
        <div v-if="error" class="delete-task-error" role="alert">
            <p>{{ error }}</p>
            <a v-if="loginRequired" :href="loginUrl">ログインする</a>
            <Button v-else-if="needsRefresh" type="button" variant="outline" size="sm" @click="emit('refresh')">閉じて一覧を再取得</Button>
        </div>
        <div class="quick-task-actions">
            <Button type="button" variant="outline" autofocus :disabled="pending" @click="emit('cancel')">キャンセル</Button>
            <Button type="button" variant="destructive" :disabled="pending || blocked" @click="emit('confirm')"><span v-if="pending" class="pending-spinner" aria-hidden="true" />{{ pending ? '削除中…' : 'タスクを削除' }}</Button>
        </div>
    </dialog>
</template>
