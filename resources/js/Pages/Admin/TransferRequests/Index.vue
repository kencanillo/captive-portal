<script setup>
import { computed, ref } from 'vue';
import { router, Head, useForm } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import { formatNumber } from '@/utils/formatters';

const props = defineProps({
  transferRequests: {
    type: Array,
    required: true,
  },
});

const transferStatusFilter = ref('all');
const transferSearch = ref('');
const filteredTransferRequests = computed(() => {
  const query = transferSearch.value.trim().toLowerCase();

  return props.transferRequests.filter((item) => {
    const matchesStatus = transferStatusFilter.value === 'all'
      || item.status === transferStatusFilter.value
      || (transferStatusFilter.value === 'blocked' && ['denied', 'failed'].includes(item.status));

    if (!matchesStatus) return false;
    if (!query) return true;

    return [
      item.client?.name,
      item.client?.phone_number,
      item.requested_phone_number,
      item.requested_mac_address,
      item.from_device?.mac_address,
      item.active_session?.mac_address,
      item.reviewed_by?.name,
    ].filter(Boolean).join(' ').toLowerCase().includes(query);
  });
});

const summary = computed(() => ({
  total: filteredTransferRequests.value.length,
  pending: filteredTransferRequests.value.filter((item) => item.status === 'pending_review').length,
  executed: filteredTransferRequests.value.filter((item) => item.status === 'executed').length,
  blocked: filteredTransferRequests.value.filter((item) => ['denied', 'failed'].includes(item.status)).length,
}));

const selectedTransferRequest = ref(null);
const approveForm = useForm({
  review_notes: '',
  phone_number: '',
  pin: '',
  pin_confirmation: '',
});

const openApproveDialog = (item) => {
  selectedTransferRequest.value = item;
  approveForm.clearErrors();
  approveForm.reset();
  approveForm.phone_number = item.client?.phone_number || item.requested_phone_number || '';
};

const closeApproveDialog = () => {
  if (approveForm.processing) return;
  selectedTransferRequest.value = null;
  approveForm.clearErrors();
  approveForm.reset();
};

const submitApprove = () => {
  if (!selectedTransferRequest.value) return;

  approveForm.post(`/admin/transfer-requests/${selectedTransferRequest.value.id}/approve`, {
    preserveScroll: true,
    onSuccess: closeApproveDialog,
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
        <div class="mt-5 grid gap-3 md:grid-cols-[220px,1fr]">
          <select v-model="transferStatusFilter" class="app-field">
            <option value="all">All transfers</option>
            <option value="pending_review">Pending review</option>
            <option value="executed">Executed</option>
            <option value="approved">Approved</option>
            <option value="blocked">Denied / failed</option>
          </select>
          <input
            v-model="transferSearch"
            class="app-field"
            type="search"
            placeholder="Search client, phone, MAC, session, or reviewer"
          />
        </div>
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
            <tr v-for="item in filteredTransferRequests" :key="item.id">
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
                  <button v-if="item.status === 'pending_review'" class="app-button-primary px-4 py-2.5" @click="openApproveDialog(item)">Approve</button>
                  <button v-if="item.status === 'pending_review'" class="app-button-secondary px-4 py-2.5" @click="denyRequest(item.id)">Deny</button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredTransferRequests.length">
              <td colspan="8">
                <div class="app-empty">No transfer requests are waiting for review.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div
      v-if="selectedTransferRequest"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 py-6"
      role="dialog"
      aria-modal="true"
      aria-labelledby="transfer-approval-title"
    >
      <form class="w-full max-w-xl rounded-lg bg-white p-6 shadow-xl" @submit.prevent="submitApprove">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="app-kicker">Approval</p>
            <h2 id="transfer-approval-title" class="mt-2 text-xl font-semibold text-slate-950">Approve transfer request</h2>
          </div>
          <button type="button" class="app-button-secondary px-3 py-2" :disabled="approveForm.processing" @click="closeApproveDialog">Close</button>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current phone</p>
            <p class="mt-1 text-sm font-medium text-slate-950">{{ selectedTransferRequest.client?.phone_number || 'No phone' }}</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Requested phone</p>
            <p class="mt-1 text-sm font-medium text-slate-950">{{ selectedTransferRequest.requested_phone_number || 'No request phone' }}</p>
          </div>
        </div>

        <div class="mt-6 space-y-4">
          <div>
            <label class="app-label" for="approval_phone_number">Phone Number</label>
            <input
              id="approval_phone_number"
              v-model="approveForm.phone_number"
              type="tel"
              class="app-field mt-2"
              placeholder="09XXXXXXXXX"
              autocomplete="tel"
            />
            <InputError class="mt-2" :message="approveForm.errors.phone_number" />
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="app-label" for="approval_pin">New PIN</label>
              <input
                id="approval_pin"
                v-model="approveForm.pin"
                type="password"
                class="app-field mt-2"
                minlength="4"
                maxlength="20"
                autocomplete="new-password"
                required
              />
              <InputError class="mt-2" :message="approveForm.errors.pin" />
            </div>
            <div>
              <label class="app-label" for="approval_pin_confirmation">Confirm New PIN</label>
              <input
                id="approval_pin_confirmation"
                v-model="approveForm.pin_confirmation"
                type="password"
                class="app-field mt-2"
                minlength="4"
                maxlength="20"
                autocomplete="new-password"
                required
              />
              <InputError class="mt-2" :message="approveForm.errors.pin_confirmation" />
            </div>
          </div>

          <div>
            <label class="app-label" for="approval_review_notes">Review Notes</label>
            <textarea id="approval_review_notes" v-model="approveForm.review_notes" class="app-field mt-2 min-h-28" />
            <InputError class="mt-2" :message="approveForm.errors.review_notes" />
          </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-3">
          <button type="button" class="app-button-secondary px-4 py-2.5" :disabled="approveForm.processing" @click="closeApproveDialog">Cancel</button>
          <button type="submit" class="app-button-primary px-4 py-2.5" :disabled="approveForm.processing">
            {{ approveForm.processing ? 'Approving...' : 'Approve and Update Access' }}
          </button>
        </div>
      </form>
    </div>
  </MainLayout>
</template>
