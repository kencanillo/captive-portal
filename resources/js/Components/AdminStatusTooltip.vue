<script setup>
import { computed } from 'vue';
import SvgIcon from '@/Components/SvgIcon.vue';

const props = defineProps({
  message: {
    type: String,
    default: '',
  },
  tone: {
    type: String,
    default: 'neutral',
  },
  label: {
    type: String,
    default: 'More details',
  },
});

const toneClass = computed(() => ({
  neutral: 'border-slate-200 bg-white text-slate-500',
  info: 'border-sky-200 bg-sky-50 text-sky-700',
  warning: 'border-amber-200 bg-amber-50 text-amber-700',
  danger: 'border-rose-200 bg-rose-50 text-rose-700',
}[props.tone] || 'border-slate-200 bg-white text-slate-500'));

const panelClass = computed(() => ({
  neutral: 'border-slate-200 bg-slate-950 text-white',
  info: 'border-sky-200 bg-sky-950 text-sky-50',
  warning: 'border-amber-200 bg-amber-950 text-amber-50',
  danger: 'border-rose-200 bg-rose-950 text-rose-50',
}[props.tone] || 'border-slate-200 bg-slate-950 text-white'));
</script>

<template>
  <div v-if="message" class="group relative inline-flex">
    <button
      type="button"
      class="inline-flex h-6 w-6 items-center justify-center rounded-full border text-[11px] transition focus:outline-none focus:ring-2 focus:ring-sky-300"
      :class="toneClass"
      :aria-label="label"
    >
      <SvgIcon name="info" class="h-3.5 w-3.5" />
    </button>

    <div
      class="pointer-events-none absolute bottom-[calc(100%+0.55rem)] left-1/2 z-20 hidden w-64 -translate-x-1/2 rounded-2xl border px-3 py-2 text-xs font-medium leading-5 shadow-[0_18px_40px_-24px_rgba(15,23,42,0.65)] group-hover:block group-focus-within:block"
      :class="panelClass"
    >
      {{ message }}
    </div>
  </div>
</template>
