<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

const props = defineProps({
  releaseRuntime: {
    type: Object,
    required: true,
  },
  sessions: {
    type: Object,
    required: true,
  },
});

const sessionRows = computed(() => props.sessions.data || []);
const activeCount = computed(() => sessionRows.value.filter((item) => item.is_active).length);
const paidCount = computed(() => sessionRows.value.filter((item) => item.payment_status === 'paid').length);
const siteCount = computed(() => new Set(sessionRows.value.map((item) => item.site?.name).filter(Boolean)).size);

const activationStatusLabel = (status) => ({
  succeeded: 'Access enabled',
  pending: 'Queued',
  in_progress: 'Activating access',
  failed: 'Activation failed',
  uncertain: 'Needs controller check',
  manual_required: 'Needs manual support',
}[status] || 'Not queued');

const reconcileStatusLabel = (status) => ({
  authorized_in_controller: 'Controller confirms active access',
  not_authorized_in_controller: 'Controller shows no active access',
  reconcile_failed: 'Controller check failed',
}[status] || 'Not checked yet');
</script>

<template>
  <Head title="Sessions" />

  <MainLayout title="WiFi Sessions">
    <section>
      <p class="app-kicker">Session Telemetry</p>
      <h1 class="mt-3 app-title">Live session ledger</h1>
      <p class="mt-4 app-subtitle">
        This is the operational table for client sessions, AP attribution, plan usage, and payment state. Keep it scan-heavy and readable. Operators and support rely on this page under pressure.
      </p>
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-metric-card">
        <p class="app-metric-label">Visible Sessions</p>
        <p class="app-metric-value">{{ formatNumber(sessionRows.length) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Active Right Now</p>
        <p class="app-metric-value">{{ formatNumber(activeCount) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Paid Sessions</p>
        <p class="app-metric-value">{{ formatNumber(paidCount) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Sites Visible</p>
        <p class="app-metric-value">{{ formatNumber(siteCount) }}</p>
      </article>
    </section>

    <section
      v-if="releaseRuntime.degraded"
      class="mt-6 rounded-[24px] border border-rose-200 bg-rose-50 px-6 py-5 text-sm text-rose-800"
    >
      <p class="font-semibold text-rose-950">Access activation looks degraded.</p>
      <p class="mt-2">
        Sessions needing activation: {{ formatNumber(releaseRuntime.outstanding_release_count || 0) }}.
        Worker heartbeat: {{ releaseRuntime.job_heartbeat_at || 'missing' }}.
        Recovery check heartbeat: {{ releaseRuntime.reconcile_heartbeat_at || 'missing' }}.
      </p>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Client Activity</p>
        <h2 class="mt-2 app-section-title">Current session list</h2>
      </div>

      <div class="app-table-wrap">
        <table class="app-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Client</th>
              <th>Site</th>
              <th>Access Point</th>
              <th>SSID</th>
              <th>Plan</th>
              <th>Payment</th>
              <th>Access Activation</th>
              <th>Controller Check</th>
              <th>Active</th>
              <th>Time Left</th>
              <th>Ends</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in sessionRows" :key="item.id">
              <td class="font-semibold text-slate-950">{{ item.id }}</td>
              <td>
                <p class="font-semibold text-slate-950">{{ item.client?.name || 'Unknown client' }}</p>
                <p v-if="item.client?.phone_number" class="mt-1 text-xs text-slate-500">{{ item.client.phone_number }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.mac_address }}</p>
              </td>
              <td>{{ item.site?.name || '-' }}</td>
              <td>
                <p class="font-medium text-slate-950">{{ item.access_point?.name || item.ap_name || '-' }}</p>
                <p v-if="item.ap_mac" class="mt-1 text-xs text-slate-500">{{ item.ap_mac }}</p>
              </td>
              <td>{{ item.ssid_name || '-' }}</td>
              <td>{{ item.plan?.name || '-' }}</td>
              <td>
                <span
                  class="app-badge"
                  :class="item.payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : item.payment_status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'"
                >
                  {{ item.payment_status }}
                </span>
              </td>
              <td>
                <div class="space-y-2">
                  <span
                    class="app-badge"
                    :class="item.release_status === 'succeeded'
                      ? 'bg-emerald-100 text-emerald-700'
                      : item.release_status === 'pending' || item.release_status === 'in_progress'
                        ? 'bg-amber-100 text-amber-700'
                        : item.release_status === 'uncertain' || item.release_status === 'manual_required'
                          ? 'bg-rose-100 text-rose-700'
                          : 'bg-slate-100 text-slate-600'"
                  >
                    {{ activationStatusLabel(item.release_status) }}
                  </span>
                  <p v-if="item.release_outcome_type" class="text-xs text-slate-600">
                    {{ item.release_outcome_type }}
                  </p>
                  <p class="text-xs text-slate-500">
                    {{ item.release_attempt_count || 0 }} attempts
                  </p>
                  <p v-if="item.release_stuck_at" class="text-xs font-semibold text-rose-700">
                    Stuck since {{ item.release_stuck_at }}
                  </p>
                  <p v-if="item.last_release_error" class="max-w-[16rem] text-xs text-rose-600">
                    {{ item.last_release_error }}
                  </p>
                </div>
              </td>
              <td>
                <div class="space-y-2">
                  <p class="text-xs text-slate-600">
                    {{ reconcileStatusLabel(item.last_reconcile_result) }}
                  </p>
                  <p class="text-xs text-slate-500">
                    {{ item.reconcile_attempt_count || 0 }} checks
                  </p>
                  <p v-if="item.last_reconciled_at" class="text-xs text-slate-500">
                    {{ item.last_reconciled_at }}
                  </p>
                </div>
              </td>
              <td>{{ item.is_active ? 'Yes' : 'No' }}</td>
              <td class="font-semibold text-slate-950">{{ item.remaining_time }}</td>
              <td>{{ item.end_time || '-' }}</td>
              <td>
                <div class="flex flex-col gap-2">
                  <Link
                    v-if="item.payment_status === 'paid' && !item.is_active && ['failed', 'uncertain', 'manual_required'].includes(item.release_status)"
                    as="button"
                    method="post"
                    :href="`/admin/sessions/${item.id}/retry-release`"
                    class="rounded-full border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700 transition hover:border-slate-950 hover:text-slate-950"
                  >
                    Retry activation
                  </Link>
                  <Link
                    v-if="item.payment_status === 'paid' && !item.is_active && ['uncertain', 'manual_required', 'in_progress'].includes(item.release_status)"
                    as="button"
                    method="post"
                    :href="`/admin/sessions/${item.id}/reconcile-release`"
                    class="rounded-full border border-amber-300 px-3 py-1 text-xs font-semibold text-amber-800 transition hover:border-amber-500 hover:text-amber-950"
                  >
                    Check controller access
                  </Link>
                  <span v-if="item.payment_status !== 'paid' || item.is_active" class="text-xs text-slate-400">-</span>
                </div>
              </td>
            </tr>
            <tr v-if="!sessionRows.length">
              <td colspan="13">
                <div class="app-empty">No WiFi sessions are available in this dataset.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </MainLayout>
</template>
