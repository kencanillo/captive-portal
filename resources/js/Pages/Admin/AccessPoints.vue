<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import SvgIcon from '@/Components/SvgIcon.vue';
import MainLayout from '@/Layouts/MainLayout.vue';
import AdminMiniLineChart from '@/Components/AdminMiniLineChart.vue';
import AdminStatusTooltip from '@/Components/AdminStatusTooltip.vue';
import AdminPagination from '@/Components/AdminPagination.vue';

const props = defineProps({
  syncConfigured: Boolean,
  syncHealth: {
    type: Object,
    required: true,
  },
  healthRuntime: {
    type: Object,
    required: true,
  },
  billingRuntime: {
    type: Object,
    required: true,
  },
  webhookCapabilityVerdict: {
    type: String,
    required: true,
  },
  statusSummary: {
    type: Object,
    required: true,
  },
  operators: {
    type: Array,
    required: true,
  },
  sites: {
    type: Array,
    required: true,
  },
  accessPoints: {
    type: Array,
    required: true,
  },
});

const correctionForms = reactive({});
const reversalForms = reactive({});
const resolutionForms = reactive({});
const groupPages = reactive({});
const accessPointStatusFilter = ref('all');
const accessPointSearch = ref('');
const perPage = 20;

const filteredAccessPoints = computed(() => {
  const query = accessPointSearch.value.trim().toLowerCase();

  return props.accessPoints.filter((accessPoint) => {
    const matchesStatus = accessPointStatusFilter.value === 'all'
      || accessPoint.status_label === accessPointStatusFilter.value
      || (accessPointStatusFilter.value === 'attention' && ['Heartbeat Missed', 'Disconnected', 'Stale Unknown', 'Failed'].includes(accessPoint.status_label))
      || (accessPointStatusFilter.value === 'claimed' && accessPoint.claim_status === 'claimed')
      || (accessPointStatusFilter.value === 'unclaimed' && accessPoint.claim_status !== 'claimed');

    if (!matchesStatus) return false;
    if (!query) return true;

    return [
      accessPoint.name,
      accessPoint.mac_address,
      accessPoint.serial_number,
      accessPoint.model,
      accessPoint.vendor,
      accessPoint.site?.name,
      accessPoint.site_name,
      accessPoint.claimed_by_operator?.business_name,
      accessPoint.claim_status,
      accessPoint.billing_state,
    ].filter(Boolean).join(' ').toLowerCase().includes(query);
  });
});

const groupedAccessPoints = computed(() => {
  const groups = new Map([
    ['Connected', []],
    ['Heartbeat Missed', []],
    ['Disconnected', []],
    ['Stale Unknown', []],
    ['Pending', []],
    ['Failed', []],
    ['Unknown', []],
  ]);

  filteredAccessPoints.value.forEach((accessPoint) => {
    const label = groups.has(accessPoint.status_label) ? accessPoint.status_label : 'Unknown';
    groups.get(label).push(accessPoint);
  });

  return Object.fromEntries([...groups.entries()].filter(([, items]) => items.length));
});

const paginatedGroups = computed(() => Object.entries(groupedAccessPoints.value).map(([label, items]) => {
  const currentPage = Math.min(groupPages[label] || 1, Math.max(1, Math.ceil(items.length / perPage)));
  const start = (currentPage - 1) * perPage;

  return {
    label,
    currentPage,
    total: items.length,
    from: items.length ? start + 1 : 0,
    to: Math.min(start + perPage, items.length),
    lastPage: Math.max(1, Math.ceil(items.length / perPage)),
    items: items.slice(start, start + perPage),
  };
}));

const summarySeries = computed(() => ([
  { label: 'Connected', value: props.statusSummary.connected || 0, color: '#34d399' },
  { label: 'Pending', value: props.statusSummary.pending || 0, color: '#38bdf8' },
  { label: 'Attention', value: props.statusSummary.attention || 0, color: '#f59e0b' },
  { label: 'Owned', value: props.statusSummary.claimed || 0, color: '#6366f1' },
]));

const billingSeries = computed(() => ([
  { label: 'Billed', value: props.statusSummary.billed || 0, color: '#34d399' },
  { label: 'Blocked', value: props.statusSummary.blocked_billing || 0, color: '#f59e0b' },
  { label: 'Review', value: props.statusSummary.billing_manual_review || 0, color: '#fb7185' },
]));

const syncAccessPoints = () => {
  router.post('/admin/access-points/sync', {}, {
    preserveScroll: true,
  });
};

const badgeClass = (status) => ({
  'bg-emerald-100 text-emerald-700': status === 'Connected',
  'bg-sky-100 text-sky-700': status === 'Pending',
  'bg-rose-100 text-rose-700': status === 'Failed',
  'bg-amber-100 text-amber-700': status === 'Heartbeat Missed',
  'bg-orange-100 text-orange-700': status === 'Stale Unknown',
  'bg-slate-100 text-slate-600': ['Disconnected', 'Unknown'].includes(status),
});

const toggleCorrection = (accessPointId) => {
  if (! correctionForms[accessPointId]) {
    correctionForms[accessPointId] = {
      open: true,
      operator_id: '',
      site_id: '',
      correction_reason: '',
      notes: '',
    };

    return;
  }

  correctionForms[accessPointId].open = !correctionForms[accessPointId].open;
};

const sitesForOperator = (operatorId) => props.sites.filter((site) => String(site.operator_id) === String(operatorId));

const submitCorrection = (accessPointId) => {
  const form = correctionForms[accessPointId];

  router.post(`/admin/access-points/${accessPointId}/correct-ownership`, {
    operator_id: form.operator_id,
    site_id: form.site_id,
    correction_reason: form.correction_reason,
    notes: form.notes,
  }, {
    preserveScroll: true,
  });
};

const toggleReversal = (accessPointId) => {
  if (! reversalForms[accessPointId]) {
    reversalForms[accessPointId] = {
      open: true,
      reason: '',
      notes: '',
    };

    return;
  }

  reversalForms[accessPointId].open = !reversalForms[accessPointId].open;
};

const submitReversal = (accessPointId) => {
  const form = reversalForms[accessPointId];

  router.post(`/admin/access-points/${accessPointId}/reverse-connection-fee`, {
    reason: form.reason,
    notes: form.notes,
  }, {
    preserveScroll: true,
  });
};

const toggleResolution = (accessPointId, action) => {
  if (! resolutionForms[accessPointId]) {
    resolutionForms[accessPointId] = {
      open: true,
      action,
      reason: '',
      notes: '',
    };

    return;
  }

  if (! resolutionForms[accessPointId].open || resolutionForms[accessPointId].action !== action) {
    resolutionForms[accessPointId].open = true;
    resolutionForms[accessPointId].action = action;

    return;
  }

  resolutionForms[accessPointId].open = !resolutionForms[accessPointId].open;
};

const submitResolution = (accessPointId) => {
  const form = resolutionForms[accessPointId];

  router.post(`/admin/access-points/${accessPointId}/resolve-billing-incident`, {
    action: form.action,
    reason: form.reason,
    notes: form.notes,
  }, {
    preserveScroll: true,
  });
};

const resolutionActionLabel = (action) => ({
  confirm_eligibility: 'Confirm eligibility',
  authorize_repost: 'Authorize repost',
}[action] || 'Resolve incident');

const changeGroupPage = (label, page) => {
  groupPages[label] = page;
};
</script>

<template>
  <Head title="Access Points" />

  <MainLayout title="Access Points">
    <section class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <p class="app-kicker">Network Inventory</p>
        <h1 class="mt-3 app-title">Controller-backed access points</h1>
        <p class="mt-4 app-subtitle">
          This page shows the synced device inventory, ownership state, billing state, and health signals for access points. Use it to audit the fleet, clean up ownership, and confirm what needs follow-up.
        </p>
      </div>

      <div class="flex flex-wrap gap-3">
        <span :class="props.syncConfigured ? 'app-badge bg-emerald-100 text-emerald-700' : 'app-badge bg-amber-100 text-amber-700'">
          {{ props.syncConfigured ? 'Sync enabled' : 'Controller auth incomplete' }}
        </span>
        <button class="app-button-primary" :disabled="!props.syncConfigured" @click="syncAccessPoints">
          <SvgIcon name="sync" class="h-[18px] w-[18px]" />
          Sync Now
        </button>
      </div>
    </section>

    <section v-if="!props.syncHealth.is_fresh" class="mt-6 rounded-[24px] border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
      Ownership approval and adoption decisions are degraded because controller inventory is stale.
      Last synced: {{ props.syncHealth.latest_synced_at || 'Never' }}.
    </section>

    <section v-if="props.healthRuntime.degraded" class="mt-4 rounded-[24px] border border-rose-200 bg-rose-50 px-5 py-4 text-rose-900">
      AP health automation is degraded.
      Sync heartbeat: {{ props.healthRuntime.sync_heartbeat_at || 'missing' }}.
      Reconcile heartbeat: {{ props.healthRuntime.reconcile_heartbeat_at || 'missing' }}.
      Stale unknown APs: {{ props.healthRuntime.stale_unknown_count || 0 }}.
    </section>

    <section v-if="props.billingRuntime.degraded" class="mt-4 rounded-[24px] border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
      AP billing automation is degraded.
      Posting heartbeat: {{ props.billingRuntime.post_heartbeat_at || 'missing' }}.
      Candidates waiting for billing: {{ props.billingRuntime.candidate_count || 0 }}.
      Blocked by automation: {{ props.billingRuntime.blocked_by_automation_count || 0 }}.
      Billing incidents: {{ props.billingRuntime.blocked_incident_count || 0 }}.
    </section>

    <section v-if="props.webhookCapabilityVerdict !== 'webhook_supported_and_implemented'" class="mt-4 rounded-[24px] border border-slate-200 bg-slate-50 px-5 py-4 text-slate-700">
      Realtime webhook health is not trusted in this deployment. AP state is controller-reconciled, not event-only.
    </section>

    <section class="mt-8 grid gap-4 xl:grid-cols-[1.35fr,0.95fr]">
      <AdminMiniLineChart
        title="Fleet status"
        subtitle="Connected, pending, and attention states in one compact view."
        mode="line"
        :points="summarySeries"
      />
      <AdminMiniLineChart
        title="Billing status"
        subtitle="Billing outcomes stay visible without covering the whole page in cards."
        mode="rail"
        :points="billingSeries"
      />
    </section>

    <section class="mt-8 space-y-6">
      <div class="app-table-shell px-6 py-6">
        <p class="app-kicker">Fleet Filters</p>
        <h2 class="mt-2 app-section-title">Filter access points</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-[240px,1fr]">
          <select v-model="accessPointStatusFilter" class="app-field">
            <option value="all">All access points</option>
            <option value="Connected">Connected</option>
            <option value="Pending">Pending</option>
            <option value="attention">Needs attention</option>
            <option value="Disconnected">Disconnected</option>
            <option value="Stale Unknown">Stale unknown</option>
            <option value="claimed">Claimed</option>
            <option value="unclaimed">Unclaimed</option>
          </select>
          <input
            v-model="accessPointSearch"
            class="app-field"
            type="search"
            placeholder="Search AP name, MAC, serial, model, site, operator, or billing state"
          />
        </div>
      </div>

      <div
        v-for="group in paginatedGroups"
        :key="group.label"
        class="app-table-shell"
      >
        <div class="flex flex-col gap-3 px-6 py-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="app-kicker">{{ group.label }} Inventory</p>
            <h2 class="mt-2 app-section-title">{{ group.label }} access points</h2>
          </div>
          <span class="app-badge-neutral">{{ group.total }} item(s)</span>
        </div>

        <div v-if="group.items.length" class="app-table-wrap">
          <table class="app-table app-table-compact">
            <thead>
              <tr>
                <th>Access Point</th>
                <th>MAC</th>
                <th>Site</th>
                <th>Status</th>
                <th>Freshness</th>
                <th>Source</th>
                <th>Claim</th>
                <th>Owner</th>
                <th>Confirmed Connected</th>
                <th>Billing</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="accessPoint in group.items" :key="accessPoint.id">
                <tr>
                  <td>
                    <div class="flex items-start gap-3">
                      <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
                        <SvgIcon name="router" class="h-5 w-5" />
                      </div>
                      <div>
                        <p class="font-semibold text-slate-950">{{ accessPoint.name || 'Unnamed AP' }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ accessPoint.model || accessPoint.vendor || 'Unknown device' }}</p>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="rounded-full bg-slate-100 px-3 py-1 font-mono text-xs text-slate-600">
                      {{ accessPoint.mac_address }}
                    </span>
                  </td>
                  <td>{{ accessPoint.site_name || 'Unassigned' }}</td>
                  <td>
                    <div class="flex items-center gap-2">
                      <span class="app-badge app-badge-compact" :class="badgeClass(accessPoint.status_label)">
                        {{ accessPoint.status_label }}
                      </span>
                      <AdminStatusTooltip
                        :message="accessPoint.health.health_warning"
                        :tone="accessPoint.status_label === 'Connected' ? 'info' : 'warning'"
                        label="Status details"
                      />
                    </div>
                  </td>
                  <td>{{ accessPoint.health.freshness_label || 'Never' }}</td>
                  <td>{{ accessPoint.health.status_source || 'unknown' }}</td>
                  <td>{{ accessPoint.claim_status }}</td>
                  <td>{{ accessPoint.claimed_by_operator || 'Unowned' }}</td>
                  <td>{{ accessPoint.health.first_confirmed_connected_at || 'Not confirmed' }}</td>
                  <td>
                    <div class="flex items-center gap-2">
                      <span
                        class="app-badge app-badge-compact"
                        :class="{
                          'bg-emerald-100 text-emerald-700': accessPoint.billing.billing_state === 'billed',
                          'bg-sky-100 text-sky-700': accessPoint.billing.billing_state === 'pending_post',
                          'bg-amber-100 text-amber-700': accessPoint.billing.billing_state === 'blocked',
                          'bg-slate-100 text-slate-600': accessPoint.billing.billing_state === 'unbilled',
                          'bg-rose-100 text-rose-700': accessPoint.billing.billing_state === 'reversed',
                        }"
                      >
                        {{ accessPoint.billing.billing_label }}
                      </span>
                      <AdminStatusTooltip
                        :message="accessPoint.billing.billing_block_reason || accessPoint.billing.billing_incident_label || accessPoint.billing.latest_billing_resolution_reason"
                        :tone="accessPoint.billing.billing_state === 'blocked' ? 'danger' : 'warning'"
                        label="Billing details"
                      />
                    </div>
                  </td>
                  <td>
                    <div class="flex flex-wrap gap-2">
                      <button
                        v-if="accessPoint.billing.billing_state === 'billed'"
                        type="button"
                        class="app-button-secondary px-3 py-2 text-[11px]"
                        @click="toggleReversal(accessPoint.id)"
                      >
                        Reverse fee
                      </button>
                      <button
                        v-if="accessPoint.claimed_by_operator"
                        type="button"
                        class="app-button-secondary px-3 py-2 text-[11px]"
                        @click="toggleCorrection(accessPoint.id)"
                      >
                        Correct ownership
                      </button>
                      <button
                        v-for="action in accessPoint.billing.available_resolution_actions"
                        :key="`${accessPoint.id}-${action}`"
                        type="button"
                        class="app-button-secondary px-3 py-2 text-[11px]"
                        @click="toggleResolution(accessPoint.id, action)"
                      >
                        {{ resolutionActionLabel(action) }}
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="resolutionForms[accessPoint.id]?.open" class="bg-amber-50/70">
                  <td colspan="11">
                    <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
                      <input
                        :value="resolutionActionLabel(resolutionForms[accessPoint.id].action)"
                        type="text"
                        class="app-input"
                        disabled
                      />
                      <input v-model="resolutionForms[accessPoint.id].reason" class="app-input" placeholder="Resolution reason" />
                      <input v-model="resolutionForms[accessPoint.id].notes" class="app-input" placeholder="Notes (optional)" />
                      <button type="button" class="app-button-primary justify-center" @click="submitResolution(accessPoint.id)">
                        Confirm resolution
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="correctionForms[accessPoint.id]?.open" class="bg-slate-50/80">
                  <td colspan="11">
                    <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
                      <select v-model="correctionForms[accessPoint.id].operator_id" class="app-input">
                        <option value="">Select operator</option>
                        <option v-for="operator in props.operators" :key="operator.id" :value="operator.id">
                          {{ operator.business_name }}
                        </option>
                      </select>
                      <select v-model="correctionForms[accessPoint.id].site_id" class="app-input" :disabled="!correctionForms[accessPoint.id].operator_id">
                        <option value="">Select site</option>
                        <option
                          v-for="site in sitesForOperator(correctionForms[accessPoint.id].operator_id)"
                          :key="site.id"
                          :value="site.id"
                        >
                          {{ site.name }}
                        </option>
                      </select>
                      <input v-model="correctionForms[accessPoint.id].correction_reason" type="text" class="app-input" placeholder="Correction reason" />
                      <input v-model="correctionForms[accessPoint.id].notes" type="text" class="app-input" placeholder="Notes (optional)" />
                    </div>
                    <div class="flex justify-end px-4 pb-4">
                      <button type="button" class="app-button-primary px-4 py-2.5" @click="submitCorrection(accessPoint.id)">
                        Save correction
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="reversalForms[accessPoint.id]?.open" class="bg-rose-50/70">
                  <td colspan="11">
                    <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
                      <input v-model="reversalForms[accessPoint.id].reason" class="app-input" placeholder="Reason for reversal" />
                      <input v-model="reversalForms[accessPoint.id].notes" class="app-input" placeholder="Notes (optional)" />
                      <button type="button" class="app-button-primary justify-center" @click="submitReversal(accessPoint.id)">
                        Confirm reversal
                      </button>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div v-else class="px-6 pb-6">
          <div class="app-empty">No access points are currently in the {{ group.label.toLowerCase() }} bucket.</div>
        </div>

        <AdminPagination
          :current-page="group.currentPage"
          :last-page="group.lastPage"
          :total="group.total"
          :from="group.from"
          :to="group.to"
          @change="changeGroupPage(group.label, $event)"
        />
      </div>
    </section>
  </MainLayout>
</template>
