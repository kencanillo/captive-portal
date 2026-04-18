<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
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
    <div class="grid gap-4 md:grid-cols-4">
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Current earnings</p>
        <p class="mt-2 text-2xl font-bold">₱{{ summary.earnings }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Available balance</p>
        <p class="mt-2 text-2xl font-bold">₱{{ summary.available_balance }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Reserved</p>
        <p class="mt-2 text-2xl font-bold">₱{{ summary.reserved }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Paid out</p>
        <p class="mt-2 text-2xl font-bold">₱{{ summary.paid_out }}</p>
      </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr,1.1fr]">
      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Request a payout</h2>
        <p class="mt-1 text-sm text-slate-600">
          Default processing is manual unless programmatic payouts are explicitly configured and enabled.
        </p>

        <form class="mt-5 space-y-4" @submit.prevent="submit">
          <div>
            <label class="block text-sm font-medium text-slate-700">Amount</label>
            <input v-model="form.amount" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-md border-slate-300" />
            <InputError class="mt-2" :message="form.errors.amount" />
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-slate-700">Destination type</label>
              <select v-model="form.destination_type" class="mt-1 w-full rounded-md border-slate-300">
                <option value="bank">Bank account</option>
                <option value="e_wallet">E-wallet</option>
                <option value="paymongo_wallet">PayMongo wallet</option>
              </select>
              <InputError class="mt-2" :message="form.errors.destination_type" />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700">Transfer provider</label>
              <input v-model="form.destination_provider" class="mt-1 w-full rounded-md border-slate-300" placeholder="instapay, pesonet, paymongo" />
              <InputError class="mt-2" :message="form.errors.destination_provider" />
            </div>
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-slate-700">Account name</label>
              <input v-model="form.destination_account_name" class="mt-1 w-full rounded-md border-slate-300" />
              <InputError class="mt-2" :message="form.errors.destination_account_name" />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700">Account number / reference</label>
              <input v-model="form.destination_account_reference" class="mt-1 w-full rounded-md border-slate-300" />
              <InputError class="mt-2" :message="form.errors.destination_account_reference" />
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700">Bank / wallet code (optional)</label>
            <input v-model="form.destination_bic" class="mt-1 w-full rounded-md border-slate-300" />
            <InputError class="mt-2" :message="form.errors.destination_bic" />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700">Destination notes</label>
            <textarea v-model="form.destination_notes" class="mt-1 w-full rounded-md border-slate-300"></textarea>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700">Request notes</label>
            <textarea v-model="form.notes" class="mt-1 w-full rounded-md border-slate-300"></textarea>
          </div>

          <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white" :disabled="form.processing">
            Submit payout request
          </button>
        </form>
      </section>

      <section class="space-y-6">
        <div class="rounded-lg bg-white p-5 shadow">
          <h2 class="text-lg font-semibold text-slate-900">Pending requests</h2>
          <div class="mt-4 space-y-3">
            <article v-for="item in pendingRequests" :key="item.id" class="rounded-md border border-slate-200 px-4 py-3">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="font-medium text-slate-900">₱{{ item.amount }}</p>
                  <p class="text-sm text-slate-500">{{ item.destination_type }} · {{ item.destination_account_name }}</p>
                </div>
                <div class="text-right text-sm">
                  <p>{{ item.status }}</p>
                  <p class="text-slate-500">{{ item.processing_mode || 'awaiting review' }}</p>
                </div>
              </div>
            </article>
            <p v-if="!pendingRequests.length" class="text-sm text-slate-500">No pending payout requests.</p>
          </div>
        </div>

        <div class="rounded-lg bg-white p-5 shadow">
          <h2 class="text-lg font-semibold text-slate-900">Completed requests</h2>
          <div class="mt-4 space-y-3">
            <article v-for="item in completedRequests" :key="item.id" class="rounded-md border border-slate-200 px-4 py-3">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="font-medium text-slate-900">₱{{ item.amount }}</p>
                  <p class="text-sm text-slate-500">{{ item.status }} · {{ item.provider || 'manual' }}</p>
                </div>
                <div class="text-right text-xs text-slate-500">
                  <p>{{ item.provider_transfer_reference || 'No transfer reference' }}</p>
                  <p>{{ item.paid_at || item.reviewed_at || item.requested_at }}</p>
                </div>
              </div>
            </article>
            <p v-if="!completedRequests.length" class="text-sm text-slate-500">No completed payout requests yet.</p>
          </div>
        </div>
      </section>
    </div>
  </MainLayout>
</template>
