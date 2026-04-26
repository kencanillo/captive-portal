<script setup>
import { computed } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

const props = defineProps({
  transferRequests: {
    type: Array,
    required: true,
  },
});

const summary = computed(() => ({
  total: props.transferRequests.length,
  pending: props.transferRequests.filter((item) => item.status === 'pending_review').length,
  executed: props.transferRequests.filter((item) => item.status === 'executed').length,
  blocked: props.transferRequests.filter((item) => ['denied', 'failed'].includes(item.status)).length,
}));

const approveRequest = (id) => {
  const reviewNotes = window.prompt('Approval notes (optional)') ?? '';

  router.post(`/admin/transfer-requests/${id}/approve`, {
    review_notes: reviewNotes,
  }, {
    preserveScroll: true,
  });
};

const denyRequest = (id) => {
  const denialReason = window.prompt('Reason for denial');

  if (denialReason === null || denialReason.trim() === '') {
    return;
  }

  const reviewNotes = window.prompt('Additional review notes (optional)') ?? '';

  router.post(`/admin/transfer-requests/${id}/deny`, {
    denial_reason: denialReason,
    review_notes: reviewNotes,
  }, {
    preserveScroll: true,
  });
};
</script>

<template>
  <Head title="Transfer Requests" />

  <MainLayout title="Device Transfer Requests">
    <section>
      <p class="app-kicker">Admin-Assisted Device Replacement</p>
      <h1 class="mt-3 app-title">Device transfer review queue</h1>
      <p class="mt-4 app-subtitle">
        This queue is intentionally strict. Transfers only move a live entitlement from one device to another after admin review and Omada handoff. No silent rebinding.
      </p>
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-metric-card">
        <p class="app-metric-label">Requests</p>
        <p class="app-metric-value">{{ formatNumber(summary.total) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Pending Review</p>
        <p class="app-metric-value">{{ formatNumber(summary.pending) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Executed</p>
        <p class="app-metric-value">{{ formatNumber(summary.executed) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Denied / Failed</p>
        <p class="app-metric-value">{{ formatNumber(summary.blocked) }}</p>
      </article>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Transfer Audit</p>
        <h2 class="mt-2 app-section-title">Pending and historical transfer requests</h2>
      </div>

      <div class="app-table-wrap">
        <table class="app-table">
          <thead>
            <tr>
              <th>Client</th>
              <th>Active Session</th>
              <th>Device Move</th>
              <th>Status</th>
              <th>Requested</th>
              <th>Reviewed By</th>
              <th>Notes</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in transferRequests" :key="item.id">
              <td>
                <p class="font-semibold text-slate-950">{{ item.client?.name || 'Unknown client' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.requested_phone_number || item.client?.phone_number || 'No phone snapshot' }}</p>
              </td>
              <td>
                <p class="font-medium text-slate-950">#{{ item.active_session?.id || 'Missing' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.active_session?.end_time || 'No active end time' }}</p>
              </td>
              <td>
                <p class="font-medium text-slate-950">{{ item.from_device?.mac_address || item.active_session?.mac_address || 'Unknown old MAC' }}</p>
                <p class="mt-1 text-xs text-slate-500">to {{ item.requested_mac_address }}</p>
              </td>
              <td>
                <span
                  class="app-badge"
                  :class="{
                    'bg-amber-100 text-amber-700': item.status === 'pending_review',
                    'bg-emerald-100 text-emerald-700': item.status === 'executed',
                    'bg-sky-100 text-sky-700': item.status === 'approved',
                    'bg-rose-100 text-rose-700': ['denied', 'failed'].includes(item.status),
                  }"
                >
                  {{ item.status }}
                </span>
                <p v-if="item.failure_reason" class="mt-2 text-xs text-rose-600">{{ item.failure_reason }}</p>
              </td>
              <td>
                <p class="text-sm text-slate-600">{{ item.requested_at || '-' }}</p>
                <p v-if="item.metadata?.request_ip" class="mt-1 text-xs text-slate-500">IP {{ item.metadata.request_ip }}</p>
              </td>
              <td>
                <p class="text-sm text-slate-600">{{ item.reviewed_by?.name || '-' }}</p>
                <p v-if="item.reviewed_at" class="mt-1 text-xs text-slate-500">{{ item.reviewed_at }}</p>
              </td>
              <td>
                <p v-if="item.denial_reason" class="text-sm text-rose-600">{{ item.denial_reason }}</p>
                <p v-else-if="item.review_notes" class="text-sm text-slate-600">{{ item.review_notes }}</p>
                <p v-else class="text-sm text-slate-400">No notes</p>
              </td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <button v-if="item.status === 'pending_review'" class="app-button-primary px-4 py-2.5" @click="approveRequest(item.id)">Approve</button>
                  <button v-if="item.status === 'pending_review'" class="app-button-secondary px-4 py-2.5" @click="denyRequest(item.id)">Deny</button>
                </div>
              </td>
            </tr>
            <tr v-if="!transferRequests.length">
              <td colspan="8">
                <div class="app-empty">No transfer requests are waiting for review.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </MainLayout>
</template>
