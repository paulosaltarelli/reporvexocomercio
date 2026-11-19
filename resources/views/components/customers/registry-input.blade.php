<div class="field">
    <label for="{{ $prefix }}{{ $field }}">{{ $label }}</label>
    <input id="{{ $prefix }}{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $limit }}"
        value="{{ $prefix ? '' : old($field, $editing?->$field) }}" @required($field === 'name')>
</div>
