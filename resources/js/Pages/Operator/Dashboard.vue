<script setup>
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

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
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Assigned sites</p>
        <p class="mt-2 text-2xl font-bold">{{ summary.sites_count || 0 }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Access points</p>
        <p class="mt-2 text-2xl font-bold">{{ summary.access_points_count || 0 }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Active sessions</p>
        <p class="mt-2 text-2xl font-bold">{{ summary.active_sessions_count || 0 }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Completed payments</p>
        <p class="mt-2 text-2xl font-bold">{{ summary.completed_payments_count || 0 }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Available balance</p>
        <p class="mt-2 text-2xl font-bold">₱{{ summary.available_balance || '0.00' }}</p>
      </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr,1.1fr]">
      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Assigned sites</h2>
        <div class="mt-4 space-y-3">
          <article v-for="site in sites" :key="site.id" class="rounded-md border border-slate-200 px-4 py-3">
            <p class="font-medium text-slate-900">{{ site.name }}</p>
            <p class="text-sm text-slate-500">{{ site.slug }}</p>
          </article>
          <p v-if="!sites.length" class="text-sm text-slate-500">No sites are assigned yet.</p>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Recent sessions</h2>
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-200 text-slate-500">
                <th class="px-2 py-2">Client</th>
                <th class="px-2 py-2">Site</th>
                <th class="px-2 py-2">Plan</th>
                <th class="px-2 py-2">Status</th>
                <th class="px-2 py-2">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="session in recentSessions" :key="session.id" class="border-b border-slate-100">
                <td class="px-2 py-3">{{ session.client_name || 'Unknown client' }}</td>
                <td class="px-2 py-3">{{ session.site_name || 'Unassigned' }}</td>
                <td class="px-2 py-3">{{ session.plan_name || 'N/A' }}</td>
                <td class="px-2 py-3">{{ session.session_status }}</td>
                <td class="px-2 py-3">₱{{ session.amount_paid }}</td>
              </tr>
              <tr v-if="!recentSessions.length">
                <td colspan="5" class="px-2 py-6 text-center text-slate-500">No session activity yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Recent payments</h2>
        <div class="mt-4 space-y-3">
          <article v-for="payment in recentPayments" :key="payment.id" class="rounded-md border border-slate-200 px-4 py-3">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-slate-900">{{ payment.plan_name || 'Unknown plan' }}</p>
                <p class="text-sm text-slate-500">{{ payment.site_name || 'Unassigned site' }} · {{ payment.reference_id }}</p>
              </div>
              <div class="text-right">
                <p class="font-semibold text-slate-900">₱{{ payment.amount }}</p>
                <p class="text-xs text-slate-500">{{ payment.status }}</p>
              </div>
            </div>
          </article>
          <p v-if="!recentPayments.length" class="text-sm text-slate-500">No payments recorded yet.</p>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Recent AP sync status</h2>
        <div class="mt-4 space-y-3">
          <article v-for="accessPoint in recentAccessPoints" :key="accessPoint.id" class="rounded-md border border-slate-200 px-4 py-3">
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="font-medium text-slate-900">{{ accessPoint.name || 'Unnamed AP' }}</p>
                <p class="text-sm text-slate-500">{{ accessPoint.site_name || 'Unassigned site' }}</p>
              </div>
              <div class="text-right text-sm">
                <p>{{ accessPoint.claim_status }}</p>
                <p :class="accessPoint.is_online ? 'text-emerald-700' : 'text-slate-500'">
                  {{ accessPoint.is_online ? 'Online' : 'Offline' }}
                </p>
              </div>
            </div>
          </article>
          <p v-if="!recentAccessPoints.length" class="text-sm text-slate-500">No access points mapped to this operator yet.</p>
        </div>
      </section>
    </div>
  </MainLayout>
</template>
