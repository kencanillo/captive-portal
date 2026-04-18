<script setup>
import { router, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

defineProps({
  payoutRequests: Array,
});

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

  if (reviewNotes === null) return;

  submitAction(id, 'reject', reviewNotes);
};

const failRequest = (id) => {
  const reviewNotes = window.prompt('Failure reason');

  if (reviewNotes === null || reviewNotes.trim() === '') return;

  submitAction(id, 'failed', reviewNotes);
};
</script>

<template>
  <Head title="Payout Requests" />

  <MainLayout title="Payout Requests">
    <div class="rounded-lg bg-white p-5 shadow">
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-slate-500">
              <th class="px-2 py-2">Operator</th>
              <th class="px-2 py-2">Amount</th>
              <th class="px-2 py-2">Destination</th>
              <th class="px-2 py-2">Status</th>
              <th class="px-2 py-2">Handling</th>
              <th class="px-2 py-2">Reference</th>
              <th class="px-2 py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in payoutRequests" :key="item.id" class="border-b border-slate-100">
              <td class="px-2 py-3">
                <p class="font-medium text-slate-900">{{ item.operator_name }}</p>
                <p class="text-xs text-slate-500">{{ item.operator_email }}</p>
              </td>
              <td class="px-2 py-3">₱{{ item.amount }}</td>
              <td class="px-2 py-3">
                <p>{{ item.destination_type }}</p>
                <p class="text-xs text-slate-500">{{ item.destination_account_name }} · {{ item.destination_account_reference }}</p>
              </td>
              <td class="px-2 py-3">
                <p>{{ item.status }}</p>
                <p class="text-xs text-slate-500">{{ item.provider_status || 'No provider status' }}</p>
              </td>
              <td class="px-2 py-3">
                <p>{{ item.processing_mode || 'Not assigned' }}</p>
                <p class="text-xs text-slate-500">{{ item.provider || 'manual' }}</p>
              </td>
              <td class="px-2 py-3 text-xs text-slate-500">{{ item.provider_transfer_reference || 'None' }}</td>
              <td class="px-2 py-3">
                <div class="flex flex-wrap gap-2">
                  <button v-if="item.status === 'pending'" class="rounded-md bg-emerald-700 px-3 py-1 text-xs font-semibold text-white" @click="submitAction(item.id, 'approve')">Approve</button>
                  <button v-if="item.status === 'pending'" class="rounded-md bg-rose-600 px-3 py-1 text-xs font-semibold text-white" @click="rejectRequest(item.id)">Reject</button>
                  <button v-if="['approved', 'processing'].includes(item.status)" class="rounded-md bg-slate-800 px-3 py-1 text-xs font-semibold text-white" @click="submitAction(item.id, 'processing')">Processing</button>
                  <button v-if="['approved', 'processing'].includes(item.status)" class="rounded-md bg-emerald-700 px-3 py-1 text-xs font-semibold text-white" @click="submitAction(item.id, 'paid')">Paid</button>
                  <button v-if="['approved', 'processing'].includes(item.status)" class="rounded-md bg-rose-600 px-3 py-1 text-xs font-semibold text-white" @click="failRequest(item.id)">Failed</button>
                </div>
              </td>
            </tr>
            <tr v-if="!payoutRequests.length">
              <td colspan="7" class="px-2 py-6 text-center text-slate-500">No payout requests yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </MainLayout>
</template>
