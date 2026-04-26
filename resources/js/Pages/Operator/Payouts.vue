<script setup>
import { computed } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

const props = defineProps({
  summary: Object,
  providerOps: Object,
  statementLines: Array,
  pendingRequests: Array,
  completedRequests: Array,
});

const form = useForm({
  amount: '',
  destination_type: 'bank',
  destination_account_name: '',
  destination_account_reference: '',
  destination_provider: 'instapay',
  destination_bic: '',
  destination_notes: '',
  notes: '',
});

const submit = () => {
  form.post('/operator/payouts', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });
};

const requests = computed(() => [
  ...(props.pendingRequests || []),
  ...(props.completedRequests || []),
]);

const statusSummary = computed(() => ({
  total: requests.value.length,
  pending: requests.value.filter((item) => item.status === 'pending_review').length,
  approved: requests.value.filter((item) => item.status === 'approved').length,
  reviewRequired: requests.value.filter((item) => item.status === 'review_required').length,
  settled: requests.value.filter((item) => ['settled', 'paid'].includes(item.status)).length,
  rejected: requests.value.filter((item) => item.status === 'rejected').length,
  failed: requests.value.filter((item) => item.status === 'failed').length,
}));

const statusItems = computed(() => ([
  { label: 'Requests', value: statusSummary.value.total, tone: 'slate' },
  { label: 'Pending', value: statusSummary.value.pending, tone: 'amber' },
  { label: 'Approved', value: statusSummary.value.approved, tone: 'sky' },
  { label: 'Review', value: statusSummary.value.reviewRequired, tone: 'orange' },
  { label: 'Settled', value: statusSummary.value.settled, tone: 'emerald' },
  { label: 'Rejected', value: statusSummary.value.rejected, tone: 'rose' },
  { label: 'Failed', value: statusSummary.value.failed, tone: 'rose' },
]));

const summaryTone = (tone) => ({
  slate: 'bg-slate-100 text-slate-700',
  amber: 'bg-amber-100 text-amber-700',
  sky: 'bg-sky-100 text-sky-700',
  orange: 'bg-orange-100 text-orange-700',
  emerald: 'bg-emerald-100 text-emerald-700',
  rose: 'bg-rose-100 text-rose-700',
}[tone] || 'bg-slate-100 text-slate-700');
</script>

<template>
  <Head title="Payouts" />

  <MainLayout title="Payout Requests">
    <section>
      <p class="app-kicker">Operator Ledger</p>
      <h1 class="mt-3 app-title">Request payouts</h1>
      <p class="mt-4 app-subtitle">
        Payouts are requested here, reviewed by admin, and executed manually by default. Do not fake automated settlement when the provider setup is not actually there.
      </p>
    </section>

    <section class="mt-8 app-rail-card">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <p class="app-metric-label">Status Summary</p>
          <p class="mt-2 text-sm text-slate-500">Same compact payout status model as admin. No bloated card wall.</p>
        </div>
        <p class="text-xs text-slate-500">Total {{ formatNumber(statusSummary.total) }}</p>
      </div>

      <div class="mt-5 flex flex-wrap gap-3">
        <div
          v-for="item in statusItems"
          :key="item.label"
          class="rounded-2xl border border-slate-200/70 bg-white/75 px-3 py-3"
        >
          <span class="app-badge app-badge-compact" :class="summaryTone(item.tone)">{{ item.label }}</span>
          <p class="mt-2 text-lg font-semibold tracking-[-0.03em] text-slate-950">{{ formatNumber(item.value) }}</p>
        </div>
      </div>
    </section>

    <section class="mt-6 grid gap-4 xl:grid-cols-4">
      <article class="app-rail-card">
        <p class="app-metric-label">Gross AP Fees</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatCurrency(summary.gross_billed_fees) }}</p>
        <p class="mt-2 text-xs text-slate-500">Reversed {{ formatCurrency(summary.reversed_fees) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Net Payable</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatCurrency(summary.net_payable_fees) }}</p>
        <p class="mt-2 text-xs text-slate-500">Blocked {{ formatCurrency(summary.blocked_fees) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Reserved</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatCurrency(summary.reserved_for_payout) }}</p>
        <p class="mt-2 text-xs text-slate-500">
          pending {{ formatCurrency(summary.pending_review_reserved) }} • approved {{ formatCurrency(summary.approved_unpaid_reserved) }}
        </p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Requestable</p>
        <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatCurrency(summary.available_balance) }}</p>
        <p class="mt-2 text-xs text-slate-500">Settled {{ formatCurrency(summary.settled_total) }}</p>
      </article>
    </section>

    <section v-if="summary.confidence_reasons?.length" class="mt-6 rounded-[24px] border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
      Accounting confidence is {{ summary.confidence_state }}.
      <span v-for="reason in summary.confidence_reasons" :key="reason" class="block">{{ reason }}</span>
    </section>

    <section
      v-if="providerOps.blocking_reason || !providerOps.provider_readiness?.ready"
      class="mt-6 rounded-[24px] border border-rose-200 bg-rose-50 px-5 py-4 text-rose-900"
    >
      Provider payout execution is blocked.
      <span class="block">{{ providerOps.blocking_reason || providerOps.provider_readiness?.blocking_reason }}</span>
      <span class="block text-xs">mode {{ providerOps.provider_mode || 'unknown' }} • live rollout {{ providerOps.live_execution_enabled ? 'enabled' : 'disabled' }}</span>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[0.92fr,1.08fr]">
      <section class="app-card-strong p-7">
        <p class="app-kicker">New Request</p>
        <h2 class="mt-3 app-section-title">Submit payout details</h2>
        <p class="app-section-copy">Capture a clean destination snapshot now so the admin review step has enough context without chasing the operator later.</p>

        <form class="mt-8 space-y-5" @submit.prevent="submit">
          <div>
            <label class="app-label">Amount</label>
            <input v-model="form.amount" type="number" min="0.01" step="0.01" class="app-field" />
            <InputError class="mt-2" :message="form.errors.amount" />
          </div>

          <div class="grid gap-5 md:grid-cols-2">
            <div>
              <label class="app-label">Destination Type</label>
              <select v-model="form.destination_type" class="app-field">
                <option value="bank">Bank account</option>
                <option value="e_wallet">E-wallet</option>
                <option value="paymongo_wallet">PayMongo wallet</option>
              </select>
              <InputError class="mt-2" :message="form.errors.destination_type" />
            </div>

            <div>
              <label class="app-label">Transfer Provider</label>
              <input v-model="form.destination_provider" class="app-field" placeholder="instapay, pesonet, paymongo" />
              <InputError class="mt-2" :message="form.errors.destination_provider" />
            </div>
          </div>

          <div class="grid gap-5 md:grid-cols-2">
            <div>
              <label class="app-label">Account Name</label>
              <input v-model="form.destination_account_name" class="app-field" />
              <InputError class="mt-2" :message="form.errors.destination_account_name" />
            </div>

            <div>
              <label class="app-label">Account Number / Reference</label>
              <input v-model="form.destination_account_reference" class="app-field" />
              <InputError class="mt-2" :message="form.errors.destination_account_reference" />
            </div>
          </div>

          <div>
            <label class="app-label">Bank / Wallet Code</label>
            <input v-model="form.destination_bic" class="app-field" />
            <InputError class="mt-2" :message="form.errors.destination_bic" />
          </div>

          <div>
            <label class="app-label">Destination Notes</label>
            <textarea v-model="form.destination_notes" class="app-field min-h-[96px]" />
          </div>

          <div>
            <label class="app-label">Request Notes</label>
            <textarea v-model="form.notes" class="app-field min-h-[96px]" />
          </div>

          <button
            type="submit"
            class="app-button-primary"
            :disabled="form.processing || Number(summary.requestable_balance || 0) <= 0 || summary.confidence_state !== 'healthy'"
          >
            Submit payout request
          </button>
        </form>
      </section>

      <section class="space-y-6">
        <div class="app-card p-7">
          <p class="app-kicker">Statement</p>
          <h2 class="mt-3 app-section-title">Recent AP fee entries</h2>
          <div class="mt-6 space-y-3">
            <article v-for="item in statementLines" :key="item.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p class="font-semibold text-slate-950">{{ formatCurrency(item.amount) }} • {{ item.direction }}</p>
                  <p class="mt-1 text-sm text-slate-500">
                    {{ item.site?.name || 'No site' }} • {{ item.access_point?.name || 'No AP' }} • source #{{ item.source_billing_ledger_entry_id }}
                  </p>
                </div>
                <div class="text-left sm:text-right">
                  <p class="font-medium text-slate-950">{{ formatCurrency(item.payable_effect_amount) }}</p>
                  <p class="mt-1 text-xs text-slate-500">{{ item.affects_payable ? 'payable effect' : 'excluded from payable' }}</p>
                </div>
              </div>
            </article>
            <div v-if="!statementLines.length" class="app-empty">No statement lines yet.</div>
          </div>
        </div>

        <div class="app-card p-7">
          <p class="app-kicker">Pending Requests</p>
          <h2 class="mt-3 app-section-title">Awaiting review or payout</h2>
          <div class="mt-6 space-y-3">
            <article v-for="item in pendingRequests" :key="item.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p class="font-semibold text-slate-950">{{ formatCurrency(item.amount) }}</p>
                  <p class="mt-1 text-sm text-slate-500">{{ item.destination_type }} • {{ item.destination_account_name }}</p>
                </div>
                <div class="text-left sm:text-right">
                  <p class="font-medium text-slate-950">{{ item.status }}</p>
                  <p class="mt-1 text-xs text-slate-500">{{ item.processing_mode || 'reserved awaiting review' }}</p>
                  <p class="mt-1 text-xs text-slate-500">settlement {{ item.settlement_state }}</p>
                  <p v-if="item.settlement_block_reason" class="mt-1 text-xs text-rose-600">{{ item.settlement_block_reason }}</p>
                  <p v-if="item.post_execution_state" class="mt-1 text-xs text-slate-500">post-execution {{ item.post_execution_state }}</p>
                  <p v-if="item.post_execution_reason" class="mt-1 text-xs text-slate-500">{{ item.post_execution_reason }}</p>
                  <p v-if="item.post_execution_handed_off_at" class="mt-1 text-xs text-emerald-700">
                    settlement handoff confirmed {{ item.post_execution_handed_off_at }}
                  </p>
                  <p v-if="item.latest_post_execution_event?.reason" class="mt-1 text-xs text-slate-500">
                    post-execution {{ item.latest_post_execution_event.event_type }}: {{ item.latest_post_execution_event.reason }}
                  </p>
                  <p v-if="item.settlement?.settlement_reference" class="mt-1 text-xs text-slate-500">{{ item.settlement.settlement_reference }}</p>
                  <p v-if="item.settlement?.correction?.reason" class="mt-1 text-xs text-orange-700">{{ item.settlement.correction.reason }}</p>
                  <p v-if="item.latest_resolution?.reason" class="mt-1 text-xs text-slate-500">
                    {{ item.latest_resolution.resolution_type }}: {{ item.latest_resolution.reason }}
                  </p>
                  <p v-if="item.post_execution_state" class="mt-1 text-xs text-slate-500">post-execution {{ item.post_execution_state }}</p>
                  <p v-if="item.post_execution_reason" class="mt-1 text-xs text-slate-500">{{ item.post_execution_reason }}</p>
                  <p v-if="item.latest_execution_attempt" class="mt-1 text-xs text-slate-500">
                    execution {{ item.latest_execution_attempt.execution_state }} • {{ item.latest_execution_attempt.execution_reference }}
                    <span v-if="item.latest_execution_attempt.provider_state">
                      • provider {{ item.latest_execution_attempt.provider_state }}
                    </span>
                  </p>
                  <p v-if="item.latest_execution_attempt?.is_stale" class="mt-1 text-xs text-rose-600">
                    stale: {{ item.latest_execution_attempt.stale_reason }}
                  </p>
                  <p v-if="item.execution_preflight && !item.execution_preflight.ready" class="mt-1 text-xs text-rose-600">
                    execution blocked: {{ item.execution_preflight.blocking_reason }}
                  </p>
                  <p v-if="item.latest_execution_attempt?.last_error" class="mt-1 text-xs text-rose-600">{{ item.latest_execution_attempt.last_error }}</p>
                  <p v-if="item.latest_execution_attempt?.latest_resolution?.reason" class="mt-1 text-xs text-slate-500">
                    execution {{ item.latest_execution_attempt.latest_resolution.resolution_type }}: {{ item.latest_execution_attempt.latest_resolution.reason }}
                  </p>
                </div>
              </div>
            </article>
            <div v-if="!pendingRequests.length" class="app-empty">No pending payout requests.</div>
          </div>
        </div>

        <div class="app-card p-7">
          <p class="app-kicker">Completed Requests</p>
          <h2 class="mt-3 app-section-title">Review trail</h2>
          <div class="mt-6 space-y-3">
            <article v-for="item in completedRequests" :key="item.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p class="font-semibold text-slate-950">{{ formatCurrency(item.amount) }}</p>
                  <p class="mt-1 text-sm text-slate-500">{{ item.status }} • {{ item.provider || 'manual' }}</p>
                  <p v-if="item.settlement?.settlement_reference" class="mt-1 text-xs text-slate-500">
                    settlement ref {{ item.settlement.settlement_reference }}
                  </p>
                  <p v-if="item.settlement?.notes" class="mt-1 text-xs text-slate-500">{{ item.settlement.notes }}</p>
                  <p v-if="item.settlement?.correction?.reason" class="mt-1 text-xs text-orange-700">
                    reversed: {{ item.settlement.correction.reason }}
                  </p>
                  <p v-if="item.settlement?.correction?.notes" class="mt-1 text-xs text-orange-700">{{ item.settlement.correction.notes }}</p>
                  <p v-if="item.latest_resolution?.reason" class="mt-1 text-xs text-slate-500">
                    {{ item.latest_resolution.resolution_type }}: {{ item.latest_resolution.reason }}
                  </p>
                  <p v-if="item.latest_execution_attempt" class="mt-1 text-xs text-slate-500">
                    execution {{ item.latest_execution_attempt.execution_state }} • {{ item.latest_execution_attempt.execution_reference }}
                    <span v-if="item.latest_execution_attempt.provider_state">
                      • provider {{ item.latest_execution_attempt.provider_state }}
                    </span>
                  </p>
                  <p v-if="item.latest_execution_attempt?.latest_resolution?.reason" class="mt-1 text-xs text-slate-500">
                    execution {{ item.latest_execution_attempt.latest_resolution.resolution_type }}: {{ item.latest_execution_attempt.latest_resolution.reason }}
                  </p>
                </div>
                <div class="text-left text-xs text-slate-500 sm:text-right">
                  <p>{{ item.settlement?.settlement_reference || item.provider_transfer_reference || 'No transfer reference' }}</p>
                  <p class="mt-1">{{ item.settlement?.settled_at || item.cancelled_at || item.paid_at || item.reviewed_at || item.requested_at }}</p>
                  <p v-if="item.settlement_block_reason" class="mt-1 text-rose-600">{{ item.settlement_block_reason }}</p>
                </div>
              </div>
            </article>
            <div v-if="!completedRequests.length" class="app-empty">No completed payout requests yet.</div>
          </div>
        </div>
      </section>
    </section>
  </MainLayout>
</template>
