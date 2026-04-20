<template>
  <div v-if="links.length > 3" class="flex flex-wrap justify-center">
    <div class="flex rounded-md shadow-sm" role="navigation">
      <template v-for="(link, key) in links" :key="key">
        <div
          v-if="link.url === null"
          class="px-4 py-2 text-sm text-gray-500 border border-gray-300 bg-gray-100"
          v-html="link.label"
        />
        <Link
          v-else
          :href="link.url"
          class="px-4 py-2 text-sm border"
          :class="{
            'bg-indigo-600 text-white border-indigo-600': link.active,
            'text-gray-700 border-gray-300 hover:bg-gray-50': !link.active,
          }"
          v-html="link.label"
          preserve-state
        />
      </template>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
  links: {
    type: Array,
    required: true,
  },
});
</script>
