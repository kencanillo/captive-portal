<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  activeSessionsCount: Number,
  totalRevenue: String,
  mostPopularPlan: Object,
  analytics: Object,
  controllerSettings: Object,
  accessPoints: Array,
  siteSummary: Array,
});
</script>

<template>
  <Head title="Dashboard" />

  <MainLayout title="Admin Dashboard">
    <div class="grid gap-4 lg:grid-cols-6">
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Active sessions</p>
        <p class="mt-2 text-2xl font-bold">{{ props.activeSessionsCount }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Total revenue</p>
        <p class="mt-2 text-2xl font-bold">₱{{ props.totalRevenue }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Most popular plan</p>
        <p class="mt-2 text-base font-semibold">{{ props.mostPopularPlan?.name || 'N/A' }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Tracked APs</p>
        <p class="mt-2 text-2xl font-bold">{{ props.analytics?.tracked_access_points || 0 }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Claimed APs</p>
        <p class="mt-2 text-2xl font-bold">{{ props.analytics?.claimed_access_points || 0 }}</p>
      </div>
      <div class="rounded-lg bg-white p-5 shadow">
        <p class="text-sm text-slate-500">Pending APs</p>
        <p class="mt-2 text-2xl font-bold">{{ props.analytics?.pending_access_points || 0 }}</p>
      </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1.4fr,1fr]">
      <div class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold">Pilot readiness</h2>
        <ul class="mt-3 space-y-2 text-sm text-slate-700">
          <li>Controller configured: {{ props.analytics?.controller_configured ? 'Yes' : 'No' }}</li>
          <li>Sites / locations: {{ props.analytics?.sites_count || 0 }}</li>
          <li>Unassigned sessions: {{ props.analytics?.unassigned_sessions || 0 }}</li>
          <li>Pause-ready promos: {{ props.analytics?.pause_ready_promos || 0 }}</li>
          <li>Anti-tethering promos: {{ props.analytics?.anti_tethering_promos || 0 }}</li>
          <li>Revenue today: ₱{{ Number(props.analytics?.revenue_today || 0).toFixed(2) }}</li>
          <li>Total sessions: {{ props.analytics?.total_sessions || 0 }}</li>
        </ul>
      </div>

      <div class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold">Controller summary</h2>
        <div v-if="props.controllerSettings" class="mt-3 space-y-2 text-sm text-slate-700">
          <p><span class="font-medium">Name:</span> {{ props.controllerSettings.controller_name }}</p>
          <p><span class="font-medium">URL:</span> {{ props.controllerSettings.base_url }}</p>
          <p><span class="font-medium">Site:</span> {{ props.controllerSettings.site_name || props.controllerSettings.site_identifier || 'Not set' }}</p>
          <p><span class="font-medium">Portal:</span> {{ props.controllerSettings.portal_base_url || 'Not set' }}</p>
        </div>
        <p v-else class="mt-3 text-sm text-amber-700">
          No controller has been configured yet.
        </p>
      </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[2fr,1fr]">
      <div class="rounded-lg bg-white p-5 shadow">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold">Access point inventory</h2>
          <span class="text-xs text-slate-500">Claim state and policy flags now matter more than raw session volume.</span>
        </div>

        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b">
                <th class="px-2 py-2">AP</th>
                <th class="px-2 py-2">Site</th>
                <th class="px-2 py-2">Claim</th>
                <th class="px-2 py-2">SSID</th>
                <th class="px-2 py-2">Policies</th>
                <th class="px-2 py-2">Live Users</th>
                <th class="px-2 py-2">Revenue</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ap in props.accessPoints" :key="ap.id" class="border-b border-slate-100">
                <td class="px-2 py-2">
                  <p class="font-medium text-slate-900">{{ ap.name || ap.mac_address }}</p>
                  <p class="text-xs text-slate-500">{{ ap.mac_address }}</p>
                </td>
                <td class="px-2 py-2">{{ ap.site_name || 'Unassigned' }}</td>
                <td class="px-2 py-2">
                  <span class="rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="{
                      'bg-emerald-100 text-emerald-700': ap.claim_status === 'claimed',
                      'bg-amber-100 text-amber-700': ap.claim_status === 'pending',
                      'bg-rose-100 text-rose-700': ap.claim_status === 'error',
                      'bg-slate-200 text-slate-700': ap.claim_status === 'unclaimed',
                    }">
                    {{ ap.claim_status }}
                  </span>
                </td>
                <td class="px-2 py-2">{{ ap.custom_ssid || 'Not set' }}</td>
                <td class="px-2 py-2">
                  <p>Pause: {{ ap.allow_client_pause ? 'On' : 'Off' }}</p>
                  <p>No tethering: {{ ap.block_tethering ? 'On' : 'Off' }}</p>
                </td>
                <td class="px-2 py-2">{{ ap.active_sessions_count }}</td>
                <td class="px-2 py-2">
                  <p>Today: ₱{{ Number(ap.revenue_today || 0).toFixed(2) }}</p>
                  <p>Total: ₱{{ Number(ap.revenue_total || 0).toFixed(2) }}</p>
                </td>
              </tr>
              <tr v-if="!props.accessPoints?.length">
                <td colspan="7" class="px-2 py-6 text-center text-slate-500">No access points have been attributed yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold">Site summary</h2>

        <div class="mt-4 space-y-3">
          <article v-for="site in props.siteSummary" :key="site.id" class="rounded-md border border-slate-200 px-4 py-3">
            <p class="font-medium text-slate-900">{{ site.name }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ site.access_points_count }} APs · {{ site.active_sessions_count }} active users</p>
            <p class="mt-2 text-sm font-semibold text-emerald-700">₱{{ Number(site.revenue_total || 0).toFixed(2) }}</p>
          </article>
          <p v-if="!props.siteSummary?.length" class="text-sm text-slate-500">No sites have been detected yet.</p>
        </div>
      </div>
    </div>

    <div class="mt-6 flex gap-3">
      <Link href="/admin/controller" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Controller Settings</Link>
      <Link href="/admin/access-points" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Manage Access Points</Link>
      <Link href="/admin/plans" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Manage Plans</Link>
      <Link href="/admin/sessions" class="rounded-md bg-slate-700 px-4 py-2 text-sm font-semibold text-white">View Sessions</Link>
      <Link href="/admin/payments" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">View Payments</Link>
    </div>
  </MainLayout>
</template>
