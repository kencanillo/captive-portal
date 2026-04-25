<script setup>
import Modal from '@/Components/Modal.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  session: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close']);
</script>

<template>
  <Modal :show="show" max-width="xl" @close="emit('close')">
    <div class="rounded-[28px] bg-white p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="app-kicker">Controller Check</p>
          <h3 class="mt-2 text-2xl font-bold tracking-[-0.04em] text-slate-950">Session #{{ session?.id || '-' }}</h3>
          <p class="mt-2 text-xs text-slate-500">{{ session?.client?.name || 'Unknown client' }} • {{ session?.mac_address || 'No MAC' }}</p>
        </div>
        <button type="button" class="app-button-ghost px-3 py-2" @click="emit('close')">Close</button>
      </div>

      <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-[22px] border border-slate-200/80 bg-slate-50/70 px-4 py-4">
          <p class="app-metric-label">Latest Result</p>
          <p class="mt-3 text-sm font-semibold text-slate-950">{{ session?.last_reconcile_result || 'Not checked yet' }}</p>
          <p class="mt-2 text-xs text-slate-500">{{ session?.last_reconciled_at || 'No timestamp available' }}</p>
        </div>
        <div class="rounded-[22px] border border-slate-200/80 bg-slate-50/70 px-4 py-4">
          <p class="app-metric-label">Checks</p>
          <p class="mt-3 text-sm font-semibold text-slate-950">{{ session?.reconcile_attempt_count || 0 }}</p>
          <p class="mt-2 text-xs text-slate-500">Use this when support needs controller confirmation.</p>
        </div>
      </div>

      <div v-if="session?.controller_check_message" class="mt-4 rounded-[20px] border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ session.controller_check_message }}
      </div>

      <div class="mt-6 flex flex-wrap gap-3">
        <Link
          as="button"
          method="post"
          :href="`/admin/sessions/${session?.id}/reconcile-release`"
          class="app-button-primary"
          @click="emit('close')"
        >
          Run Controller Check
        </Link>
        <button type="button" class="app-button-secondary" @click="emit('close')">Dismiss</button>
      </div>
    </div>
  </Modal>
</template>
