<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import SvgIcon from '@/Components/SvgIcon.vue';
import MainLayout from '@/Layouts/MainLayout.vue';

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

  props.accessPoints.forEach((accessPoint) => {
    const label = groups.has(accessPoint.status_label) ? accessPoint.status_label : 'Unknown';
    groups.get(label).push(accessPoint);
  });

  return Object.fromEntries([...groups.entries()].filter(([, items]) => items.length));
});

const statCards = computed(() => [
  {
    label: 'Connected',
    value: props.statusSummary.connected || 0,
    tone: 'emerald',
    icon: 'check_circle',
  },
  {
    label: 'Pending',
    value: props.statusSummary.pending || 0,
    tone: 'sky',
    icon: 'pending',
  },
  {
    label: 'Attention',
    value: props.statusSummary.attention || 0,
    tone: 'amber',
    icon: 'warning',
  },
  {
    label: 'Owned',
    value: props.statusSummary.claimed || 0,
    tone: 'emerald',
    icon: 'verified',
  },
  {
    label: 'Billed',
    value: props.statusSummary.billed || 0,
    tone: 'emerald',
    icon: 'payments',
  },
  {
    label: 'Billing Blocked',
    value: props.statusSummary.blocked_billing || 0,
    tone: 'amber',
    icon: 'block',
  },
  {
    label: 'Billing Review',
    value: props.statusSummary.billing_manual_review || 0,
    tone: 'rose',
    icon: 'rule',
  },
]);

const syncAccessPoints = () => {
  router.post('/admin/access-points/sync', {}, {
    preserveScroll: true,
  });
};

const postConnectionFees = () => {
  router.post('/admin/access-points/post-connection-fees', {}, {
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
  if (!correctionForms[accessPointId]) {
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
  if (!reversalForms[accessPointId]) {
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
  if (!resolutionForms[accessPointId]) {
    resolutionForms[accessPointId] = {
      open: true,
      action,
      reason: '',
      notes: '',
    };

    return;
  }

  if (!resolutionForms[accessPointId].open || resolutionForms[accessPointId].action !== action) {
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
</script>

<template>
  <Head title="Access Points" />

  <MainLayout title="Access Points">
    <section class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <p class="app-kicker">Network Inventory</p>
        <h1 class="mt-3 app-title">Omada-synced access points</h1>
        <p class="mt-4 app-subtitle">
          Manual AP creation is dead. This page is a controller-backed inventory with sync actions, status grouping, and claim visibility. That is the only model that scales cleanly.
        </p>
      </div>

      <div class="flex flex-wrap gap-3">
        <span :class="props.syncConfigured ? 'app-badge bg-emerald-100 text-emerald-700' : 'app-badge bg-amber-100 text-amber-700'">
          {{ props.syncConfigured ? 'Sync enabled' : 'Controller auth incomplete' }}
        </span>
        <button class="app-button-primary" :disabled="!props.syncConfigured" @click="syncAccessPoints">
          <SvgIcon name="sync" class="h-[18px] w-[18px]" />
          Sync from Omada now
        </button>
        <button class="app-button-secondary" @click="postConnectionFees">
          <SvgIcon name="payments" class="h-[18px] w-[18px]" />
          Post AP fees now
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

    <section class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <article
        v-for="card in statCards"
        :key="card.label"
        class="app-metric-card"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="app-metric-label">{{ card.label }}</p>
            <p class="app-metric-value">{{ card.value }}</p>
          </div>
          <div
            class="flex h-12 w-12 items-center justify-center rounded-full"
            :class="{
              'bg-emerald-100 text-emerald-700': card.tone === 'emerald',
              'bg-sky-100 text-sky-700': card.tone === 'sky',
              'bg-amber-100 text-amber-700': card.tone === 'amber',
              'bg-rose-100 text-rose-700': card.tone === 'rose',
            }"
          >
            <SvgIcon :name="card.icon" class="h-6 w-6" />
          </div>
        </div>
        <p class="app-metric-note">
          {{ card.label === 'Connected'
            ? 'Live APs reporting to the controller'
            : card.label === 'Pending'
              ? 'Controller-discovered devices that still have no safe ownership claim'
              : card.label === 'Owned'
                ? 'APs with approved ownership metadata recorded locally'
                : card.label === 'Billed'
                  ? 'Access points with a posted one-time connection fee'
                  : card.label === 'Billing Blocked'
                    ? 'APs blocked by stale evidence, ownership problems, or manual review'
                    : card.label === 'Billing Review'
                      ? 'APs that require human billing intervention before any repost'
                : 'Heartbeat-missed, disconnected, stale, or failed APs' }}
        </p>
      </article>
    </section>

    <section class="mt-8 space-y-6">
      <div
        v-for="(items, label) in groupedAccessPoints"
        :key="label"
        class="app-table-shell"
      >
        <div class="flex flex-col gap-3 px-6 py-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="app-kicker">{{ label }} Inventory</p>
            <h2 class="mt-2 app-section-title">{{ label }} access points</h2>
          </div>
          <span class="app-badge-neutral">{{ items.length }} item(s)</span>
        </div>

        <div v-if="items.length" class="app-table-wrap">
          <table class="app-table">
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
              <tr v-for="accessPoint in items" :key="accessPoint.id">
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
                  <span class="app-badge" :class="badgeClass(accessPoint.status_label)">
                    {{ accessPoint.status_label }}
                  </span>
                  <p v-if="accessPoint.health.health_warning" class="mt-2 text-xs text-rose-600">
                    {{ accessPoint.health.health_warning }}
                  </p>
                </td>
                <td>{{ accessPoint.health.freshness_label || 'Never' }}</td>
                <td>{{ accessPoint.health.status_source || 'unknown' }}</td>
                <td>{{ accessPoint.claim_status }}</td>
                <td>{{ accessPoint.claimed_by_operator || 'Unowned' }}</td>
                <td>{{ accessPoint.health.first_confirmed_connected_at || 'Not confirmed' }}</td>
                <td>
                  <span
                    class="app-badge"
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
                  <p v-if="accessPoint.billing.latest_entry" class="mt-2 text-xs text-slate-500">
                    {{ accessPoint.billing.latest_entry.direction }} PHP {{ accessPoint.billing.latest_entry.amount }} on {{ accessPoint.billing.latest_entry.posted_at || 'unknown date' }}
                  </p>
                  <p v-if="accessPoint.billing.billing_incident_label" class="mt-1 text-xs text-amber-700">
                    Incident: {{ accessPoint.billing.billing_incident_label }}
                  </p>
                  <p v-if="accessPoint.billing.billing_block_reason" class="mt-1 text-xs text-rose-600">
                    {{ accessPoint.billing.billing_block_reason }}
                  </p>
                  <p v-if="accessPoint.billing.billing_eligibility_confirmed_at" class="mt-1 text-xs text-slate-500">
                    Billing eligibility confirmed {{ accessPoint.billing.billing_eligibility_confirmed_at }}
                  </p>
                  <p v-if="accessPoint.billing.latest_billing_resolution_reason" class="mt-1 text-xs text-slate-500">
                    Resolution: {{ accessPoint.billing.latest_billing_resolution_reason }}
                  </p>
                </td>
                <td>
                  <div class="flex flex-col gap-2">
                    <button
                      v-if="accessPoint.billing.billing_state === 'billed'"
                      type="button"
                      class="app-button-secondary px-4 py-2.5"
                      @click="toggleReversal(accessPoint.id)"
                    >
                      Reverse fee
                    </button>
                    <button
                      v-if="accessPoint.claimed_by_operator"
                      type="button"
                      class="app-button-secondary px-4 py-2.5"
                      @click="toggleCorrection(accessPoint.id)"
                    >
                      Correct ownership
                    </button>
                    <button
                      v-for="action in accessPoint.billing.available_resolution_actions"
                      :key="`${accessPoint.id}-${action}`"
                      type="button"
                      class="app-button-secondary px-4 py-2.5"
                      @click="toggleResolution(accessPoint.id, action)"
                    >
                      {{ resolutionActionLabel(action) }}
                    </button>
                    <p v-if="accessPoint.ownership_corrected_at" class="text-xs text-slate-500">
                      Corrected {{ accessPoint.ownership_corrected_at }} by {{ accessPoint.ownership_corrected_by || 'Admin' }}
                    </p>
                    <p v-if="accessPoint.latest_correction_reason" class="text-xs text-slate-500">{{ accessPoint.latest_correction_reason }}</p>
                  </div>
                </td>
              </tr>
              <tr
                v-for="accessPoint in items.filter((item) => resolutionForms[item.id]?.open)"
                :key="`${accessPoint.id}-resolution`"
                class="bg-amber-50/70"
              >
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
              <tr
                v-for="accessPoint in items.filter((item) => correctionForms[item.id]?.open)"
                :key="`${accessPoint.id}-correction`"
                class="bg-slate-50/80"
              >
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
              <tr
                v-for="accessPoint in items.filter((item) => reversalForms[item.id]?.open)"
                :key="`${accessPoint.id}-reversal`"
                class="bg-rose-50/70"
              >
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
            </tbody>
          </table>
        </div>

        <div v-else class="px-6 pb-6">
          <div class="app-empty">No access points are currently in the {{ label.toLowerCase() }} bucket.</div>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
