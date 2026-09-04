<template>
  <div class="flex md:flex-row">
    <button
      @click="toggle"
      class="px-3 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg transition-colors"
    >
      Toggle
    </button>
    <div v-if="isOpen" class="mt-2 p-4 bg-gray-50 border border-gray-200 rounded-xl">
      <slot />
    </div>
  </div>
</template>
