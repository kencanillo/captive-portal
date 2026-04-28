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
const authorizationForm = ref({
  wifi_session_id: '',
  client_name: '',
  phone: '',
  mac_address: '',
  plan_id: '',
  manual_payment_mode: '',
  site_id: '',
  access_point_id: '',
  ap_name: '',
  ap_mac: '',
  ssid_name: '',
  radio_id: '',
  note: '',
});

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

const closeDialogs = () => {
  historyOpen.value = false;
};

const openAuthorizationModal = (item) => {
  selectedSession.value = item;
  authorizationForm.value = {
    wifi_session_id: item.id,
    client_name: item.client?.name || '',
    phone: item.client?.phone_number || '',
    mac_address: item.mac_address || '',
    plan_id: '',
    manual_payment_mode: '',
    site_id: item.site?.id || '',
    access_point_id: item.access_point?.id || '',
    ap_name: item.access_point?.name || item.ap_name || '',
    ap_mac: item.access_point?.mac_address || item.ap_mac || '',
    ssid_name: item.ssid_name || '',
    radio_id: item.radio_id || '',
    note: '',
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

const selectedPlan = computed(() => props.manualAuthorization.plans.find((plan) => String(plan.id) === String(authorizationForm.value.plan_id)) || null);
const expirationPreview = computed(() => {
  if (! selectedPlan.value) return '-';
  return new Date(Date.now() + (selectedPlan.value.duration_minutes * 60 * 1000)).toLocaleString();
});

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
                  <button
                    v-if="manualAuthorization.enabled"
                    type="button"
                    class="app-button-primary px-3 py-2 text-[11px]"
                    @click="openAuthorizationModal(item)"
                  >
                    Connect / Authorize Client
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!sessionRows.length">
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

    <div v-if="authorizationModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <div class="w-full max-w-2xl rounded-2xl bg-white p-6">
        <h3 class="text-lg font-semibold text-slate-950">Connect / Authorize Client</h3>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <div><label class="app-label">Client Name</label><input v-model="authorizationForm.client_name" class="app-field" type="text" /></div>
          <div><label class="app-label">Phone</label><input v-model="authorizationForm.phone" class="app-field" type="text" /></div>
          <div><label class="app-label">MAC Address</label><input v-model="authorizationForm.mac_address" class="app-field" type="text" /></div>
          <div>
            <label class="app-label">Plan</label>
            <select v-model="authorizationForm.plan_id" class="app-field">
              <option value="">Select plan</option>
              <option v-for="plan in manualAuthorization.plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
            </select>
          </div>
          <div>
            <label class="app-label">Authorization Mode</label>
            <select v-model="authorizationForm.manual_payment_mode" class="app-field">
              <option value="">Select mode</option>
              <option value="admin_approved">Admin Approved</option>
              <option value="manually_paid">Manually Paid</option>
            </select>
          </div>
          <div><label class="app-label">Site</label><input class="app-field" type="text" :value="selectedSession?.site?.name || 'N/A'" disabled /></div>
          <div><label class="app-label">Access Point</label><input class="app-field" type="text" :value="selectedSession?.access_point?.name || selectedSession?.ap_name || 'N/A'" disabled /></div>
          <div><label class="app-label">SSID</label><input class="app-field" type="text" :value="selectedSession?.ssid_name || 'N/A'" disabled /></div>
          <div><label class="app-label">Expiration Preview</label><input class="app-field" type="text" :value="expirationPreview" disabled /></div>
        </div>
        <div v-if="selectedPlan" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
          Plan duration: {{ selectedPlan.duration_minutes }} minutes • Price: ₱{{ selectedPlan.price }}
        </div>
        <div class="mt-4">
          <label class="app-label">Note / Reason (optional)</label>
          <textarea v-model="authorizationForm.note" class="app-field" rows="2" />
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
