<script setup>
defineProps({ name: { type: String, default: 'check' } });

const paths = {
    check: 'm5 12 4 4L19 6',
    plus: 'M12 5v14M5 12h14',
    search: 'm21 21-4.4-4.4M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
    folder: 'M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z',
    arrow: 'm9 5 7 7-7 7',
    edit: 'm16 3 5 5-12 12-6 1 1-6L16 3ZM14 5l5 5',
    trash: 'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7',
    inbox: 'M4 4h16l2 12v4H2v-4L4 4ZM2 16h6l2 3h4l2-3h6',
    calendar:
        'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z',
};
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        class="app-icon"
    >
        <path :d="paths[name] || paths.check" />
    </svg>
</template>
