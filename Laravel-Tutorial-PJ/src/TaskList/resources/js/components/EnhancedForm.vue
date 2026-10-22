<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectGroup,
    SelectItem,
} from '@/components/ui/select';

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
    <form :action="action" method="POST" class="enhanced-form" @submit="submit">
        <input
            v-for="field in hiddenFields"
            :key="field.name"
            type="hidden"
            :name="field.name"
            :value="field.value"
        />
        <template v-for="field in fields" :key="field.name">
            <label v-if="field.type === 'checkbox'" class="remember-field"
                ><input
                    v-model="values[field.name]"
                    type="checkbox"
                    :name="field.name"
                    :value="field.checkedValue"
                />{{ field.label }}</label
            >
            <div
                v-else
                class="enhanced-field"
                :data-invalid="errors[field.name] ? true : undefined"
            >
                <Label :for="field.id"
                    >{{ field.label
                    }}<span v-if="field.required" class="required-mark"
                        >*</span
                    ></Label
                >
                <template v-if="field.type === 'select'">
                    <Select v-model="values[field.name]">
                        <SelectTrigger
                            :id="field.id"
                            class="w-full"
                            :aria-invalid="!!errors[field.name]"
                            :aria-describedby="
                                errors[field.name]
                                    ? `${field.id}-error`
                                    : undefined
                            "
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent
                            ><SelectGroup
                                ><SelectItem
                                    v-for="option in field.options"
                                    :key="option.value"
                                    :value="option.value"
                                    >{{ option.label }}</SelectItem
                                ></SelectGroup
                            ></SelectContent
                        >
                    </Select>
                    <input
                        type="hidden"
                        :name="field.name"
                        :value="values[field.name]"
                    />
                </template>
                <Input
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
                    :aria-invalid="!!errors[field.name]"
                    :aria-describedby="
                        errors[field.name] ? `${field.id}-error` : undefined
                    "
                />
                <p
                    v-if="errors[field.name]"
                    :id="`${field.id}-error`"
                    class="field-error"
                >
                    {{ errors[field.name][0] }}
                </p>
            </div>
        </template>
        <div class="form-submit-row">
            <Button v-if="cancel" as-child variant="outline"
                ><a :href="cancel">キャンセル</a></Button
            >
            <Button
                type="submit"
                :variant="destructive ? 'destructive' : 'default'"
                :disabled="submitting"
                ><span
                    v-if="submitting"
                    class="pending-spinner"
                    aria-hidden="true"
                />{{ submitting ? '送信しています…' : label }}</Button
            >
        </div>
    </form>
</template>
