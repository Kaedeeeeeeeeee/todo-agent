import { createApp } from 'vue';
import ui from '@nuxt/ui/vue-plugin';
import TaskWorkspace from './components/TaskWorkspace.vue';
import EnhancedForm from './components/EnhancedForm.vue';

const errors = JSON.parse(
    document.getElementById('server-errors')?.textContent || '{}',
);
const workspace = document.querySelector('[data-task-workspace]');

if (workspace) {
    const props = JSON.parse(
        document.getElementById('task-workspace-props').textContent,
    );
    createApp(TaskWorkspace, props).use(ui).mount(workspace);
}

// Keep Laravel's ordinary POST, CSRF protection, and validation redirects.
for (const form of document.querySelectorAll('form[data-ui-form]')) {
    const fields = [
        ...form.querySelectorAll('input:not([type="hidden"]), select'),
    ].map((input) => ({
        name: input.name,
        id: input.id || input.name,
        type: input.tagName === 'SELECT' ? 'select' : input.type,
        label:
            form
                .querySelector(`label[for="${input.id}"]`)
                ?.textContent.trim() ||
            input.closest('label')?.textContent.trim() ||
            input.name,
        value: input.type === 'checkbox' ? input.checked : input.value,
        checkedValue: input.value,
        required: input.required,
        autocomplete: input.autocomplete,
        autofocus: input.autofocus,
        min: input.getAttribute('min'),
        minlength: input.getAttribute('minlength'),
        maxlength: input.getAttribute('maxlength'),
        options:
            input.tagName === 'SELECT'
                ? [...input.options].map((option) => ({
                      label: option.text,
                      value: option.value,
                  }))
                : [],
    }));
    const submit = form.querySelector('button[type="submit"]');
    const props = {
        action: form.action,
        fields,
        hiddenFields: [...form.querySelectorAll('input[type="hidden"]')].map(
            (input) => ({ name: input.name, value: input.value }),
        ),
        errors,
        label: submit.textContent.trim(),
        destructive: submit.classList.contains('danger'),
        cancel: form.querySelector('a.secondary')?.href,
    };
    const root = document.createElement('div');
    form.replaceWith(root);
    createApp(EnhancedForm, props).use(ui).mount(root);
}
