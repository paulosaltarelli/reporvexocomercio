<div class="field">
    <label for="{{ $field }}">{{ $label }}</label>
    <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $limit }}"
        value="{{ old($field, $editing?->$field) }}" @required($field === 'name')>
</div>
