<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

const props = defineProps({
  activeSessionsCount: Number,
  totalRevenue: String,
  mostPopularPlan: Object,
  analytics: Object,
  controllerSettings: Object,
  revenueTrend: Array,
  accessPoints: Array,
  siteSummary: Array,
  operators: Array,
});

const headlineStats = computed(() => [
  {
    label: 'Active Sessions',
    value: formatNumber(props.activeSessionsCount || 0),
    note: `${formatNumber(props.analytics?.total_sessions || 0)} total sessions tracked`,
    tone: 'sky',
    icon: 'wifi_find',
  },
  {
    label: 'Operators',
    value: formatNumber(props.analytics?.operators_count || 0),
    note: `${formatNumber(props.analytics?.operators_pending || 0)} awaiting approval`,
    tone: 'emerald',
    icon: 'groups',
  },
  {
    label: 'Tracked APs',
    value: formatNumber(props.analytics?.tracked_access_points || 0),
    note: `${formatNumber(props.analytics?.claimed_access_points || 0)} claimed by controller`,
    tone: 'slate',
    icon: 'router',
  },
]);

const controllerReady = computed(() => Boolean(props.analytics?.controller_configured));
const trendMax = computed(() => Math.max(...(props.revenueTrend || []).map((item) => Number(item.amount || 0)), 1));
const trendBars = computed(() => (props.revenueTrend || []).map((item) => ({
  ...item,
  normalizedHeight: Math.max(28, Math.round((Number(item.amount || 0) / trendMax.value) * 100)),
})));
</script>

<template>
  <Head title="Dashboard" />

  <MainLayout title="Admin Dashboard">
    <section class="grid gap-6 xl:grid-cols-[1.25fr,0.95fr]">
      <div class="app-card-dark relative overflow-hidden p-7 sm:p-9">
        <div class="absolute inset-x-6 bottom-6 top-[52%] z-0 flex items-end gap-3 overflow-hidden sm:inset-x-9">
          <div
            v-for="bar in trendBars"
            :key="bar.date"
            class="relative flex-1 rounded-t-[28px] bg-white/10"
            :style="{ height: `${bar.normalizedHeight}%` }"
          >
            <div class="absolute inset-x-0 top-0 h-14 rounded-t-[28px] bg-white/5" />
            <div class="absolute bottom-3 left-1/2 -translate-x-1/2 text-[10px] font-bold uppercase tracking-[0.22em] text-white/35">
              {{ bar.label }}
            </div>
          </div>
        </div>

        <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
          <div class="max-w-3xl">
            <p class="app-top-stat">
              <span class="material-symbols-outlined text-[16px]">monitoring</span>
              Revenue command center
            </p>
            <h1 class="mt-5 text-4xl font-extrabold tracking-[-0.06em] text-white sm:text-5xl">
              {{ formatCurrency(props.totalRevenue) }}
            </h1>
            <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">
              The admin surface is now structured around controller health, operator approvals, payout review, and site-level performance. No filler cards, no dead space.
            </p>
            <p class="mt-5 text-xs font-bold uppercase tracking-[0.24em] text-white/45">
              Last 7 days revenue trend
            </p>
          </div>

          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
            <div class="rounded-[22px] border border-white/10 bg-white/8 px-4 py-4 backdrop-blur-sm">
              <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Revenue Today</p>
              <p class="mt-3 text-2xl font-semibold tracking-[-0.04em] text-white">{{ formatCurrency(props.analytics?.revenue_today || 0) }}</p>
            </div>
            <div class="rounded-[22px] border border-white/10 bg-white/8 px-4 py-4 backdrop-blur-sm">
              <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Most Popular Plan</p>
              <p class="mt-3 text-lg font-semibold text-white">{{ props.mostPopularPlan?.name || 'No plan data' }}</p>
            </div>
          </div>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-1">
        <article
          v-for="stat in headlineStats"
          :key="stat.label"
          class="app-metric-card"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="app-metric-label">{{ stat.label }}</p>
              <p class="app-metric-value">{{ stat.value }}</p>
              <p class="app-metric-note">{{ stat.note }}</p>
            </div>
            <div
              class="flex h-12 w-12 items-center justify-center rounded-full"
              :class="{
                'bg-sky-100 text-sky-700': stat.tone === 'sky',
                'bg-emerald-100 text-emerald-700': stat.tone === 'emerald',
                'bg-slate-100 text-slate-700': stat.tone === 'slate',
              }"
            >
              <span class="material-symbols-outlined">{{ stat.icon }}</span>
            </div>
          </div>
        </article>
      </div>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[1.1fr,0.9fr]">
      <div class="app-card-strong p-7">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="app-kicker">Operations Summary</p>
            <h2 class="mt-3 app-section-title">Controller and commercial overview</h2>
          </div>
          <span :class="controllerReady ? 'app-badge bg-emerald-100 text-emerald-700' : 'app-badge bg-amber-100 text-amber-700'">
            {{ controllerReady ? 'Controller ready' : 'Controller missing' }}
          </span>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2">
          <div class="app-panel">
            <p class="app-metric-label">Sites / Locations</p>
            <p class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatNumber(props.analytics?.sites_count || 0) }}</p>
            <p class="mt-2 text-sm text-slate-500">{{ formatNumber(props.analytics?.unassigned_sessions || 0) }} sessions still unassigned</p>
          </div>
          <div class="app-panel">
            <p class="app-metric-label">Pending Payout Requests</p>
            <p class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatNumber(props.analytics?.pending_payout_requests || 0) }}</p>
            <p class="mt-2 text-sm text-slate-500">Manual payout review remains the default flow</p>
          </div>
          <div class="app-panel">
            <p class="app-metric-label">Pause-Ready Promos</p>
            <p class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-slate-950">{{ formatNumber(props.analytics?.pause_ready_promos || 0) }}</p>
            <p class="mt-2 text-sm text-slate-500">Anti-tethering enabled on {{ formatNumber(props.analytics?.anti_tethering_promos || 0) }} promos</p>
          </div>
          <div class="app-panel">
            <p class="app-metric-label">Controller Endpoint</p>
            <p class="mt-3 text-base font-semibold text-slate-950">
              {{ props.controllerSettings?.controller_name || 'No controller configured' }}
            </p>
            <p class="mt-2 break-all text-sm text-slate-500">{{ props.controllerSettings?.base_url || 'Save controller settings first' }}</p>
          </div>
        </div>
      </div>

      <div class="app-card p-7">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="app-kicker">Site Performance</p>
            <h2 class="mt-3 app-section-title">Regional revenue snapshot</h2>
          </div>
          <Link href="/admin/access-points" class="app-button-secondary px-4 py-2.5">
            Inspect APs
          </Link>
        </div>

        <div class="mt-8 space-y-3">
          <article
            v-for="site in props.siteSummary"
            :key="site.id"
            class="rounded-[22px] border border-slate-200/70 bg-white/80 px-5 py-4"
          >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="text-base font-semibold text-slate-950">{{ site.name }}</p>
                <p class="mt-1 text-sm text-slate-500">
                  {{ formatNumber(site.access_points_count || 0) }} APs • {{ formatNumber(site.active_sessions_count || 0) }} active sessions
                </p>
              </div>
              <p class="text-lg font-semibold tracking-[-0.03em] text-slate-950">{{ formatCurrency(site.revenue_total || 0) }}</p>
            </div>
          </article>

          <div v-if="!props.siteSummary?.length" class="app-empty">
            No controller-backed sites have been synced yet.
          </div>
        </div>
      </div>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[1.1fr,0.9fr]">
      <div class="app-table-shell">
        <div class="flex items-center justify-between gap-4 px-6 py-6">
          <div>
            <p class="app-kicker">Operators</p>
            <h2 class="mt-2 app-section-title">Approval and revenue watchlist</h2>
          </div>
          <Link href="/admin/operators" class="app-button-secondary px-4 py-2.5">Manage</Link>
        </div>

        <div class="app-table-wrap">
          <table class="app-table">
            <thead>
              <tr>
                <th>Operator</th>
                <th>Status</th>
                <th>Sites</th>
                <th>Revenue</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="operator in props.operators" :key="operator.id">
                <td>
                  <p class="font-semibold text-slate-950">{{ operator.business_name }}</p>
                  <p class="mt-1 text-xs text-slate-500">{{ operator.contact_name }} • {{ operator.email }}</p>
                </td>
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
                <td>{{ operator.sites.join(', ') || 'Unassigned' }}</td>
                <td>{{ formatCurrency(operator.revenue_total) }}</td>
                <td>{{ formatCurrency(operator.available_balance) }}</td>
              </tr>
              <tr v-if="!props.operators?.length">
                <td colspan="5">
                  <div class="app-empty">No operator records exist yet.</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="app-card p-7">
        <div class="flex items-center justify-between gap-4">
          <div>
            <p class="app-kicker">Access Point Inventory</p>
            <h2 class="mt-2 app-section-title">Top network edges</h2>
          </div>
          <Link href="/admin/access-points" class="app-button-secondary px-4 py-2.5">Open inventory</Link>
        </div>

        <div class="mt-6 space-y-3">
          <article
            v-for="ap in props.accessPoints"
            :key="ap.id"
            class="rounded-[22px] border border-slate-200/70 bg-white/80 px-5 py-4"
          >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="text-base font-semibold text-slate-950">{{ ap.name || ap.mac_address }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ ap.site_name || 'Unassigned site' }} • {{ ap.mac_address }}</p>
              </div>
              <div class="text-left sm:text-right">
                <span :class="ap.is_online ? 'app-badge bg-emerald-100 text-emerald-700' : 'app-badge bg-slate-100 text-slate-600'">
                  {{ ap.is_online ? 'Connected' : 'Offline' }}
                </span>
                <p class="mt-2 text-sm text-slate-500">{{ ap.claim_status }} • {{ formatNumber(ap.active_sessions_count || 0) }} active users</p>
              </div>
            </div>
          </article>

          <div v-if="!props.accessPoints?.length" class="app-empty">
            No access points are attributed yet.
          </div>
        </div>
      </div>
    </section>

    <section class="mt-8 flex flex-wrap gap-3">
      <Link href="/admin/controller" class="app-button-primary">
        <span class="material-symbols-outlined text-[18px]">settings_input_component</span>
        Controller Settings
      </Link>
      <Link href="/admin/operators" class="app-button-secondary">
        <span class="material-symbols-outlined text-[18px]">groups</span>
        Manage Operators
      </Link>
      <Link href="/admin/payout-requests" class="app-button-secondary">
        <span class="material-symbols-outlined text-[18px]">payments</span>
        Review Payouts
      </Link>
      <Link href="/admin/plans" class="app-button-secondary">
        <span class="material-symbols-outlined text-[18px]">sell</span>
        Manage Promos
      </Link>
    </section>
  </MainLayout>
</template>
