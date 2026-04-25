<script setup>
import Modal from '@/Components/Modal.vue';

defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  client: {
    type: Object,
    default: null,
  },
  history: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close']);
</script>

<template>
  <Modal :show="show" max-width="2xl" @close="emit('close')">
    <div class="rounded-[28px] bg-white p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="app-kicker">Client History</p>
          <h3 class="mt-2 text-2xl font-bold tracking-[-0.04em] text-slate-950">{{ client?.name || 'Unknown client' }}</h3>
          <p class="mt-2 text-xs text-slate-500">{{ client?.phone_number || 'No phone' }} | {{ client?.mac_address || 'No MAC' }}</p>
        </div>
        <button type="button" class="app-button-ghost px-3 py-2" @click="emit('close')">Close</button>
      </div>

      <div class="mt-6 space-y-3">
        <article
          v-for="entry in history"
          :key="entry.id"
          class="rounded-[22px] border border-slate-200/80 bg-slate-50/70 px-4 py-4"
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p class="text-sm font-semibold text-slate-950">{{ entry.plan_name || 'No plan' }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ entry.site_name || 'No site' }} • {{ entry.created_at || '-' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <span class="app-badge app-badge-compact" :class="entry.payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : entry.payment_status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'">
                {{ entry.payment_status }}
              </span>
              <span class="app-badge app-badge-compact" :class="entry.release_status === 'succeeded' ? 'bg-emerald-100 text-emerald-700' : entry.release_status === 'pending' || entry.release_status === 'in_progress' ? 'bg-amber-100 text-amber-700' : entry.release_status === 'manual_required' || entry.release_status === 'uncertain' ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-600'">
                {{ entry.release_status || 'not queued' }}
              </span>
            </div>
          </div>

          <div class="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3">
            <p>Session #{{ entry.id }}</p>
            <p>{{ entry.remaining_time || '-' }}</p>
            <p>{{ entry.end_time || '-' }}</p>
          </div>

          <div v-if="entry.payments?.length" class="mt-3 space-y-2 border-t border-slate-200/70 pt-3">
            <div v-for="payment in entry.payments" :key="payment.id" class="flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
              <span>{{ payment.reference_id || 'No reference' }}</span>
              <span>{{ payment.status }}</span>
              <span>{{ payment.created_at }}</span>
            </div>
          </div>
        </article>

        <div v-if="!history.length" class="app-empty">No recent history is available for this client.</div>
      </div>
    </div>
  </Modal>
</template>
