<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import AdminMiniLineChart from '@/Components/AdminMiniLineChart.vue';
import AdminPagination from '@/Components/AdminPagination.vue';

const props = defineProps({
  payments: {
    type: Object,
    required: true,
  },
});

const paymentStatusFilter = ref('all');
const paymentSearch = ref('');
const paymentRows = computed(() => props.payments.data || []);
const filteredPaymentRows = computed(() => {
  const query = paymentSearch.value.trim().toLowerCase();

  return paymentRows.value.filter((payment) => {
    const matchesStatus = paymentStatusFilter.value === 'all'
      || payment.status === paymentStatusFilter.value
      || (paymentStatusFilter.value === 'pending_group' && ['pending', 'awaiting_payment'].includes(payment.status))
      || (paymentStatusFilter.value === 'failed_group' && ['failed', 'canceled'].includes(payment.status));

    if (!matchesStatus) return false;
    if (!query) return true;

    return [
      payment.id,
      payment.wifi_session_id,
      payment.provider,
      payment.reference_id,
      payment.wifi_session?.site?.name,
      payment.wifi_session?.access_point?.name,
      payment.wifi_session?.ap_name,
    ].filter(Boolean).join(' ').toLowerCase().includes(query);
  });
});
const statusCounts = computed(() => ({
  paid: filteredPaymentRows.value.filter((payment) => payment.status === 'paid').length,
  pending: filteredPaymentRows.value.filter((payment) => ['pending', 'awaiting_payment'].includes(payment.status)).length,
  expired: filteredPaymentRows.value.filter((payment) => payment.status === 'expired').length,
  failed: filteredPaymentRows.value.filter((payment) => ['failed', 'canceled'].includes(payment.status)).length,
}));

const statusSeries = computed(() => ([
  { label: 'Paid', value: statusCounts.value.paid, color: '#34d399' },
  { label: 'Pending', value: statusCounts.value.pending, color: '#38bdf8' },
  { label: 'Expired', value: statusCounts.value.expired, color: '#f59e0b' },
  { label: 'Failed', value: statusCounts.value.failed, color: '#fb7185' },
]));

const badgeTone = (status) => ({
  paid: 'bg-emerald-100 text-emerald-700',
  pending: 'bg-amber-100 text-amber-700',
  awaiting_payment: 'bg-sky-100 text-sky-700',
  expired: 'bg-slate-100 text-slate-600',
  failed: 'bg-rose-100 text-rose-700',
  canceled: 'bg-rose-100 text-rose-700',
}[status] || 'bg-slate-100 text-slate-600');

const goToPage = (page) => {
  router.get('/admin/payments', { page }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};
</script>

<template>
  <Head title="Payments" />

  <MainLayout title="Payments">
    <section>
      <p class="app-kicker">Checkout Ledger</p>
      <h1 class="mt-3 app-title">Payment activity</h1>
      <p class="mt-4 app-subtitle">
        Payment analytics belong in one readable rail, not four oversized cards. The table stays focused on records, references, and state.
      </p>
    </section>

    <section class="mt-8">
      <AdminMiniLineChart
        title="Payment State Rail"
        subtitle="This strip shows the current mix of paid, pending, expired, and failed records in the visible page."
        mode="rail"
        :points="statusSeries"
      >
        <template #meta>
          <span class="app-badge-neutral">{{ props.payments.total || paymentRows.length }} records</span>
        </template>
      </AdminMiniLineChart>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Transactions</p>
        <h2 class="mt-2 app-section-title">Provider payment records</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-[220px,1fr]">
          <select v-model="paymentStatusFilter" class="app-field">
            <option value="all">All payments</option>
            <option value="paid">Paid</option>
            <option value="pending_group">Pending / awaiting</option>
            <option value="expired">Expired</option>
            <option value="failed_group">Failed / canceled</option>
            <option value="waived">Waived</option>
            <option value="cash_collected">Cash collected</option>
          </select>
          <input
            v-model="paymentSearch"
            class="app-field"
            type="search"
            placeholder="Search reference, session, provider, site, or AP"
          />
        </div>
      </div>

      <div class="app-table-wrap">
        <table class="app-table app-table-compact">
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
            <tr v-for="payment in filteredPaymentRows" :key="payment.id">
              <td class="font-semibold text-slate-950">{{ payment.id }}</td>
              <td>{{ payment.wifi_session_id }}</td>
              <td>{{ payment.wifi_session?.site?.name || '-' }}</td>
              <td>{{ payment.wifi_session?.access_point?.name || payment.wifi_session?.ap_name || '-' }}</td>
              <td class="capitalize">{{ payment.provider }}</td>
              <td class="font-medium text-slate-950">{{ payment.reference_id }}</td>
              <td>
                <span class="app-badge app-badge-compact" :class="badgeTone(payment.status)">
                  {{ payment.status }}
                </span>
              </td>
              <td class="text-[11px] text-slate-500">{{ payment.created_at }}</td>
            </tr>
            <tr v-if="!filteredPaymentRows.length">
              <td colspan="8">
                <div class="app-empty">No payment records are available.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminPagination
        :current-page="props.payments.current_page || 1"
        :last-page="props.payments.last_page || 1"
        :total="props.payments.total || paymentRows.length"
        :from="props.payments.from || 0"
        :to="props.payments.to || paymentRows.length"
        @change="goToPage"
      />
    </section>
  </MainLayout>
</template>
