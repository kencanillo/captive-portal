<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

const props = defineProps({
  syncHealth: {
    type: Object,
    required: true,
  },
  claims: {
    type: Array,
    required: true,
  },
});

const claimStatusFilter = ref('all');
const claimSearch = ref('');
const filteredClaims = computed(() => {
  const query = claimSearch.value.trim().toLowerCase();

  return props.claims.filter((item) => {
    const matchesStatus = claimStatusFilter.value === 'all'
      || item.claim_status === claimStatusFilter.value
      || (claimStatusFilter.value === 'blocked' && (['denied', 'adoption_failed'].includes(item.claim_status) || item.requires_re_review));

    if (!matchesStatus) return false;
    if (!query) return true;

    return [
      item.operator?.business_name,
      item.operator?.user_email,
      item.operator?.user_name,
      item.site?.name,
      item.requested_serial_number,
      item.requested_mac_address,
      item.requested_ap_name,
      item.matched_access_point?.name,
      item.matched_access_point?.serial_number,
      item.matched_access_point?.mac_address,
    ].filter(Boolean).join(' ').toLowerCase().includes(query);
  });
});

const summary = computed(() => ({
  total: filteredClaims.value.length,
  pending: filteredClaims.value.filter((item) => item.claim_status === 'pending_review').length,
  approved: filteredClaims.value.filter((item) => item.claim_status === 'approved').length,
  adopted: filteredClaims.value.filter((item) => item.claim_status === 'adopted').length,
  blocked: filteredClaims.value.filter((item) => ['denied', 'adoption_failed'].includes(item.claim_status) || item.requires_re_review).length,
}));

const approveClaim = (id) => {
  const reviewNotes = window.prompt('Approval notes (optional)') ?? '';

  router.post(`/admin/access-point-claims/${id}/approve`, {
    review_notes: reviewNotes,
  }, {
    preserveScroll: true,
  });
};

const denyClaim = (id) => {
  const denialReason = window.prompt('Denial reason');

  if (denialReason === null || denialReason.trim() === '') {
    return;
  }

  const reviewNotes = window.prompt('Additional review notes (optional)') ?? '';

  router.post(`/admin/access-point-claims/${id}/deny`, {
    denial_reason: denialReason,
    review_notes: reviewNotes,
  }, {
    preserveScroll: true,
  });
};
</script>

<template>
  <Head title="AP Claims" />

  <MainLayout title="Access Point Claims">
    <section>
      <p class="app-kicker">Ownership Review</p>
      <h1 class="mt-3 app-title">AP claim queue</h1>
      <p class="mt-4 app-subtitle">
        Pending discovery is not ownership proof. Claims are approved here, matched against real pending controller inventory, then adopted from that approved claim only.
      </p>
    </section>

    <section v-if="!syncHealth.is_fresh" class="mt-6 rounded-[24px] border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
      Claim approval and adoption are intentionally blocked because controller inventory is stale.
      Last synced: {{ syncHealth.latest_synced_at || 'Never' }}.
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
      <article class="app-metric-card">
        <p class="app-metric-label">Claims</p>
        <p class="app-metric-value">{{ formatNumber(summary.total) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Pending Review</p>
        <p class="app-metric-value">{{ formatNumber(summary.pending) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Approved</p>
        <p class="app-metric-value">{{ formatNumber(summary.approved) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Adopted</p>
        <p class="app-metric-value">{{ formatNumber(summary.adopted) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Denied / Failed</p>
        <p class="app-metric-value">{{ formatNumber(summary.blocked) }}</p>
      </article>
    </section>

    <section class="app-table-shell mt-8">
      <div class="px-6 py-6">
        <p class="app-kicker">Admin Queue</p>
        <h2 class="mt-2 app-section-title">Claim review and adoption audit</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-[220px,1fr]">
          <select v-model="claimStatusFilter" class="app-field">
            <option value="all">All claims</option>
            <option value="pending_review">Pending review</option>
            <option value="approved">Approved</option>
            <option value="adopted">Adopted</option>
            <option value="blocked">Denied / failed / re-review</option>
          </select>
          <input
            v-model="claimSearch"
            class="app-field"
            type="search"
            placeholder="Search operator, site, serial, MAC, or AP name"
          />
        </div>
      </div>

      <div class="app-table-wrap">
        <table class="app-table">
          <thead>
            <tr>
              <th>Operator</th>
              <th>Site</th>
              <th>Fingerprint</th>
              <th>Matched Device</th>
              <th>Status</th>
              <th>Match State</th>
              <th>Review</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="claim in filteredClaims" :key="claim.id">
              <td>
                <p class="font-semibold text-slate-950">{{ claim.operator?.business_name || 'Unknown operator' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ claim.operator?.user_email || claim.operator?.user_name || 'No contact' }}</p>
              </td>
              <td>{{ claim.site?.name || 'Unassigned' }}</td>
              <td>
                <p class="text-sm text-slate-700">{{ claim.requested_serial_number || 'No serial' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ claim.requested_mac_address || 'No MAC' }}</p>
                <p v-if="claim.requested_ap_name" class="mt-1 text-xs text-slate-400">Hint: {{ claim.requested_ap_name }}</p>
              </td>
              <td>
                <p class="font-medium text-slate-950">{{ claim.matched_access_point?.name || 'No live match yet' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ claim.matched_access_point?.serial_number || claim.matched_access_point?.mac_address || '-' }}</p>
                <p v-if="claim.matched_access_point?.site_name" class="mt-1 text-xs text-slate-400">{{ claim.matched_access_point.site_name }}</p>
              </td>
              <td>
                <span
                  class="app-badge"
                  :class="{
                    'bg-amber-100 text-amber-700': ['pending_review', 'adoption_pending'].includes(claim.claim_status),
                    'bg-sky-100 text-sky-700': claim.claim_status === 'approved',
                    'bg-emerald-100 text-emerald-700': claim.claim_status === 'adopted',
                    'bg-rose-100 text-rose-700': ['denied', 'adoption_failed'].includes(claim.claim_status),
                  }"
                >
                  {{ claim.claim_status }}
                </span>
                <p v-if="claim.failure_reason" class="mt-2 text-xs text-rose-600">{{ claim.failure_reason }}</p>
              </td>
              <td>
                <p class="text-sm text-slate-700">{{ claim.claim_match_status || 'unmatched' }}</p>
                <p v-if="claim.requires_re_review" class="mt-1 text-xs text-rose-600">Re-review required</p>
                <p v-if="claim.conflict_state" class="mt-1 text-xs text-rose-600">{{ claim.conflict_state }}</p>
                <p v-if="claim.sync_freshness_checked_at" class="mt-1 text-xs text-slate-500">Checked {{ claim.sync_freshness_checked_at }}</p>
              </td>
              <td>
                <p class="text-sm text-slate-600">{{ claim.reviewed_by?.name || '-' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ claim.reviewed_at || claim.claimed_at || '-' }}</p>
                <p v-if="claim.review_notes" class="mt-2 text-xs text-slate-500">{{ claim.review_notes }}</p>
                <p v-if="claim.denial_reason" class="mt-2 text-xs text-rose-600">{{ claim.denial_reason }}</p>
              </td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <button v-if="claim.claim_status === 'pending_review'" class="app-button-primary px-4 py-2.5" @click="approveClaim(claim.id)">Approve</button>
                  <button v-if="claim.claim_status === 'pending_review'" class="app-button-secondary px-4 py-2.5" @click="denyClaim(claim.id)">Deny</button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredClaims.length">
              <td colspan="8">
                <div class="app-empty">No AP claims are waiting for review.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </MainLayout>
</template>
