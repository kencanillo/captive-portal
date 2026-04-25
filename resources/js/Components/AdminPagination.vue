<script setup>
import { computed } from 'vue';

const props = defineProps({
  currentPage: {
    type: Number,
    required: true,
  },
  lastPage: {
    type: Number,
    required: true,
  },
  total: {
    type: Number,
    default: 0,
  },
  from: {
    type: Number,
    default: 0,
  },
  to: {
    type: Number,
    default: 0,
  },
});

const emit = defineEmits(['change']);

const pages = computed(() => {
  const start = Math.max(1, props.currentPage - 2);
  const end = Math.min(props.lastPage, props.currentPage + 2);
  const items = [];

  for (let page = start; page <= end; page += 1) {
    items.push(page);
  }

  return items;
});

const goTo = (page) => {
  if (page < 1 || page > props.lastPage || page === props.currentPage) {
    return;
  }

  emit('change', page);
};
</script>

<template>
  <div v-if="lastPage > 1" class="flex flex-col gap-3 border-t border-slate-200/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
    <p class="text-xs text-slate-500">
      Showing <span class="font-semibold text-slate-700">{{ from }}</span> to <span class="font-semibold text-slate-700">{{ to }}</span>
      of <span class="font-semibold text-slate-700">{{ total }}</span>
    </p>

    <div class="flex items-center gap-2">
      <button type="button" class="app-button-ghost px-3 py-2 text-xs" :disabled="currentPage === 1" @click="goTo(currentPage - 1)">
        Prev
      </button>
      <button
        v-for="page in pages"
        :key="page"
        type="button"
        class="inline-flex h-9 min-w-9 items-center justify-center rounded-full border px-3 text-xs font-semibold transition"
        :class="page === currentPage ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950'"
        @click="goTo(page)"
      >
        {{ page }}
      </button>
      <button type="button" class="app-button-ghost px-3 py-2 text-xs" :disabled="currentPage === lastPage" @click="goTo(currentPage + 1)">
        Next
      </button>
    </div>
  </div>
</template>
