<div class="field">
    <label for="title">タスク名</label>
    <input id="title" name="title" value="{{ old('title', $task->title ?? '') }}" maxlength="100" required>
</div>
@if (isset($task))
    <div class="field">
        <label for="status">状態</label>
        <select id="status" name="status" required>
            @foreach (\App\Models\Task::STATUS as $value => $status)
                <option value="{{ $value }}" @selected((string) old('status', $task->status) === (string) $value)>{{ $status['label'] }}</option>
            @endforeach
        </select>
    </div>
@endif
<div class="field">
    <label for="due_date">期限</label>
    <input id="due_date" type="date" name="due_date" value="{{ old('due_date', isset($task) ? $task->due_date->format('Y-m-d') : '') }}" @if (! isset($task)) min="{{ today()->toDateString() }}" @endif required>
</div>
