<script setup>
import { computed } from 'vue';
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

const paymentRows = computed(() => props.payments.data || []);
const statusCounts = computed(() => ({
  paid: paymentRows.value.filter((payment) => payment.status === 'paid').length,
  pending: paymentRows.value.filter((payment) => ['pending', 'awaiting_payment'].includes(payment.status)).length,
  expired: paymentRows.value.filter((payment) => payment.status === 'expired').length,
  failed: paymentRows.value.filter((payment) => ['failed', 'canceled'].includes(payment.status)).length,
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
            <tr v-for="payment in paymentRows" :key="payment.id">
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
            <tr v-if="!paymentRows.length">
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
