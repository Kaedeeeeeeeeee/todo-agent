<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
defineProps({ createUrl: String });
const folders = ref([]);
const notice = ref('');
function update(event) {
    if (event.detail.folders) folders.value = event.detail.folders;
    else if (event.detail.folder && !folders.value.some((item) => item.id === event.detail.folder.id)) folders.value.push(event.detail.folder);
    notice.value = event.detail.message;
}
onMounted(() => window.addEventListener('tasklist:folders-changed', update));
onBeforeUnmount(() => window.removeEventListener('tasklist:folders-changed', update));
</script>

<template>
    <section class="card form-card">
        <h1>{{ folders.length ? 'フォルダ一覧' : '最初のフォルダを作りましょう' }}</h1>
        <p v-if="notice" class="workspace-message" role="status">{{ notice }}</p>
        <ul v-if="folders.length" class="folder-list"><li v-for="folder in folders" :key="folder.id"><a :href="folder.url">{{ folder.title }}</a></li></ul>
        <a data-create-folder class="button" :href="createUrl">フォルダを作成</a>
    </section>
</template>
