<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import AdminPagination from '@/Components/AdminPagination.vue';
import SessionHistoryDialog from '@/Components/SessionHistoryDialog.vue';
import SessionControllerCheckDialog from '@/Components/SessionControllerCheckDialog.vue';
import { formatNumber } from '@/utils/formatters';

const props = defineProps({
  releaseRuntime: {
    type: Object,
    required: true,
  },
  filters: {
    type: Object,
    required: true,
  },
  accessPoints: {
    type: Array,
    required: true,
  },
  sessions: {
    type: Object,
    required: true,
  },
  clientHistories: {
    type: Object,
    required: true,
  },
});

const historyOpen = ref(false);
const controllerCheckOpen = ref(false);
const selectedSession = ref(null);

const sessionRows = computed(() => props.sessions.data || []);
const activeCount = computed(() => sessionRows.value.filter((item) => item.is_active).length);
const paidCount = computed(() => sessionRows.value.filter((item) => item.payment_status === 'paid').length);
const needsAttentionCount = computed(() => sessionRows.value.filter((item) => controllerNeedsAttention(item)).length);

const activationStatusLabel = (status) => ({
  succeeded: 'Access enabled',
  pending: 'Queued',
  in_progress: 'Activating',
  failed: 'Failed',
  uncertain: 'Needs check',
  manual_required: 'Manual review',
}[status] || 'Not queued');

const activationTone = (status) => ({
  succeeded: 'bg-emerald-100 text-emerald-700',
  pending: 'bg-amber-100 text-amber-700',
  in_progress: 'bg-sky-100 text-sky-700',
  failed: 'bg-rose-100 text-rose-700',
  uncertain: 'bg-rose-100 text-rose-700',
  manual_required: 'bg-rose-100 text-rose-700',
}[status] || 'bg-slate-100 text-slate-600');

const paymentTone = (status) => ({
  paid: 'bg-emerald-100 text-emerald-700',
  pending: 'bg-amber-100 text-amber-700',
  awaiting_payment: 'bg-sky-100 text-sky-700',
  expired: 'bg-slate-100 text-slate-600',
  failed: 'bg-rose-100 text-rose-700',
}[status] || 'bg-slate-100 text-slate-600');

function controllerNeedsAttention(item) {
  return ['not_authorized_in_controller', 'reconcile_failed'].includes(item.last_reconcile_result)
    || Boolean(item.controller_check_message);
}

const openHistory = (item) => {
  selectedSession.value = item;
  historyOpen.value = true;
};

const openControllerCheck = (item) => {
  selectedSession.value = item;
  controllerCheckOpen.value = true;
};

const closeDialogs = () => {
  historyOpen.value = false;
  controllerCheckOpen.value = false;
};

const applyFilters = (event) => {
  const form = new FormData(event.target);
  const params = Object.fromEntries([...form.entries()].filter(([, value]) => String(value).trim() !== ''));

  router.get('/operator/sessions', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

const resetFilters = () => {
  router.get('/operator/sessions', {}, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

const goToPage = (page) => {
  router.get('/operator/sessions', {
    ...(props.filters || {}),
    page,
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

const historyRows = computed(() => {
  if (! selectedSession.value?.client_id) {
    return [];
  }

  return props.clientHistories[selectedSession.value.client_id] || [];
});
</script>

<template>
  <Head title="Operator Sessions" />

  <MainLayout title="Sessions">
    <section>
      <p class="app-kicker">Session Telemetry</p>
      <h1 class="mt-3 app-title">Client session ledger</h1>
      <p class="mt-4 app-subtitle">
        Operator view uses the same session logic as Admin, scoped to your assigned sites and claimed APs.
      </p>
    </section>

    <form class="mt-8 app-card p-5" @submit.prevent="applyFilters">
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <div>
          <label class="app-label">Date From</label>
          <input name="date_from" type="date" class="app-field" :value="filters.date_from" />
        </div>
        <div>
          <label class="app-label">Date To</label>
          <input name="date_to" type="date" class="app-field" :value="filters.date_to" />
        </div>
        <div>
          <label class="app-label">Client</label>
          <input name="client" type="search" class="app-field" placeholder="Name, phone, or MAC" :value="filters.client" />
        </div>
        <div>
          <label class="app-label">Access Point</label>
          <select name="access_point_id" class="app-field" :value="filters.access_point_id">
            <option value="">All APs</option>
            <option v-for="accessPoint in accessPoints" :key="accessPoint.id" :value="accessPoint.id">
              {{ accessPoint.name || accessPoint.mac_address }}
            </option>
          </select>
        </div>
        <div>
          <label class="app-label">Status</label>
          <select name="status" class="app-field" :value="filters.status">
            <option value="">All statuses</option>
            <option value="pending_payment">Pending payment</option>
            <option value="awaiting_payment">QR generated</option>
            <option value="paid">Paid</option>
            <option value="active">Active</option>
            <option value="expired">Expired</option>
            <option value="failed">Failed</option>
            <option value="release_failed">Release failed</option>
          </select>
        </div>
      </div>

      <div class="mt-5 flex flex-wrap gap-3">
        <button type="submit" class="app-button-primary">Apply filters</button>
        <button type="button" class="app-button-secondary" @click="resetFilters">Reset</button>
      </div>
    </form>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-rail-card">
        <p class="app-metric-label">Visible Sessions</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatNumber(props.sessions.total || sessionRows.length) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Active First</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatNumber(activeCount) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Paid Sessions</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatNumber(paidCount) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Needs Checking</p>
        <p class="mt-3 text-3xl font-semibold text-slate-950">{{ formatNumber(needsAttentionCount) }}</p>
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
        <table class="app-table app-table-compact">
          <thead>
            <tr>
              <th>ID</th>
              <th>Client</th>
              <th>Plan</th>
              <th>Payment</th>
              <th>Access Activation</th>
              <th>Time Left</th>
              <th>Ends</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in sessionRows"
              :key="item.id"
              :class="{ 'app-table-row-active': item.is_active }"
            >
              <td class="font-semibold text-slate-950">{{ item.id }}</td>
              <td>
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-slate-950">{{ item.client?.name || 'Unknown client' }}</p>
                    <span
                      v-if="item.is_active"
                      class="app-badge app-badge-compact bg-emerald-100 text-emerald-700"
                    >
                      Active
                    </span>
                  </div>
                  <p class="mt-1 text-[10px] text-slate-500">
                    {{ item.client?.phone_number || 'No phone' }} | {{ item.mac_address }}
                  </p>
                  <div class="mt-2 space-y-1 text-[11px] text-slate-500">
                    <p>{{ item.site?.name || 'No site assigned' }}</p>
                    <p>{{ item.access_point?.name || item.ap_name || 'No access point' }}<span v-if="item.ap_mac || item.access_point?.mac_address"> | {{ item.ap_mac || item.access_point?.mac_address }}</span></p>
                  </div>
                </div>
              </td>
              <td>
                <p class="font-medium text-slate-950">{{ item.plan?.name || '-' }}</p>
                <p v-if="item.ssid_name" class="mt-1 text-[11px] text-slate-500">{{ item.ssid_name }}</p>
              </td>
              <td>
                <span class="app-badge app-badge-compact" :class="paymentTone(item.payment_status)">
                  {{ item.payment_status }}
                </span>
              </td>
              <td>
                <div class="space-y-2">
                  <span class="app-badge app-badge-compact" :class="activationTone(item.release_status)">
                    {{ activationStatusLabel(item.release_status) }}
                  </span>
                  <p class="text-[11px] text-slate-500">{{ item.release_attempt_count || 0 }} attempts</p>
                  <p v-if="item.release_outcome_type" class="text-[11px] text-slate-500">{{ item.release_outcome_type }}</p>
                </div>
              </td>
              <td class="font-semibold text-slate-950">{{ item.remaining_time }}</td>
              <td class="text-[11px] text-slate-500">{{ item.end_time || '-' }}</td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <button
                    type="button"
                    class="app-button-secondary px-3 py-2 text-[11px]"
                    @click="openHistory(item)"
                  >
                    View History
                  </button>
                  <button
                    type="button"
                    class="rounded-full border px-3 py-2 text-[11px] font-semibold transition"
                    :class="controllerNeedsAttention(item)
                      ? 'border-amber-300 bg-amber-50 text-amber-900 hover:border-amber-400'
                      : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950'"
                    @click="openControllerCheck(item)"
                  >
                    Controller Check
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!sessionRows.length">
              <td colspan="8">
                <div class="app-empty">No client sessions match these filters.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminPagination
        :current-page="props.sessions.current_page || 1"
        :last-page="props.sessions.last_page || 1"
        :total="props.sessions.total || sessionRows.length"
        :from="props.sessions.from || 0"
        :to="props.sessions.to || sessionRows.length"
        @change="goToPage"
      />
    </section>

    <SessionHistoryDialog
      :show="historyOpen"
      :client="selectedSession?.client || null"
      :history="historyRows"
      @close="closeDialogs"
    />

    <SessionControllerCheckDialog
      :show="controllerCheckOpen"
      :session="selectedSession"
      @close="closeDialogs"
    />
  </MainLayout>
</template>
