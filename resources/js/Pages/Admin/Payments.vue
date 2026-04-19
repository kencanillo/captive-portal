<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

const props = defineProps({
  payments: {
    type: Object,
    required: true,
  },
});

const paymentRows = computed(() => props.payments.data || []);
const statusCounts = computed(() => ({
  total: paymentRows.value.length,
  paid: paymentRows.value.filter((payment) => payment.status === 'paid').length,
  pending: paymentRows.value.filter((payment) => payment.status === 'pending').length,
  failed: paymentRows.value.filter((payment) => payment.status === 'failed').length,
}));
</script>

<template>
  <Head title="Payments" />

  <MainLayout title="Payments">
    <section>
      <p class="app-kicker">Checkout Ledger</p>
      <h1 class="mt-3 app-title">Payment activity</h1>
      <p class="mt-4 app-subtitle">
        This page stays focused on payment records and reference tracking. Keep payout logic separate. Blending those concerns into one table is bad finance design.
      </p>
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-metric-card">
        <p class="app-metric-label">Visible Payments</p>
        <p class="app-metric-value">{{ formatNumber(statusCounts.total) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Paid</p>
        <p class="app-metric-value">{{ formatNumber(statusCounts.paid) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Pending</p>
        <p class="app-metric-value">{{ formatNumber(statusCounts.pending) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Failed</p>
        <p class="app-metric-value">{{ formatNumber(statusCounts.failed) }}</p>
      </article>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Transactions</p>
        <h2 class="mt-2 app-section-title">Provider payment records</h2>
      </div>

      <div class="app-table-wrap">
        <table class="app-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Session</th>
              <th>Site</th>
              <th>Access Point</th>
              <th>Provider</th>
              <th>Reference</th>
              <th>Status</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="payment in paymentRows" :key="payment.id">
              <td class="font-semibold text-slate-950">{{ payment.id }}</td>
              <td>{{ payment.wifi_session_id }}</td>
              <td>{{ payment.wifi_session?.site?.name || '-' }}</td>
              <td>{{ payment.wifi_session?.access_point?.name || payment.wifi_session?.ap_name || '-' }}</td>
              <td class="capitalize">{{ payment.provider }}</td>
              <td>{{ payment.reference_id }}</td>
              <td>
                <span
                  class="app-badge"
                  :class="payment.status === 'paid' ? 'bg-emerald-100 text-emerald-700' : payment.status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700'"
                >
                  {{ payment.status }}
                </span>
              </td>
              <td>{{ payment.created_at }}</td>
            </tr>
            <tr v-if="!paymentRows.length">
              <td colspan="8">
                <div class="app-empty">No payment records are available.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </MainLayout>
</template>
