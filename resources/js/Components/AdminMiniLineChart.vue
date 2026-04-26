<script setup>
import { computed } from 'vue';

const props = defineProps({
  title: {
    type: String,
    default: '',
  },
  subtitle: {
    type: String,
    default: '',
  },
  points: {
    type: Array,
    default: () => [],
  },
  mode: {
    type: String,
    default: 'line',
  },
});

const maxValue = computed(() => {
  const values = props.points.map((point) => Number(point.value || 0));

  return Math.max(...values, 1);
});

const linePoints = computed(() => {
  if (! props.points.length) {
    return '';
  }

  const width = 280;
  const height = 78;

  return props.points
    .map((point, index) => {
      const x = props.points.length === 1 ? width / 2 : (index / (props.points.length - 1)) * width;
      const y = height - ((Number(point.value || 0) / maxValue.value) * (height - 12)) - 6;

      return `${x},${y}`;
    })
    .join(' ');
});

const railSegments = computed(() => {
  const total = props.points.reduce((carry, point) => carry + Number(point.value || 0), 0);

  return props.points.map((point) => ({
    ...point,
    width: total > 0 ? `${(Number(point.value || 0) / total) * 100}%` : '0%',
  }));
});
</script>

<template>
  <article class="app-card p-5 sm:p-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p v-if="title" class="app-metric-label">{{ title }}</p>
        <p v-if="subtitle" class="mt-2 text-sm text-slate-500">{{ subtitle }}</p>
      </div>
      <slot name="meta" />
    </div>

    <div v-if="mode === 'rail'" class="mt-5">
      <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
        <div
          v-for="segment in railSegments"
          :key="segment.label"
          class="h-full transition-all"
          :style="{ width: segment.width, background: segment.color || '#94a3b8' }"
        />
      </div>

      <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="segment in railSegments" :key="`${segment.label}-legend`" class="rounded-2xl border border-slate-200/70 bg-white/70 px-3 py-3">
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full" :style="{ background: segment.color || '#94a3b8' }" />
            <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">{{ segment.label }}</p>
          </div>
          <p class="mt-2 text-lg font-semibold tracking-[-0.03em] text-slate-950">{{ segment.value }}</p>
        </div>
      </div>
    </div>

    <div v-else class="mt-5">
      <svg viewBox="0 0 280 84" class="h-28 w-full">
        <defs>
          <linearGradient id="admin-chart-line" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stop-color="#38bdf8" />
            <stop offset="100%" stop-color="#34d399" />
          </linearGradient>
        </defs>
        <path d="M0 78H280" stroke="rgba(148,163,184,0.28)" stroke-width="1" />
        <polyline
          v-if="linePoints"
          :points="linePoints"
          fill="none"
          stroke="url(#admin-chart-line)"
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="4"
        />
        <circle
          v-for="(point, index) in points"
          :key="`${point.label}-${index}`"
          :cx="points.length === 1 ? 140 : (index / (points.length - 1)) * 280"
          :cy="78 - ((Number(point.value || 0) / maxValue) * 66) - 6"
          r="4.5"
          :fill="point.color || '#38bdf8'"
          stroke="white"
          stroke-width="2"
        />
      </svg>

      <div class="mt-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="point in points" :key="point.label" class="rounded-2xl border border-slate-200/70 bg-white/70 px-3 py-3">
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full" :style="{ background: point.color || '#38bdf8' }" />
            <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">{{ point.label }}</p>
          </div>
          <p class="mt-2 text-lg font-semibold tracking-[-0.03em] text-slate-950">{{ point.value }}</p>
        </div>
      </div>
    </div>
  </article>
</template>
