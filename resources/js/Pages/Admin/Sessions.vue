<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import AdminPagination from '@/Components/AdminPagination.vue';
import SessionHistoryDialog from '@/Components/SessionHistoryDialog.vue';
import SvgIcon from '@/Components/SvgIcon.vue';
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
  clientHistories: {
    type: Object,
    required: true,
  },
  manualAuthorization: {
    type: Object,
    required: true,
  },
});

const historyOpen = ref(false);
const selectedSession = ref(null);
const authorizationModalOpen = ref(false);
const sessionStatusFilter = ref('all');
const sessionSearch = ref('');
const deauthTooltip = ref({
  visible: false,
  left: 0,
  top: 0,
  item: null,
});
const authorizationForm = ref({
  wifi_session_id: '',
  plan_id: '',
  manual_payment_mode: '',
});

const sessionRows = computed(() => props.sessions.data || []);
const filteredSessionRows = computed(() => {
  const query = sessionSearch.value.trim().toLowerCase();

  return sessionRows.value.filter((item) => {
    const matchesStatus = sessionStatusFilter.value === 'all'
      || (sessionStatusFilter.value === 'active' && item.is_active)
      || (sessionStatusFilter.value === 'expired' && item.session_status === 'expired')
      || (sessionStatusFilter.value === 'needs_omada' && controllerNeedsAttention(item))
      || (sessionStatusFilter.value === item.payment_status)
      || (sessionStatusFilter.value === item.release_status);

    if (!matchesStatus) return false;
    if (!query) return true;

    return [
      item.id,
      item.client?.name,
      item.client?.phone_number,
      item.mac_address,
      item.site?.name,
      item.access_point?.name,
      item.ap_name,
      item.plan?.name,
    ].filter(Boolean).join(' ').toLowerCase().includes(query);
  });
});
const activeCount = computed(() => filteredSessionRows.value.filter((item) => item.is_active).length);
const paidCount = computed(() => filteredSessionRows.value.filter((item) => item.payment_status === 'paid').length);
const needsAttentionCount = computed(() => filteredSessionRows.value.filter((item) => controllerNeedsAttention(item)).length);

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

const deauthorizationStatusLabel = (status) => ({
  pending: 'Deauth pending',
  failed: 'Deauth retrying',
  succeeded: 'Deauthorized',
  manual_required: 'Check Omada',
}[status] || 'No deauth state');

const deauthorizationTone = (status) => ({
  pending: 'bg-amber-100 text-amber-700',
  failed: 'bg-rose-100 text-rose-700',
  succeeded: 'bg-emerald-100 text-emerald-700',
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
    || ['failed', 'manual_required'].includes(item.controller_deauthorization_status)
    || Boolean(item.controller_check_message)
    || Boolean(item.manual_controller_deauthorization_required);
}

const openHistory = (item) => {
  selectedSession.value = item;
  historyOpen.value = true;
};

const closeDialogs = () => {
  historyOpen.value = false;
};

const showDeauthTooltip = (item, event) => {
  const rect = event.currentTarget.getBoundingClientRect();
  const tooltipWidth = Math.min(288, window.innerWidth - 24);
  const left = Math.min(
    Math.max(12, rect.right + 8),
    window.innerWidth - tooltipWidth - 12,
  );
  const top = Math.min(
    Math.max(12, rect.top - 8),
    window.innerHeight - 180,
  );

  deauthTooltip.value = {
    visible: true,
    left,
    top,
    item,
  };
};

const hideDeauthTooltip = () => {
  deauthTooltip.value.visible = false;
};

const openAuthorizationModal = (item) => {
  selectedSession.value = item;
  authorizationForm.value = {
    wifi_session_id: item.id,
    plan_id: '',
    manual_payment_mode: '',
  };
  authorizationModalOpen.value = true;
};

const submitManualAuthorization = () => {
  router.post(route('manual-authorizations.store'), authorizationForm.value, {
    preserveScroll: true,
    onSuccess: () => {
      authorizationModalOpen.value = false;
    },
  });
};

const goToPage = (page) => {
  router.get('/admin/sessions', { page }, {
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
  <Head title="Sessions" />

  <MainLayout title="WiFi Sessions">
    <section>
      <p class="app-kicker">Session Telemetry</p>
      <h1 class="mt-3 app-title">Client session ledger</h1>
      <p class="mt-4 app-subtitle">
        Active sessions stay at the top. Support actions stay in the action column. Client identity, site, and access point live in one compact block instead of wasting half the table.
      </p>
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-rail-card">
        <p class="app-metric-label">Visible Sessions</p>
        <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatNumber(props.sessions.total || sessionRows.length) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Active First</p>
        <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatNumber(activeCount) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Paid Sessions</p>
        <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatNumber(paidCount) }}</p>
      </article>
      <article class="app-rail-card">
        <p class="app-metric-label">Needs Checking</p>
        <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatNumber(needsAttentionCount) }}</p>
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

    <section class="app-table-shell mt-8 overflow-visible">
      <div class="px-6 py-6">
        <p class="app-kicker">Client Activity</p>
        <h2 class="mt-2 app-section-title">Current session list</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-[220px,1fr]">
          <select v-model="sessionStatusFilter" class="app-field">
            <option value="all">All sessions</option>
            <option value="active">Connected active</option>
            <option value="expired">Expired</option>
            <option value="needs_omada">Needs Omada check</option>
            <option value="paid">Paid</option>
            <option value="pending">Pending payment</option>
            <option value="failed">Failed</option>
          </select>
          <input
            v-model="sessionSearch"
            class="app-field"
            type="search"
            placeholder="Search client, phone, MAC, site, AP, or plan"
          />
        </div>
      </div>

      <div class="app-table-wrap md:overflow-visible">
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
              v-for="item in filteredSessionRows"
              :key="item.id"
              :class="{ 'app-table-row-active': item.is_active }"
            >
              <td class="font-semibold text-slate-950">{{ item.id }}</td>
              <td>
                <div class="flex items-start gap-3">
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
                      <p>{{ item.access_point?.name || item.ap_name || 'No access point' }}<span v-if="item.ap_mac"> • {{ item.ap_mac }}</span></p>
                    </div>
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
                <div class="space-y-1 text-[8px] leading-tight">
                  <span class="app-badge app-badge-compact whitespace-nowrap !px-2 !py-0.5 !text-[8px] !tracking-[0.14em]" :class="activationTone(item.release_status)">
                    {{ activationStatusLabel(item.release_status) }}
                  </span>
                  <p class="text-[8px] text-slate-500">{{ item.release_attempt_count || 0 }} attempts</p>
                  <p v-if="item.release_outcome_type" class="text-[8px] text-slate-500">{{ item.release_outcome_type }}</p>
                  <div v-if="item.controller_deauthorization_status" class="space-y-1">
                    <span class="inline-flex max-w-full items-center align-middle">
                      <button
                        v-if="item.controller_deauthorization_last_error || item.manual_controller_deauthorization_required"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 py-1 text-[8px] font-bold uppercase leading-none tracking-[0.14em] shadow-sm transition"
                        :class="deauthorizationTone(item.controller_deauthorization_status)"
                        title="Omada deauthorization details"
                        @mouseenter="showDeauthTooltip(item, $event)"
                        @mouseleave="hideDeauthTooltip"
                        @focus="showDeauthTooltip(item, $event)"
                        @blur="hideDeauthTooltip"
                      >
                        <span>{{ deauthorizationStatusLabel(item.controller_deauthorization_status) }}</span>
                        <SvgIcon name="info" class="h-3 w-3 shrink-0" />
                      </button>
                      <span
                        v-else
                        class="app-badge app-badge-compact whitespace-nowrap !px-2 !py-0.5 !text-[8px] !tracking-[0.14em]"
                        :class="deauthorizationTone(item.controller_deauthorization_status)"
                      >
                        {{ deauthorizationStatusLabel(item.controller_deauthorization_status) }}
                      </span>
                    </span>
                    <p class="text-[8px] text-slate-500">
                      {{ item.controller_deauthorization_attempt_count || 0 }} deauth attempts
                    </p>
                    <p v-if="item.controller_deauthorization_next_attempt_at" class="text-[8px] text-slate-500">
                      Next retry {{ item.controller_deauthorization_next_attempt_at }}
                    </p>
                  </div>
                </div>
              </td>
              <td class="font-semibold text-slate-950">{{ item.remaining_time }}</td>
              <td class="text-[11px] text-slate-500">{{ item.end_time || '-' }}</td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <Link
                    v-if="item.payment_status === 'paid' && !item.is_active && ['failed', 'uncertain', 'manual_required'].includes(item.release_status)"
                    as="button"
                    method="post"
                    :href="`/admin/sessions/${item.id}/retry-release`"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                    title="Retry Activation"
                  >
                    <SvgIcon name="pending" class="h-5 w-5" />
                  </Link>
                  <button
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-700 transition hover:border-slate-300 hover:text-slate-950"
                    title="View History"
                    @click="openHistory(item)"
                  >
                    <SvgIcon name="info" class="h-5 w-5" />
                  </button>
                  <button
                    v-if="manualAuthorization.enabled"
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-950 text-white transition hover:bg-slate-800"
                    title="Connect / Authorize Client"
                    @click="openAuthorizationModal(item)"
                  >
                    <SvgIcon name="verified" class="h-5 w-5" />
                  </button>
                  <Link
                    v-if="item.payment_status === 'paid' && !item.is_active && ['failed', 'uncertain', 'manual_required'].includes(item.release_status)"
                    as="button"
                    method="post"
                    :href="`/admin/sessions/${item.id}/retry-release`"
                    class="app-button-secondary px-3 py-2 text-[11px]"
                  >
                    Retry Activation
                  </Link>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredSessionRows.length">
              <td colspan="8">
                <div class="app-empty">No WiFi sessions are available in this dataset.</div>
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

    <Teleport to="body">
      <div
        v-if="deauthTooltip.visible && deauthTooltip.item"
        class="pointer-events-none fixed z-[10000] w-[min(18rem,calc(100vw-1.5rem))] rounded-xl border border-rose-100 bg-white p-3 text-left text-[11px] leading-5 text-rose-700 shadow-2xl ring-1 ring-rose-100/70"
        :style="{ left: `${deauthTooltip.left}px`, top: `${deauthTooltip.top}px` }"
      >
        <span v-if="deauthTooltip.item.controller_deauthorization_last_error" class="block">
          {{ deauthTooltip.item.controller_deauthorization_last_error }}
        </span>
        <span v-if="deauthTooltip.item.manual_controller_deauthorization_required" class="mt-2 block font-semibold">
          Omada still needs manual verification. Disconnect/reconnect the client, then unauthorize it.
        </span>
      </div>
    </Teleport>

    <div v-if="authorizationModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <div class="w-full max-w-2xl rounded-2xl bg-white p-6">
        <h3 class="text-lg font-semibold text-slate-950">Connect / Authorize Client</h3>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <div>
            <label class="app-label">Plan</label>
            <select v-model="authorizationForm.plan_id" class="app-field">
              <option value="">Select plan</option>
              <option v-for="plan in manualAuthorization.plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
            </select>
          </div>
          <div>
            <label class="app-label">Authorization Type</label>
            <select v-model="authorizationForm.manual_payment_mode" class="app-field">
              <option value="">Select type</option>
              <option value="admin_approved">Admin Approved</option>
              <option value="manually_paid">Manually Paid</option>
            </select>
          </div>
        </div>
        <div class="mt-5 flex justify-end gap-3">
          <button type="button" class="app-button-secondary" @click="authorizationModalOpen = false">Cancel</button>
          <button
            type="button"
            class="app-button-primary"
            :disabled="!authorizationForm.plan_id || !authorizationForm.manual_payment_mode"
            @click="submitManualAuthorization"
          >
            Authorize
          </button>
        </div>
      </div>
    </div>
  </MainLayout>
</template>
