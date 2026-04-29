<script setup>
import { computed, ref } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import AdminPagination from '@/Components/AdminPagination.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

const props = defineProps({
  payoutRequests: Array,
  defaultExecutionProvider: String,
  providerOps: Object,
});

const payoutStatusFilter = ref('all');
const payoutSearch = ref('');
const filteredPayoutRequests = computed(() => {
  const query = payoutSearch.value.trim().toLowerCase();

  return props.payoutRequests.filter((item) => {
    const matchesStatus = payoutStatusFilter.value === 'all'
      || item.status === payoutStatusFilter.value
      || (payoutStatusFilter.value === 'needs_review' && ['pending_review', 'review_required'].includes(item.status))
      || (payoutStatusFilter.value === 'execution_exception' && [
        'execution_completed_but_blocked_from_settlement',
        'execution_failed_retryable',
        'execution_manual_followup_required',
        'execution_terminal_failed',
        'execution_provider_returned_under_review',
        'execution_provider_reversed_under_review',
        'execution_provider_rejected_under_review',
        'execution_provider_on_hold_under_review',
      ].includes(item.post_execution_state));

    if (!matchesStatus) return false;
    if (!query) return true;

    return [
      item.operator_name,
      item.operator_email,
      item.destination_type,
      item.destination_account_name,
      item.destination_account_reference,
      item.provider_status,
      item.settlement_state,
      item.reference_id,
      item.latest_execution_attempt?.execution_reference,
    ].filter(Boolean).join(' ').toLowerCase().includes(query);
  });
});

const summary = computed(() => ({
  total: filteredPayoutRequests.value.length,
  pending: filteredPayoutRequests.value.filter((item) => item.status === 'pending_review').length,
  approved: filteredPayoutRequests.value.filter((item) => item.status === 'approved').length,
  reviewRequired: filteredPayoutRequests.value.filter((item) => item.status === 'review_required').length,
  settled: filteredPayoutRequests.value.filter((item) => item.status === 'settled').length,
  cancelled: filteredPayoutRequests.value.filter((item) => item.status === 'cancelled').length,
  rejected: filteredPayoutRequests.value.filter((item) => item.status === 'rejected').length,
  completedAwaitingSettlement: filteredPayoutRequests.value.filter((item) => item.post_execution_state === 'execution_completed_awaiting_settlement').length,
  postExecutionExceptions: filteredPayoutRequests.value.filter((item) => [
    'execution_completed_but_blocked_from_settlement',
    'execution_failed_retryable',
    'execution_manual_followup_required',
    'execution_terminal_failed',
    'execution_provider_returned_under_review',
    'execution_provider_reversed_under_review',
    'execution_provider_rejected_under_review',
    'execution_provider_on_hold_under_review',
  ].includes(item.post_execution_state)).length,
}));

const currentPage = ref(1);
const perPage = 20;

const paginatedRows = computed(() => {
  const start = (currentPage.value - 1) * perPage;

  return filteredPayoutRequests.value.slice(start, start + perPage);
});

const lastPage = computed(() => Math.max(1, Math.ceil(filteredPayoutRequests.value.length / perPage)));
const from = computed(() => filteredPayoutRequests.value.length ? ((currentPage.value - 1) * perPage) + 1 : 0);
const to = computed(() => Math.min(currentPage.value * perPage, filteredPayoutRequests.value.length));

const summaryItems = computed(() => ([
  { label: 'Requests', value: summary.value.total, tone: 'slate' },
  { label: 'Pending', value: summary.value.pending, tone: 'amber' },
  { label: 'Approved', value: summary.value.approved, tone: 'sky' },
  { label: 'Review', value: summary.value.reviewRequired, tone: 'orange' },
  { label: 'Settled', value: summary.value.settled, tone: 'emerald' },
  { label: 'Rejected', value: summary.value.rejected, tone: 'rose' },
  { label: 'Cancelled', value: summary.value.cancelled, tone: 'zinc' },
  { label: 'Awaiting Settlement', value: summary.value.completedAwaitingSettlement, tone: 'violet' },
  { label: 'Execution Exceptions', value: summary.value.postExecutionExceptions, tone: 'rose' },
]));

const summaryTone = (tone) => ({
  slate: 'bg-slate-100 text-slate-700',
  amber: 'bg-amber-100 text-amber-700',
  sky: 'bg-sky-100 text-sky-700',
  orange: 'bg-orange-100 text-orange-700',
  emerald: 'bg-emerald-100 text-emerald-700',
  rose: 'bg-rose-100 text-rose-700',
  zinc: 'bg-zinc-100 text-zinc-700',
  violet: 'bg-violet-100 text-violet-700',
}[tone] || 'bg-slate-100 text-slate-700');

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

const cancelRequest = (id) => {
  const reviewNotes = window.prompt('Cancellation reason');

  if (reviewNotes === null || reviewNotes.trim() === '') {
    return;
  }

  submitAction(id, 'cancel', reviewNotes);
};

const returnToReview = (id) => {
  const reviewNotes = window.prompt('Reason for returning this request to review');

  if (reviewNotes === null || reviewNotes.trim() === '') {
    return;
  }

  submitAction(id, 'return-to-review', reviewNotes);
};

const settleRequest = (item) => {
  const amount = window.prompt('Settlement amount', item.amount);

  if (amount === null || amount.trim() === '') {
    return;
  }

  const settlementReference = window.prompt('Settlement reference (leave blank if you will enter notes)');

  if (settlementReference === null) {
    return;
  }

  const notes = settlementReference.trim() === ''
    ? window.prompt('Settlement notes')
    : window.prompt('Optional settlement notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-requests/${item.id}/settle`, {
    amount,
    settlement_reference: settlementReference,
    notes,
  }, {
    preserveScroll: true,
  });
};

const reverseSettlement = (item) => {
  const reason = window.prompt('Settlement reversal reason');

  if (reason === null || reason.trim() === '') {
    return;
  }

  const notes = window.prompt('Optional settlement reversal notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-requests/${item.id}/reverse-settlement`, {
    reason,
    notes,
  }, {
    preserveScroll: true,
  });
};

const cancelAndRelease = (item) => {
  const reason = window.prompt('Cancel and release reason');

  if (reason === null || reason.trim() === '') {
    return;
  }

  const notes = window.prompt('Optional resolution notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-requests/${item.id}/cancel-and-release`, {
    reason,
    notes,
  }, {
    preserveScroll: true,
  });
};

const resolveReturnToReview = (item) => {
  const reason = window.prompt('Reason for returning this reversed request to review');

  if (reason === null || reason.trim() === '') {
    return;
  }

  const notes = window.prompt('Optional resolution notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-requests/${item.id}/resolve-return-to-review`, {
    reason,
    notes,
  }, {
    preserveScroll: true,
  });
};

const confirmSettlementHandoff = (item) => {
  const reason = window.prompt('Settlement handoff reason');

  if (reason === null || reason.trim() === '') {
    return;
  }

  const notes = window.prompt('Optional handoff notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-requests/${item.id}/confirm-settlement-handoff`, {
    reason,
    notes,
  }, {
    preserveScroll: true,
  });
};

const triggerExecution = (item) => {
  router.post(`/admin/payout-requests/${item.id}/trigger-execution`, {
    provider: props.defaultExecutionProvider || 'manual',
  }, {
    preserveScroll: true,
  });
};

const reconcileExecution = (attempt) => {
  router.post(`/admin/payout-execution-attempts/${attempt.id}/reconcile`, {}, {
    preserveScroll: true,
  });
};

const retryExecution = (attempt) => {
  const reason = window.prompt('Retry reason');

  if (reason === null || reason.trim() === '') {
    return;
  }

  const notes = window.prompt('Optional retry notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-execution-attempts/${attempt.id}/retry`, {
    reason,
    notes,
    provider: props.defaultExecutionProvider || 'manual',
  }, {
    preserveScroll: true,
  });
};

const markExecutionCompleted = (attempt) => {
  const reason = window.prompt('Completion reason');

  if (reason === null || reason.trim() === '') {
    return;
  }

  const notes = window.prompt('Optional completion notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-execution-attempts/${attempt.id}/mark-completed`, {
    reason,
    notes,
  }, {
    preserveScroll: true,
  });
};

const markExecutionTerminalFailed = (attempt) => {
  const reason = window.prompt('Terminal failure reason');

  if (reason === null || reason.trim() === '') {
    return;
  }

  const notes = window.prompt('Optional failure notes');

  if (notes === null) {
    return;
  }

  router.post(`/admin/payout-execution-attempts/${attempt.id}/mark-terminal-failed`, {
    reason,
    notes,
  }, {
    preserveScroll: true,
  });
};

const goToPage = (page) => {
  currentPage.value = page;
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

    <section class="mt-8 app-rail-card">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <p class="app-metric-label">Status Summary</p>
          <p class="mt-2 text-sm text-slate-500">One compact card is enough for payout status counts.</p>
        </div>
        <p class="text-xs text-slate-500">Total {{ formatNumber(summary.total) }}</p>
      </div>

      <div class="mt-5 flex flex-wrap gap-3">
        <div
          v-for="item in summaryItems"
          :key="item.label"
          class="rounded-2xl border border-slate-200/70 bg-white/75 px-3 py-3"
        >
          <div class="flex items-center gap-2">
            <span class="app-badge app-badge-compact" :class="summaryTone(item.tone)">{{ item.label }}</span>
          </div>
          <p class="mt-2 text-lg font-semibold tracking-[-0.03em] text-slate-950">{{ formatNumber(item.value) }}</p>
        </div>
      </div>
    </section>

    <section class="mt-6 grid gap-4 xl:grid-cols-4">
      <article class="app-rail-card">
        <p class="app-metric-label">Provider</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ providerOps.provider }}</p>
        <p class="mt-2 text-xs text-slate-500">
          mode {{ providerOps.provider_mode || 'unknown' }} • live rollout {{ providerOps.live_execution_enabled ? 'enabled' : 'disabled' }}
        </p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Provider Ops</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ providerOps.degraded ? 'degraded' : 'healthy' }}</p>
        <p class="mt-2 text-xs text-slate-500">{{ providerOps.last_reconcile_heartbeat_at || 'No reconcile heartbeat yet' }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Stale / Ambiguous</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatNumber(providerOps.stale_or_ambiguous_count) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Retryable Failed</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatNumber(providerOps.retryable_failed_count) }}</p>
      </article>
    </section>

    <section
      v-if="providerOps.blocking_reason || !providerOps.provider_readiness?.ready"
      class="mt-6 rounded-[24px] border border-rose-200 bg-rose-50 px-5 py-4 text-rose-900"
    >
      <p class="font-semibold">Provider ops blocked</p>
      <p class="mt-1 text-sm">{{ providerOps.blocking_reason || providerOps.provider_readiness?.blocking_reason }}</p>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Payout Ledger</p>
        <h2 class="mt-2 app-section-title">Operator payout requests</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-[240px,1fr]">
          <select v-model="payoutStatusFilter" class="app-field" @change="currentPage = 1">
            <option value="all">All payouts</option>
            <option value="needs_review">Needs review</option>
            <option value="approved">Approved</option>
            <option value="processing">Processing</option>
            <option value="settled">Settled</option>
            <option value="execution_exception">Execution exceptions</option>
            <option value="cancelled">Cancelled</option>
            <option value="rejected">Rejected</option>
          </select>
          <input
            v-model="payoutSearch"
            class="app-field"
            type="search"
            placeholder="Search operator, destination, provider state, or reference"
            @input="currentPage = 1"
          />
        </div>
      </div>

      <div class="app-table-wrap">
        <table class="app-table app-table-compact">
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
            <tr v-for="item in paginatedRows" :key="item.id">
              <td>
                <p class="font-semibold text-slate-950">{{ item.operator_name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.operator_email }}</p>
                <p v-if="item.financial_context" class="mt-2 text-xs text-slate-500">
                  requestable {{ formatCurrency(item.financial_context.requestable_balance) }} • reserved {{ formatCurrency(item.financial_context.reserved_for_payout) }}
                </p>
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
                    'bg-amber-100 text-amber-700': item.status === 'pending_review',
                    'bg-orange-100 text-orange-700': item.status === 'review_required',
                    'bg-sky-100 text-sky-700': item.status === 'processing',
                    'bg-emerald-100 text-emerald-700': ['settled', 'paid'].includes(item.status),
                    'bg-slate-100 text-slate-600': item.status === 'approved',
                    'bg-zinc-100 text-zinc-700': item.status === 'cancelled',
                    'bg-rose-100 text-rose-700': ['rejected', 'failed'].includes(item.status),
                  }"
                >
                  {{ item.status }}
                </span>
                <p class="mt-2 text-xs text-slate-500">{{ item.provider_status || 'No provider status' }}</p>
                <p class="mt-1 text-xs text-slate-500">settlement {{ item.settlement_state }}</p>
                <p v-if="item.settlement_block_reason" class="mt-1 text-xs text-rose-600">{{ item.settlement_block_reason }}</p>
                <p v-if="item.post_execution_state" class="mt-1 text-xs text-slate-500">post-execution {{ item.post_execution_state }}</p>
                <p v-if="item.post_execution_reason" class="mt-1 text-xs text-slate-500">{{ item.post_execution_reason }}</p>
                <p v-if="item.post_execution_handed_off_at" class="mt-1 text-xs text-emerald-700">
                  handoff confirmed {{ item.post_execution_handed_off_at }} by {{ item.post_execution_handed_off_by_name || item.post_execution_handed_off_by_email || 'unknown admin' }}
                </p>
                <p v-if="item.latest_post_execution_event?.reason" class="mt-1 text-xs text-slate-500">
                  post-execution {{ item.latest_post_execution_event.event_type }}: {{ item.latest_post_execution_event.reason }}
                </p>
                <p v-if="item.settlement" class="mt-1 text-xs text-emerald-700">
                  settled {{ item.settlement.settled_at }} by {{ item.settlement.settled_by_name || item.settlement.settled_by_email || 'unknown admin' }}
                </p>
                <p v-if="item.settlement?.correction" class="mt-1 text-xs text-orange-700">
                  reversed {{ item.settlement.correction.corrected_at }} by {{ item.settlement.correction.corrected_by_name || item.settlement.correction.corrected_by_email || 'unknown admin' }}
                </p>
                <p v-if="item.settlement?.correction?.reason" class="mt-1 text-xs text-orange-700">
                  {{ item.settlement.correction.reason }}
                </p>
                <p v-if="item.latest_resolution?.reason" class="mt-1 text-xs text-slate-500">
                  resolution {{ item.latest_resolution.resolution_type }}: {{ item.latest_resolution.reason }}
                </p>
                <p v-if="item.latest_execution_attempt" class="mt-1 text-xs text-slate-500">
                  execution {{ item.latest_execution_attempt.execution_state }} • {{ item.latest_execution_attempt.execution_reference }}
                  <span v-if="item.latest_execution_attempt.provider_state">
                    • provider {{ item.latest_execution_attempt.provider_state }}
                  </span>
                </p>
                <p v-if="item.latest_execution_attempt?.is_stale" class="mt-1 text-xs text-rose-600">
                  stale: {{ item.latest_execution_attempt.stale_reason }}
                </p>
                <p v-if="item.latest_execution_attempt?.latest_resolution?.reason" class="mt-1 text-xs text-slate-500">
                  execution resolution {{ item.latest_execution_attempt.latest_resolution.resolution_type }}: {{ item.latest_execution_attempt.latest_resolution.reason }}
                </p>
                <p v-if="item.execution_preflight && !item.execution_preflight.ready" class="mt-1 text-xs text-rose-600">
                  execution blocked: {{ item.execution_preflight.blocking_reason }}
                </p>
              </td>
              <td>
                <p class="font-medium text-slate-950">{{ item.processing_mode || 'Not assigned' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.provider || item.latest_execution_attempt?.provider_name || 'manual' }}</p>
                <p v-if="item.latest_execution_attempt?.provider_state_source" class="mt-1 text-xs text-slate-500">
                  provider {{ item.latest_execution_attempt.provider_state_source }} @ {{ item.latest_execution_attempt.provider_state_checked_at || 'n/a' }}
                </p>
                <p v-if="item.retry_budget_remaining !== undefined" class="mt-1 text-xs text-slate-500">
                  retry budget remaining {{ item.retry_budget_remaining }}
                </p>
              </td>
              <td class="text-xs text-slate-500">
                {{ item.settlement?.settlement_reference || item.provider_transfer_reference || 'None' }}
                <p v-if="item.settlement?.notes" class="mt-1">{{ item.settlement.notes }}</p>
                <p v-if="item.settlement?.correction?.notes" class="mt-1 text-orange-700">{{ item.settlement.correction.notes }}</p>
                <p v-if="item.latest_resolution?.notes" class="mt-1">{{ item.latest_resolution.notes }}</p>
                <p v-if="item.latest_execution_attempt?.last_error" class="mt-1 text-rose-600">{{ item.latest_execution_attempt.last_error }}</p>
                <p v-if="item.latest_execution_attempt?.latest_resolution?.notes" class="mt-1">{{ item.latest_execution_attempt.latest_resolution.notes }}</p>
              </td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <button v-if="item.status === 'pending_review'" class="app-button-primary px-4 py-2.5" @click="submitAction(item.id, 'approve')">Approve</button>
                  <button v-if="item.status === 'pending_review'" class="app-button-secondary px-4 py-2.5" @click="rejectRequest(item.id)">Reject</button>
                  <button
                    v-if="item.post_execution_state === 'execution_completed_awaiting_settlement' && !item.post_execution_handed_off_at"
                    class="app-button-primary px-4 py-2.5"
                    @click="confirmSettlementHandoff(item)"
                  >
                    Confirm Settlement Handoff
                  </button>
                  <button
                    v-if="item.status === 'approved' && item.settlement_state === 'ready' && item.execution_preflight?.ready && (!item.latest_execution_attempt || item.latest_execution_attempt.retry_eligible)"
                    class="app-button-secondary px-4 py-2.5"
                    @click="triggerExecution(item)"
                  >
                    Trigger Execution
                  </button>
                  <button
                    v-if="item.latest_execution_attempt && ['pending_execution', 'dispatched', 'manual_followup_required'].includes(item.latest_execution_attempt.execution_state)"
                    class="app-button-secondary px-4 py-2.5"
                    @click="reconcileExecution(item.latest_execution_attempt)"
                  >
                    Reconcile Execution
                  </button>
                  <button
                    v-if="item.latest_execution_attempt?.retry_eligible && item.retry_budget_remaining > 0 && item.execution_preflight?.ready"
                    class="app-button-secondary px-4 py-2.5"
                    @click="retryExecution(item.latest_execution_attempt)"
                  >
                    Retry Execution
                  </button>
                  <button
                    v-if="item.latest_execution_attempt?.can_mark_completed"
                    class="app-button-primary px-4 py-2.5"
                    @click="markExecutionCompleted(item.latest_execution_attempt)"
                  >
                    Mark Execution Completed
                  </button>
                  <button
                    v-if="item.latest_execution_attempt?.can_mark_terminal_failed"
                    class="app-button-secondary px-4 py-2.5"
                    @click="markExecutionTerminalFailed(item.latest_execution_attempt)"
                  >
                    Mark Terminal Failed
                  </button>
                  <button
                    v-if="item.status === 'approved' && item.settlement_state === 'ready' && (!item.latest_execution_attempt || (item.latest_execution_attempt.execution_state === 'completed' && item.post_execution_handed_off_at))"
                    class="app-button-primary px-4 py-2.5"
                    @click="settleRequest(item)"
                  >
                    Record Settlement
                  </button>
                  <button
                    v-if="['pending_review', 'approved', 'review_required'].includes(item.status)"
                    class="inline-flex items-center justify-center rounded-full bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800"
                    @click="cancelRequest(item.id)"
                  >
                    Cancel
                  </button>
                  <button
                    v-if="['settled', 'paid'].includes(item.status) && !item.settlement?.correction"
                    class="app-button-secondary px-4 py-2.5"
                    @click="reverseSettlement(item)"
                  >
                    Reverse Settlement
                  </button>
                  <button
                    v-if="item.status === 'approved' && ['blocked_underfunded', 'blocked_manual_review'].includes(item.settlement_state)"
                    class="app-button-secondary px-4 py-2.5"
                    @click="returnToReview(item.id)"
                  >
                    Return To Review
                  </button>
                  <button
                    v-if="item.status === 'review_required'"
                    class="app-button-primary px-4 py-2.5"
                    @click="resolveReturnToReview(item)"
                  >
                    Return To Review
                  </button>
                  <button
                    v-if="item.status === 'review_required'"
                    class="app-button-secondary px-4 py-2.5"
                    @click="cancelAndRelease(item)"
                  >
                    Cancel And Release
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!paginatedRows.length">
              <td colspan="7">
                <div class="app-empty">No payout requests exist yet.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminPagination
        :current-page="currentPage"
        :last-page="lastPage"
        :total="filteredPayoutRequests.length"
        :from="from"
        :to="to"
        @change="goToPage"
      />
    </section>
  </MainLayout>
</template>
