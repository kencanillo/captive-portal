<script setup>
import { computed } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

const props = defineProps({
  payoutRequests: Array,
});

const summary = computed(() => ({
  total: props.payoutRequests.length,
  pending: props.payoutRequests.filter((item) => item.status === 'pending').length,
  processing: props.payoutRequests.filter((item) => item.status === 'processing').length,
  paid: props.payoutRequests.filter((item) => item.status === 'paid').length,
}));

const submitAction = (id, action, reviewNotes = null) => {
  const payload = {};

  if (reviewNotes !== null) {
    payload.review_notes = reviewNotes;
  }

  router.post(`/admin/payout-requests/${id}/${action}`, payload, {
    preserveScroll: true,
  });
};

const rejectRequest = (id) => {
  const reviewNotes = window.prompt('Reason for rejection');

  if (reviewNotes === null) {
    return;
  }

  submitAction(id, 'reject', reviewNotes);
};

const failRequest = (id) => {
  const reviewNotes = window.prompt('Failure reason');

  if (reviewNotes === null || reviewNotes.trim() === '') {
    return;
  }

  submitAction(id, 'failed', reviewNotes);
};
</script>

<template>
  <Head title="Payout Requests" />

  <MainLayout title="Payout Requests">
    <section>
      <p class="app-kicker">Manual Settlement Workflow</p>
      <h1 class="mt-3 app-title">Operator payout review</h1>
      <p class="mt-4 app-subtitle">
        The app approves and records payout lifecycle state first. Actual money movement is optional and provider-dependent. That separation is deliberate and correct.
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
        <p class="app-metric-label">Processing</p>
        <p class="app-metric-value">{{ formatNumber(summary.processing) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Paid</p>
        <p class="app-metric-value">{{ formatNumber(summary.paid) }}</p>
      </article>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Payout Ledger</p>
        <h2 class="mt-2 app-section-title">Operator payout requests</h2>
      </div>

      <div class="app-table-wrap">
        <table class="app-table">
          <thead>
            <tr>
              <th>Operator</th>
              <th>Amount</th>
              <th>Destination</th>
              <th>Status</th>
              <th>Handling</th>
              <th>Reference</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in payoutRequests" :key="item.id">
              <td>
                <p class="font-semibold text-slate-950">{{ item.operator_name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.operator_email }}</p>
              </td>
              <td class="font-semibold text-slate-950">{{ formatCurrency(item.amount) }}</td>
              <td>
                <p class="font-medium text-slate-950">{{ item.destination_type }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.destination_account_name }} • {{ item.destination_account_reference }}</p>
              </td>
              <td>
                <span
                  class="app-badge"
                  :class="{
                    'bg-amber-100 text-amber-700': item.status === 'pending',
                    'bg-sky-100 text-sky-700': item.status === 'processing',
                    'bg-emerald-100 text-emerald-700': item.status === 'paid',
                    'bg-slate-100 text-slate-600': item.status === 'approved',
                    'bg-rose-100 text-rose-700': ['rejected', 'failed'].includes(item.status),
                  }"
                >
                  {{ item.status }}
                </span>
                <p class="mt-2 text-xs text-slate-500">{{ item.provider_status || 'No provider status' }}</p>
              </td>
              <td>
                <p class="font-medium text-slate-950">{{ item.processing_mode || 'Not assigned' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.provider || 'manual' }}</p>
              </td>
              <td class="text-xs text-slate-500">{{ item.provider_transfer_reference || 'None' }}</td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <button v-if="item.status === 'pending'" class="app-button-primary px-4 py-2.5" @click="submitAction(item.id, 'approve')">Approve</button>
                  <button v-if="item.status === 'pending'" class="app-button-secondary px-4 py-2.5" @click="rejectRequest(item.id)">Reject</button>
                  <button v-if="['approved', 'processing'].includes(item.status)" class="app-button-secondary px-4 py-2.5" @click="submitAction(item.id, 'processing')">Processing</button>
                  <button v-if="['approved', 'processing'].includes(item.status)" class="app-button-primary px-4 py-2.5" @click="submitAction(item.id, 'paid')">Paid</button>
                  <button v-if="['approved', 'processing'].includes(item.status)" class="inline-flex items-center justify-center rounded-full bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-500" @click="failRequest(item.id)">Failed</button>
                </div>
              </td>
            </tr>
            <tr v-if="!payoutRequests.length">
              <td colspan="7">
                <div class="app-empty">No payout requests exist yet.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </MainLayout>
</template>
