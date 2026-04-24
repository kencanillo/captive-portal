<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({
  inheritAttrs: false,
});

const props = defineProps({
  name: {
    type: String,
    required: true,
  },
  title: {
    type: String,
    default: '',
  },
});

const attrs = useAttrs();

const icons = {
  dashboard: {
    nodes: [
      { type: 'rect', attrs: { x: '3', y: '3', width: '8', height: '8', rx: '1.5' } },
      { type: 'rect', attrs: { x: '13', y: '3', width: '8', height: '5', rx: '1.5' } },
      { type: 'rect', attrs: { x: '13', y: '10', width: '8', height: '11', rx: '1.5' } },
      { type: 'rect', attrs: { x: '3', y: '13', width: '8', height: '8', rx: '1.5' } },
    ],
  },
  settings_input_component: {
    nodes: [
      { type: 'rect', attrs: { x: '3', y: '5', width: '18', height: '14', rx: '2' } },
      { type: 'path', attrs: { d: 'M8 9h8' } },
      { type: 'path', attrs: { d: 'M8 12h8' } },
      { type: 'path', attrs: { d: 'M8 15h5' } },
      { type: 'path', attrs: { d: 'M12 19v2' } },
    ],
  },
  router: {
    nodes: [
      { type: 'rect', attrs: { x: '4', y: '8', width: '16', height: '10', rx: '2' } },
      { type: 'path', attrs: { d: 'M12 4v4' } },
      { type: 'path', attrs: { d: 'M8.5 6.5 12 3l3.5 3.5' } },
      { type: 'circle', attrs: { cx: '9', cy: '13', r: '0.75', fill: 'currentColor', stroke: 'none' } },
      { type: 'circle', attrs: { cx: '15', cy: '13', r: '0.75', fill: 'currentColor', stroke: 'none' } },
      { type: 'path', attrs: { d: 'M8 17h8' } },
    ],
  },
  fact_check: {
    nodes: [
      { type: 'path', attrs: { d: 'M9 3h6l4 4v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h2Z' } },
      { type: 'path', attrs: { d: 'M9 12.5 11 14.5l4-4' } },
      { type: 'path', attrs: { d: 'M9 8h4' } },
    ],
  },
  sell: {
    nodes: [
      { type: 'path', attrs: { d: 'M4 10.5V5a2 2 0 0 1 2-2h5.5a2 2 0 0 1 1.4.6l6.5 6.5a2 2 0 0 1 0 2.8l-6.1 6.1a2 2 0 0 1-2.8 0L4.6 11.9A2 2 0 0 1 4 10.5Z' } },
      { type: 'circle', attrs: { cx: '8.5', cy: '7.5', r: '1', fill: 'currentColor', stroke: 'none' } },
    ],
  },
  wifi_find: {
    nodes: [
      { type: 'path', attrs: { d: 'M4.5 9.5a11 11 0 0 1 15 0' } },
      { type: 'path', attrs: { d: 'M7.5 12.5a7 7 0 0 1 8 0' } },
      { type: 'path', attrs: { d: 'M10.5 15.5a3 3 0 0 1 2 0' } },
      { type: 'circle', attrs: { cx: '12', cy: '18', r: '1', fill: 'currentColor', stroke: 'none' } },
      { type: 'circle', attrs: { cx: '18.25', cy: '18.25', r: '2.75' } },
      { type: 'path', attrs: { d: 'm20.5 20.5 1.75 1.75' } },
    ],
  },
  payments: {
    nodes: [
      { type: 'rect', attrs: { x: '3', y: '6', width: '18', height: '12', rx: '2' } },
      { type: 'path', attrs: { d: 'M3 10h18' } },
      { type: 'path', attrs: { d: 'M7 15h3' } },
      { type: 'path', attrs: { d: 'M14 15h3' } },
    ],
  },
  swap_horiz: {
    nodes: [
      { type: 'path', attrs: { d: 'M4 8h13' } },
      { type: 'path', attrs: { d: 'm13 4 4 4-4 4' } },
      { type: 'path', attrs: { d: 'M20 16H7' } },
      { type: 'path', attrs: { d: 'm11 12-4 4 4 4' } },
    ],
  },
  groups: {
    nodes: [
      { type: 'circle', attrs: { cx: '9', cy: '9', r: '3' } },
      { type: 'circle', attrs: { cx: '17', cy: '10', r: '2.5' } },
      { type: 'path', attrs: { d: 'M3.5 19a5.5 5.5 0 0 1 11 0' } },
      { type: 'path', attrs: { d: 'M14.5 19a4 4 0 0 1 6 0' } },
    ],
  },
  account_balance_wallet: {
    nodes: [
      { type: 'path', attrs: { d: 'M4 7a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v2H6a2 2 0 0 0-2 2V7Z' } },
      { type: 'rect', attrs: { x: '4', y: '9', width: '16', height: '10', rx: '2' } },
      { type: 'circle', attrs: { cx: '16', cy: '14', r: '1', fill: 'currentColor', stroke: 'none' } },
    ],
  },
  person: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '8', r: '3.25' } },
      { type: 'path', attrs: { d: 'M5 20a7 7 0 0 1 14 0' } },
    ],
  },
  settings: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '3.25' } },
      { type: 'path', attrs: { d: 'M12 2.75v2.5' } },
      { type: 'path', attrs: { d: 'M12 18.75v2.5' } },
      { type: 'path', attrs: { d: 'm4.93 4.93 1.77 1.77' } },
      { type: 'path', attrs: { d: 'm17.3 17.3 1.77 1.77' } },
      { type: 'path', attrs: { d: 'M2.75 12h2.5' } },
      { type: 'path', attrs: { d: 'M18.75 12h2.5' } },
      { type: 'path', attrs: { d: 'm4.93 19.07 1.77-1.77' } },
      { type: 'path', attrs: { d: 'm17.3 6.7 1.77-1.77' } },
    ],
  },
  menu: {
    nodes: [
      { type: 'path', attrs: { d: 'M4 7h16' } },
      { type: 'path', attrs: { d: 'M4 12h16' } },
      { type: 'path', attrs: { d: 'M4 17h16' } },
    ],
  },
  search: {
    nodes: [
      { type: 'circle', attrs: { cx: '11', cy: '11', r: '5.5' } },
      { type: 'path', attrs: { d: 'm16 16 4.5 4.5' } },
    ],
  },
  expand_more: {
    nodes: [{ type: 'path', attrs: { d: 'm6 9 6 6 6-6' } }],
  },
  arrow_forward: {
    nodes: [
      { type: 'path', attrs: { d: 'M5 12h14' } },
      { type: 'path', attrs: { d: 'm13 6 6 6-6 6' } },
    ],
  },
  logout: {
    nodes: [
      { type: 'path', attrs: { d: 'M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4' } },
      { type: 'path', attrs: { d: 'M13 8l4 4-4 4' } },
      { type: 'path', attrs: { d: 'M9 12h8' } },
    ],
  },
  check_circle: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '9' } },
      { type: 'path', attrs: { d: 'm8.5 12.5 2.5 2.5 4.5-5' } },
    ],
  },
  error: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '9' } },
      { type: 'path', attrs: { d: 'M12 7.5v6' } },
      { type: 'circle', attrs: { cx: '12', cy: '16.5', r: '1', fill: 'currentColor', stroke: 'none' } },
    ],
  },
  info: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '9' } },
      { type: 'circle', attrs: { cx: '12', cy: '8', r: '1', fill: 'currentColor', stroke: 'none' } },
      { type: 'path', attrs: { d: 'M12 11v5' } },
    ],
  },
  close: {
    nodes: [
      { type: 'path', attrs: { d: 'm6 6 12 12' } },
      { type: 'path', attrs: { d: 'm18 6-12 12' } },
    ],
  },
  pending: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '9' } },
      { type: 'path', attrs: { d: 'M12 7v5l3 2' } },
    ],
  },
  warning: {
    nodes: [
      { type: 'path', attrs: { d: 'M12 4 21 20H3L12 4Z' } },
      { type: 'path', attrs: { d: 'M12 9v4.5' } },
      { type: 'circle', attrs: { cx: '12', cy: '17', r: '1', fill: 'currentColor', stroke: 'none' } },
    ],
  },
  verified: {
    nodes: [
      { type: 'path', attrs: { d: 'M12 3 19 6v5c0 4.25-2.5 7-7 10-4.5-3-7-5.75-7-10V6l7-3Z' } },
      { type: 'path', attrs: { d: 'm9 12 2 2 4-4' } },
    ],
  },
  block: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '9' } },
      { type: 'path', attrs: { d: 'm7 17 10-10' } },
    ],
  },
  rule: {
    nodes: [
      { type: 'path', attrs: { d: 'M12 4v16' } },
      { type: 'path', attrs: { d: 'M7 7h10' } },
      { type: 'path', attrs: { d: 'm7 7-3 4h6l-3-4Z' } },
      { type: 'path', attrs: { d: 'm17 7-3 4h6l-3-4Z' } },
      { type: 'path', attrs: { d: 'M8 20h8' } },
    ],
  },
  sync: {
    nodes: [
      { type: 'path', attrs: { d: 'M4 12a8 8 0 0 1 13.65-5.65L20 8.75' } },
      { type: 'path', attrs: { d: 'M20 4.5v4.25h-4.25' } },
      { type: 'path', attrs: { d: 'M20 12a8 8 0 0 1-13.65 5.65L4 15.25' } },
      { type: 'path', attrs: { d: 'M4 19.5v-4.25h4.25' } },
    ],
  },
  wifi_tethering: {
    nodes: [
      { type: 'path', attrs: { d: 'M6 9.5a9.5 9.5 0 0 1 12 0' } },
      { type: 'path', attrs: { d: 'M8.5 12.5a6 6 0 0 1 7 0' } },
      { type: 'path', attrs: { d: 'M11 15.5a2.5 2.5 0 0 1 2 0' } },
      { type: 'circle', attrs: { cx: '12', cy: '18', r: '1', fill: 'currentColor', stroke: 'none' } },
      { type: 'path', attrs: { d: 'M5 19h2' } },
      { type: 'path', attrs: { d: 'M17 19h2' } },
    ],
  },
  monitoring: {
    nodes: [
      { type: 'path', attrs: { d: 'M4 18V6' } },
      { type: 'path', attrs: { d: 'M4 18h16' } },
      { type: 'path', attrs: { d: 'm6.5 14 3-4 2.5 3 2.5-6 3 4' } },
    ],
  },
  add_circle: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '9' } },
      { type: 'path', attrs: { d: 'M12 8v8' } },
      { type: 'path', attrs: { d: 'M8 12h8' } },
    ],
  },
  edit: {
    nodes: [
      { type: 'path', attrs: { d: 'M4 20h4l10-10-4-4L4 16v4Z' } },
      { type: 'path', attrs: { d: 'm12 6 4 4' } },
    ],
  },
  delete: {
    nodes: [
      { type: 'path', attrs: { d: 'M5 7h14' } },
      { type: 'path', attrs: { d: 'M9 7V5h6v2' } },
      { type: 'path', attrs: { d: 'M7 7l1 12h8l1-12' } },
      { type: 'path', attrs: { d: 'M10 11v5' } },
      { type: 'path', attrs: { d: 'M14 11v5' } },
    ],
  },
  hub: {
    nodes: [
      { type: 'circle', attrs: { cx: '12', cy: '12', r: '2' } },
      { type: 'circle', attrs: { cx: '6', cy: '6', r: '1.5' } },
      { type: 'circle', attrs: { cx: '18', cy: '6', r: '1.5' } },
      { type: 'circle', attrs: { cx: '6', cy: '18', r: '1.5' } },
      { type: 'circle', attrs: { cx: '18', cy: '18', r: '1.5' } },
      { type: 'path', attrs: { d: 'M7.25 7.25 10.5 10.5' } },
      { type: 'path', attrs: { d: 'm16.75 7.25-3.25 3.25' } },
      { type: 'path', attrs: { d: 'm7.25 16.75 3.25-3.25' } },
      { type: 'path', attrs: { d: 'm16.75 16.75-3.25-3.25' } },
    ],
  },
  alternate_email: {
    nodes: [
      { type: 'path', attrs: { d: 'M16.5 17.5A5.5 5.5 0 1 1 17 8a4 4 0 1 1 0 8h-1.25a1.75 1.75 0 0 1-1.75-1.75V9.5a2.5 2.5 0 1 0-2.5 2.5h3' } },
    ],
  },
  lock: {
    nodes: [
      { type: 'rect', attrs: { x: '5', y: '11', width: '14', height: '10', rx: '2' } },
      { type: 'path', attrs: { d: 'M8 11V8a4 4 0 0 1 8 0v3' } },
    ],
  },
  security: {
    nodes: [
      { type: 'path', attrs: { d: 'M12 3 19 6v5c0 4.25-2.5 7-7 10-4.5-3-7-5.75-7-10V6l7-3Z' } },
    ],
  },
  send: {
    nodes: [
      { type: 'path', attrs: { d: 'M3 11.5 20.5 4l-4 16-4.75-5-4.75-3.5Z' } },
      { type: 'path', attrs: { d: 'M11.75 15 20.5 4' } },
    ],
  },
  business: {
    nodes: [
      { type: 'rect', attrs: { x: '5', y: '3', width: '14', height: '18', rx: '2' } },
      { type: 'path', attrs: { d: 'M9 7h1' } },
      { type: 'path', attrs: { d: 'M14 7h1' } },
      { type: 'path', attrs: { d: 'M9 11h1' } },
      { type: 'path', attrs: { d: 'M14 11h1' } },
      { type: 'path', attrs: { d: 'M9 15h1' } },
      { type: 'path', attrs: { d: 'M14 15h1' } },
      { type: 'path', attrs: { d: 'M11 21v-4h2v4' } },
    ],
  },
  lock_person: {
    nodes: [
      { type: 'circle', attrs: { cx: '8.5', cy: '8.5', r: '2.5' } },
      { type: 'path', attrs: { d: 'M4.5 18a4.5 4.5 0 0 1 8 0' } },
      { type: 'rect', attrs: { x: '13', y: '11', width: '7', height: '9', rx: '1.5' } },
      { type: 'path', attrs: { d: 'M14.75 11V9.75a2.75 2.75 0 1 1 5.5 0V11' } },
    ],
  },
  lan: {
    nodes: [
      { type: 'rect', attrs: { x: '10', y: '3', width: '4', height: '4', rx: '1' } },
      { type: 'rect', attrs: { x: '3', y: '17', width: '4', height: '4', rx: '1' } },
      { type: 'rect', attrs: { x: '17', y: '17', width: '4', height: '4', rx: '1' } },
      { type: 'path', attrs: { d: 'M12 7v5' } },
      { type: 'path', attrs: { d: 'M6 17v-2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2' } },
    ],
  },
  domain: {
    nodes: [
      { type: 'rect', attrs: { x: '4', y: '5', width: '16', height: '14', rx: '2' } },
      { type: 'path', attrs: { d: 'M8 9h1' } },
      { type: 'path', attrs: { d: 'M12 9h1' } },
      { type: 'path', attrs: { d: 'M16 9h1' } },
      { type: 'path', attrs: { d: 'M8 13h1' } },
      { type: 'path', attrs: { d: 'M12 13h1' } },
      { type: 'path', attrs: { d: 'M16 13h1' } },
      { type: 'path', attrs: { d: 'M10 19v-3h4v3' } },
    ],
  },
};

const fallbackIcon = icons.info;

const icon = computed(() => icons[props.name] ?? fallbackIcon);
const hasTitle = computed(() => Boolean(props.title));
</script>

<template>
  <svg
    v-bind="attrs"
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    :role="hasTitle ? 'img' : 'presentation'"
    :aria-hidden="hasTitle ? undefined : 'true'"
  >
    <title v-if="hasTitle">{{ title }}</title>
    <component
      :is="node.type"
      v-for="(node, index) in icon.nodes"
      :key="`${props.name}-${index}`"
      v-bind="node.attrs"
    />
  </svg>
</template>
