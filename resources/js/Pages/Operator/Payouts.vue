<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';
import { formatCurrency } from '@/utils/formatters';

defineProps({
  summary: Object,
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

    <section class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <article class="app-metric-card">
        <p class="app-metric-label">Current Earnings</p>
        <p class="app-metric-value">{{ formatCurrency(summary.earnings) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Available Balance</p>
        <p class="app-metric-value">{{ formatCurrency(summary.available_balance) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Reserved</p>
        <p class="app-metric-value">{{ formatCurrency(summary.reserved) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Paid Out</p>
        <p class="app-metric-value">{{ formatCurrency(summary.paid_out) }}</p>
      </article>
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

          <button type="submit" class="app-button-primary" :disabled="form.processing">
            Submit payout request
          </button>
        </form>
      </section>

      <section class="space-y-6">
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
                  <p class="mt-1 text-xs text-slate-500">{{ item.processing_mode || 'awaiting review' }}</p>
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
                </div>
                <div class="text-left text-xs text-slate-500 sm:text-right">
                  <p>{{ item.provider_transfer_reference || 'No transfer reference' }}</p>
                  <p class="mt-1">{{ item.paid_at || item.reviewed_at || item.requested_at }}</p>
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
