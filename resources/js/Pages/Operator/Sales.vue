<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import AdminPagination from '@/Components/AdminPagination.vue';
import { formatCurrency, formatDateTime, formatNumber } from '@/utils/formatters';

const props = defineProps({
  filters: {
    type: Object,
    required: true,
  },
  summary: {
    type: Object,
    required: true,
  },
  dailySales: {
    type: Array,
    required: true,
  },
  accessPointSales: {
    type: Array,
    required: true,
  },
  sales: {
    type: Object,
    required: true,
  },
});

const salesRows = computed(() => props.sales.data || []);

const applyFilters = (event) => {
  const form = new FormData(event.target);
  const params = Object.fromEntries([...form.entries()].filter(([, value]) => String(value).trim() !== ''));

  router.get('/operator/sales', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

const resetFilters = () => {
  router.get('/operator/sales', {}, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

const goToPage = (page) => {
  router.get('/operator/sales', {
    ...(props.filters || {}),
    page,
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};
</script>

<template>
  <Head title="Operator Sales" />

  <MainLayout title="Sales">
    <section>
      <p class="app-kicker">Sales Ledger</p>
      <h1 class="mt-3 app-title">Paid WiFi sales</h1>
      <p class="mt-4 app-subtitle">
        Sales are paid payment records from your assigned sites and claimed APs. Payouts stay separate because withdrawal state is not revenue state.
      </p>
    </section>

    <form class="mt-8 app-card p-5" @submit.prevent="applyFilters">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto]">
        <div>
          <label class="app-label">Date From</label>
          <input name="date_from" type="date" class="app-field" :value="filters.date_from" />
        </div>
        <div>
          <label class="app-label">Date To</label>
          <input name="date_to" type="date" class="app-field" :value="filters.date_to" />
        </div>
        <div class="flex items-end gap-3">
          <button type="submit" class="app-button-primary">Apply</button>
          <button type="button" class="app-button-secondary" @click="resetFilters">Reset</button>
        </div>
      </div>
    </form>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-rail-card">
        <p class="app-metric-label">Total Sales</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatCurrency(summary.total_sales) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Paid Payments</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatNumber(summary.paid_payments_count || 0) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Clients</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatNumber(summary.unique_clients_count || 0) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Access Points</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatNumber(summary.unique_access_points_count || 0) }}</p>
      </article>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-2">
      <article class="app-card p-6">
        <p class="app-kicker">By Date</p>
        <h2 class="mt-2 app-section-title">Daily sales</h2>
        <div class="mt-5 space-y-3">
          <div v-for="item in dailySales" :key="item.date" class="flex items-center justify-between rounded-[18px] border border-slate-200 px-4 py-3">
            <div>
              <p class="font-semibold text-slate-950">{{ item.date }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ formatNumber(item.count) }} paid payments</p>
            </div>
            <p class="font-semibold text-slate-950">{{ formatCurrency(item.total) }}</p>
          </div>
          <div v-if="!dailySales.length" class="app-empty">No paid sales in this date range.</div>
        </div>
      </article>

      <article class="app-card p-6">
        <p class="app-kicker">By AP</p>
        <h2 class="mt-2 app-section-title">Access point sales</h2>
        <div class="mt-5 space-y-3">
          <div v-for="item in accessPointSales" :key="`${item.access_point_name}-${item.access_point_mac}`" class="flex items-center justify-between rounded-[18px] border border-slate-200 px-4 py-3">
            <div class="min-w-0">
              <p class="truncate font-semibold text-slate-950">{{ item.access_point_name }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ item.access_point_mac || 'No AP MAC' }} | {{ formatNumber(item.count) }} paid payments</p>
            </div>
            <p class="font-semibold text-slate-950">{{ formatCurrency(item.total) }}</p>
          </div>
          <div v-if="!accessPointSales.length" class="app-empty">No AP sales in this date range.</div>
        </div>
      </article>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Transactions</p>
        <h2 class="mt-2 app-section-title">Paid payment records</h2>
      </div>

      <div class="app-table-wrap">
        <table class="app-table app-table-compact">
          <thead>
            <tr>
              <th>Date</th>
              <th>Client</th>
              <th>AP</th>
              <th>Plan</th>
              <th>Reference</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sale in salesRows" :key="sale.id">
              <td class="text-[11px] text-slate-500">{{ formatDateTime(sale.paid_at || sale.created_at) }}</td>
              <td>
                <p class="font-semibold text-slate-950">{{ sale.client?.name || 'Unknown client' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ sale.client?.phone_number || sale.mac_address || 'No client MAC' }}</p>
              </td>
              <td>
                <p class="font-medium text-slate-950">{{ sale.access_point?.name || sale.ap_name || 'Unassigned AP' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ sale.site?.name || 'No site' }} | {{ sale.access_point?.mac_address || sale.ap_mac || 'No AP MAC' }}</p>
              </td>
              <td>{{ sale.plan?.name || '-' }}</td>
              <td class="font-medium text-slate-950">{{ sale.reference_id || `Payment #${sale.id}` }}</td>
              <td class="font-semibold text-slate-950">{{ formatCurrency(sale.amount) }}</td>
            </tr>
            <tr v-if="!salesRows.length">
              <td colspan="6">
                <div class="app-empty">No paid sales match these filters.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminPagination
        :current-page="props.sales.current_page || 1"
        :last-page="props.sales.last_page || 1"
        :total="props.sales.total || salesRows.length"
        :from="props.sales.from || 0"
        :to="props.sales.to || salesRows.length"
        @change="goToPage"
      />
    </section>
  </MainLayout>
</template>
