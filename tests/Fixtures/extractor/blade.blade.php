@php
    $classes = 'flex flex-col gap-2';
@endphp
<div class="{{ $classes }} rounded-lg border border-gray-200 p-6">
    @if ($user->isAdmin())
        <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">Admin</span>
    @endif
    <button wire:click="save" class="w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md">
        Save
    </button>
</div>
