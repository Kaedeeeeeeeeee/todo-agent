<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { ja } from '@nuxt/ui/locale';
import AppIcon from './AppIcon.vue';

const props = defineProps({
    action: String,
    fields: Array,
    hiddenFields: Array,
    errors: Object,
    label: String,
    destructive: Boolean,
    cancel: String,
});
const values = reactive(
    Object.fromEntries(props.fields.map((field) => [field.name, field.value])),
);
const submitting = ref(false);
const resetPending = () => {
    submitting.value = false;
};
onMounted(() => window.addEventListener('pageshow', resetPending));
onBeforeUnmount(() => window.removeEventListener('pageshow', resetPending));

function submit(event) {
    if (submitting.value) event.preventDefault();
    else submitting.value = true;
}
</script>

<template>
    <UApp :locale="ja">
        <form
            :action="action"
            method="POST"
            class="enhanced-form"
            @submit="submit"
        >
            <input
                v-for="field in hiddenFields"
                :key="field.name"
                type="hidden"
                :name="field.name"
                :value="field.value"
            />
            <template v-for="field in fields" :key="field.name">
                <label v-if="field.type === 'checkbox'" class="remember-field">
                    <input
                        v-model="values[field.name]"
                        type="checkbox"
                        :name="field.name"
                        :value="field.checkedValue"
                    />
                    {{ field.label }}
                </label>
                <UFormField
                    v-else
                    :label="field.label"
                    :required="field.required"
                    :error="errors[field.name]?.[0]"
                    :name="field.name"
                >
                    <template v-if="field.type === 'select'">
                        <USelect
                            :id="field.id"
                            v-model="values[field.name]"
                            :items="field.options"
                            size="lg"
                            class="w-full"
                            :aria-invalid="!!errors[field.name]"
                        >
                            <template #trailing
                                ><AppIcon name="arrow" class="select-chevron"
                            /></template>
                        </USelect>
                        <input
                            type="hidden"
                            :name="field.name"
                            :value="values[field.name]"
                        />
                    </template>
                    <UInput
                        v-else
                        :id="field.id"
                        v-model="values[field.name]"
                        :name="field.name"
                        :type="field.type"
                        :required="field.required"
                        :autocomplete="field.autocomplete"
                        :autofocus="field.autofocus"
                        :min="field.min"
                        :minlength="field.minlength"
                        :maxlength="field.maxlength"
                        size="lg"
                        class="w-full"
                        :aria-invalid="!!errors[field.name]"
                    />
                </UFormField>
            </template>
            <div class="form-submit-row">
                <UButton
                    v-if="cancel"
                    :to="cancel"
                    target="_self"
                    color="neutral"
                    variant="outline"
                    size="lg"
                    >キャンセル</UButton
                >
                <UButton
                    type="submit"
                    :color="destructive ? 'error' : 'neutral'"
                    size="lg"
                    :loading="submitting"
                    :disabled="submitting"
                >
                    {{ submitting ? '送信しています…' : label }}
                </UButton>
            </div>
        </form>
    </UApp>
</template>
