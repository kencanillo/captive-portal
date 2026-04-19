<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

const props = defineProps({
  operators: Array,
});

const summary = computed(() => ({
  total: props.operators.length,
  approved: props.operators.filter((operator) => operator.status === 'approved').length,
  pending: props.operators.filter((operator) => operator.status === 'pending').length,
  revenue: props.operators.reduce((total, operator) => total + Number(operator.revenue_total || 0), 0),
}));
</script>

<template>
  <Head title="Operators" />

  <MainLayout title="Operators">
    <section>
      <p class="app-kicker">Multi-Operator Control</p>
      <h1 class="mt-3 app-title">Operator registry</h1>
      <p class="mt-4 app-subtitle">
        This is the admin control point for approvals, site assignment, revenue visibility, and payout balance review. Operators are not generic users. Treat them like a managed business entity.
      </p>
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-metric-card">
        <p class="app-metric-label">Operators</p>
        <p class="app-metric-value">{{ formatNumber(summary.total) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Approved</p>
        <p class="app-metric-value">{{ formatNumber(summary.approved) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Pending</p>
        <p class="app-metric-value">{{ formatNumber(summary.pending) }}</p>
      </article>
      <article class="app-card-dark p-6">
        <p class="app-top-stat">
          <span class="material-symbols-outlined text-[16px]">payments</span>
          Revenue generated
        </p>
        <p class="mt-5 text-4xl font-semibold tracking-[-0.05em] text-white">{{ formatCurrency(summary.revenue) }}</p>
      </article>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Operator Accounts</p>
        <h2 class="mt-2 app-section-title">Approvals, site ownership, and balance view</h2>
      </div>

      <div class="app-table-wrap">
        <table class="app-table">
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
            <tr v-for="operator in operators" :key="operator.id">
              <td>
                <p class="font-semibold text-slate-950">{{ operator.business_name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ operator.requested_site_name || 'No requested site' }}</p>
              </td>
              <td>
                <p class="font-medium text-slate-950">{{ operator.contact_name }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ operator.email }} • {{ operator.phone_number }}</p>
              </td>
              <td>{{ operator.sites.join(', ') || 'Unassigned' }}</td>
              <td>
                <span
                  class="app-badge"
                  :class="{
                    'bg-emerald-100 text-emerald-700': operator.status === 'approved',
                    'bg-amber-100 text-amber-700': operator.status === 'pending',
                    'bg-rose-100 text-rose-700': operator.status === 'rejected',
                  }"
                >
                  {{ operator.status }}
                </span>
              </td>
              <td>{{ formatCurrency(operator.available_balance) }}</td>
              <td>{{ formatCurrency(operator.revenue_total) }}</td>
              <td>
                <Link :href="`/admin/operators/${operator.id}`" class="app-button-secondary px-4 py-2.5">
                  Open
                </Link>
              </td>
            </tr>
            <tr v-if="!operators.length">
              <td colspan="7">
                <div class="app-empty">No operators have registered yet.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </MainLayout>
</template>
