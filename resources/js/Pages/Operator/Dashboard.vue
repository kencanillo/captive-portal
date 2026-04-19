<script setup>
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

defineProps({
  summary: Object,
  sites: Array,
  recentSessions: Array,
  recentPayments: Array,
  recentAccessPoints: Array,
});
</script>

<template>
  <Head title="Operator Dashboard" />

  <MainLayout title="Operator Dashboard">
    <section class="grid gap-6 xl:grid-cols-[1.2fr,0.8fr]">
      <div class="app-card-dark p-7 sm:p-9">
        <p class="app-top-stat">
          <span class="material-symbols-outlined text-[16px]">domain</span>
          Operator overview
        </p>
        <h1 class="mt-5 text-4xl font-extrabold tracking-[-0.06em] text-white sm:text-5xl">
          {{ formatCurrency(summary.available_balance || 0) }}
        </h1>
        <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">
          This dashboard is site-scoped. Every card, activity list, and AP snapshot must stay inside the operator’s assigned footprint. Anything else is a tenancy leak.
        </p>

        <div class="mt-8 grid gap-4 sm:grid-cols-2">
          <div class="rounded-[22px] border border-white/10 bg-white/8 px-5 py-4">
            <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Completed Payments</p>
            <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-white">{{ formatNumber(summary.completed_payments_count || 0) }}</p>
          </div>
          <div class="rounded-[22px] border border-white/10 bg-white/8 px-5 py-4">
            <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Active Sessions</p>
            <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-white">{{ formatNumber(summary.active_sessions_count || 0) }}</p>
          </div>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
        <article class="app-metric-card">
          <p class="app-metric-label">Assigned Sites</p>
          <p class="app-metric-value">{{ formatNumber(summary.sites_count || 0) }}</p>
        </article>
        <article class="app-metric-card">
          <p class="app-metric-label">Access Points</p>
          <p class="app-metric-value">{{ formatNumber(summary.access_points_count || 0) }}</p>
        </article>
        <article class="app-metric-card">
          <p class="app-metric-label">Ledger Balance</p>
          <p class="app-metric-value">{{ formatCurrency(summary.available_balance || 0) }}</p>
        </article>
      </div>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[0.9fr,1.1fr]">
      <section class="app-card-strong p-7">
        <p class="app-kicker">Assigned Sites</p>
        <h2 class="mt-3 app-section-title">Your operating footprint</h2>
        <div class="mt-6 space-y-3">
          <article v-for="site in sites" :key="site.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
            <p class="text-base font-semibold text-slate-950">{{ site.name }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ site.slug }}</p>
          </article>
          <div v-if="!sites.length" class="app-empty">No sites are assigned yet.</div>
        </div>
      </section>

      <section class="app-table-shell">
        <div class="px-6 py-6">
          <p class="app-kicker">Recent Sessions</p>
          <h2 class="mt-2 app-section-title">Latest client activity</h2>
        </div>

        <div class="app-table-wrap">
          <table class="app-table">
            <thead>
              <tr>
                <th>Client</th>
                <th>Site</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="session in recentSessions" :key="session.id">
                <td>{{ session.client_name || 'Unknown client' }}</td>
                <td>{{ session.site_name || 'Unassigned' }}</td>
                <td>{{ session.plan_name || 'N/A' }}</td>
                <td>
                  <span class="app-badge" :class="session.session_status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'">
                    {{ session.session_status }}
                  </span>
                </td>
                <td>{{ formatCurrency(session.amount_paid) }}</td>
              </tr>
              <tr v-if="!recentSessions.length">
                <td colspan="5">
                  <div class="app-empty">No session activity yet.</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-2">
      <section class="app-card p-7">
        <p class="app-kicker">Recent Payments</p>
        <h2 class="mt-3 app-section-title">Revenue events</h2>
        <div class="mt-6 space-y-3">
          <article v-for="payment in recentPayments" :key="payment.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="font-semibold text-slate-950">{{ payment.plan_name || 'Unknown plan' }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ payment.site_name || 'Unassigned site' }} • {{ payment.reference_id }}</p>
              </div>
              <div class="text-left sm:text-right">
                <p class="font-semibold text-slate-950">{{ formatCurrency(payment.amount) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ payment.status }}</p>
              </div>
            </div>
          </article>
          <div v-if="!recentPayments.length" class="app-empty">No payments recorded yet.</div>
        </div>
      </section>

      <section class="app-card p-7">
        <p class="app-kicker">AP Sync Status</p>
        <h2 class="mt-3 app-section-title">Recent device visibility</h2>
        <div class="mt-6 space-y-3">
          <article v-for="accessPoint in recentAccessPoints" :key="accessPoint.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="font-semibold text-slate-950">{{ accessPoint.name || 'Unnamed AP' }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ accessPoint.site_name || 'Unassigned site' }}</p>
              </div>
              <div class="text-left sm:text-right">
                <p class="font-medium text-slate-950">{{ accessPoint.claim_status }}</p>
                <p :class="accessPoint.is_online ? 'mt-1 text-xs text-emerald-700' : 'mt-1 text-xs text-slate-500'">
                  {{ accessPoint.is_online ? 'Online' : 'Offline' }}
                </p>
              </div>
            </div>
          </article>
          <div v-if="!recentAccessPoints.length" class="app-empty">No access points are mapped to this operator yet.</div>
        </div>
      </section>
    </section>
  </MainLayout>
</template>
