<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import AdminPagination from '@/Components/AdminPagination.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

const props = defineProps({
  operators: Array,
});

const currentPage = ref(1);
const perPage = 20;

const summary = computed(() => ({
  total: props.operators.length,
  approved: props.operators.filter((operator) => operator.status === 'approved').length,
  pending: props.operators.filter((operator) => operator.status === 'pending').length,
  revenue: props.operators.reduce((total, operator) => total + Number(operator.revenue_total || 0), 0),
}));

const lastPage = computed(() => Math.max(1, Math.ceil(props.operators.length / perPage)));
const operatorRows = computed(() => {
  const start = (currentPage.value - 1) * perPage;

  return props.operators.slice(start, start + perPage);
});

const from = computed(() => props.operators.length ? ((currentPage.value - 1) * perPage) + 1 : 0);
const to = computed(() => Math.min(currentPage.value * perPage, props.operators.length));

const goToPage = (page) => {
  currentPage.value = page;
};
</script>

<template>
  <Head title="Operators" />

  <MainLayout title="Operators">
    <section>
      <p class="app-kicker">Multi-Operator Control</p>
      <h1 class="mt-3 app-title">Operator registry</h1>
      <p class="mt-4 app-subtitle">
        Approvals, assigned sites, balance, and revenue stay in one compact ledger. The page should read fast under review, not drown the operator list in oversized summary blocks.
      </p>
    </section>

    <section class="mt-8 grid gap-4 lg:grid-cols-[1.2fr,0.8fr]">
      <div class="grid gap-4 sm:grid-cols-3">
        <article class="app-rail-card">
          <p class="app-metric-label">Operators</p>
          <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatNumber(summary.total) }}</p>
        </article>
        <article class="app-rail-card">
          <p class="app-metric-label">Approved</p>
          <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatNumber(summary.approved) }}</p>
        </article>
        <article class="app-rail-card">
          <p class="app-metric-label">Pending</p>
          <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatNumber(summary.pending) }}</p>
        </article>
      </div>
      <article class="app-card-dark p-6">
        <p class="app-top-stat">Revenue generated</p>
        <p class="mt-5 text-4xl font-semibold tracking-[-0.05em] text-white">{{ formatCurrency(summary.revenue) }}</p>
        <p class="mt-3 text-sm text-slate-300">This number stays visible, but it no longer dominates the page layout.</p>
      </article>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Operator Accounts</p>
        <h2 class="mt-2 app-section-title">Approvals, site ownership, and balance view</h2>
      </div>

      <div class="app-table-wrap">
        <table class="app-table app-table-compact">
          <thead>
            <tr>
              <th>Operator</th>
              <th>Contact</th>
              <th>Assigned Sites</th>
              <th>Status</th>
              <th>Balance</th>
              <th>Revenue</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="operator in operatorRows" :key="operator.id">
              <td class="align-middle">
                <p class="font-semibold text-slate-950">{{ operator.business_name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ operator.requested_site_name || 'No requested site' }}</p>
              </td>
              <td class="align-middle">
                <p class="font-medium text-slate-950">{{ operator.contact_name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ operator.email }} • {{ operator.phone_number }}</p>
              </td>
              <td class="align-middle text-sm text-slate-600">{{ operator.sites.join(', ') || 'Unassigned' }}</td>
              <td class="align-middle">
                <span
                  class="app-badge app-badge-compact"
                  :class="{
                    'bg-emerald-100 text-emerald-700': operator.status === 'approved',
                    'bg-amber-100 text-amber-700': operator.status === 'pending',
                    'bg-rose-100 text-rose-700': operator.status === 'rejected',
                  }"
                >
                  {{ operator.status }}
                </span>
              </td>
              <td class="align-middle font-medium text-slate-950">{{ formatCurrency(operator.available_balance) }}</td>
              <td class="align-middle font-medium text-slate-950">{{ formatCurrency(operator.revenue_total) }}</td>
              <td class="align-middle">
                <Link :href="`/admin/operators/${operator.id}`" class="app-button-secondary px-4 py-2 text-[11px]">
                  Open
                </Link>
              </td>
            </tr>
            <tr v-if="!operatorRows.length">
              <td colspan="7">
                <div class="app-empty">No operators have registered yet.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminPagination
        :current-page="currentPage"
        :last-page="lastPage"
        :total="props.operators.length"
        :from="from"
        :to="to"
        @change="goToPage"
      />
    </section>
  </MainLayout>
</template>
