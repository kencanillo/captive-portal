<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

defineProps({
  syncHealth: Object,
  healthRuntime: Object,
  billingRuntime: Object,
  webhookCapabilityVerdict: String,
  claimableSites: Array,
  claimRequests: Array,
  connectedDevices: Array,
  failedDevices: Array,
});

const csrfToken = usePage().props.csrf_token;
</script>

<template>
  <Head title="Device Management" />

  <MainLayout title="Device Management">
    <section>
      <p class="app-kicker">Operator Devices</p>
      <h1 class="mt-3 app-title">Site device inventory</h1>
      <p class="mt-4 app-subtitle">
        Pending, connected, and failed device states need to be obvious. Operators should act from a clean status board, not hunt through noisy lists.
      </p>
    </section>

    <section class="mt-8 grid gap-4 md:grid-cols-3">
      <article class="app-metric-card">
        <p class="app-metric-label">Claims</p>
        <p class="app-metric-value">{{ formatNumber(claimRequests.length) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Connected</p>
        <p class="app-metric-value">{{ formatNumber(connectedDevices.length) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Failed</p>
        <p class="app-metric-value">{{ formatNumber(failedDevices.length) }}</p>
      </article>
    </section>

    <section v-if="!syncHealth?.is_fresh" class="mt-6 rounded-[24px] border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
      Claim adoption is blocked because controller inventory is stale.
      Last synced: {{ syncHealth?.latest_synced_at || 'Never' }}.
    </section>

    <section v-if="healthRuntime?.degraded" class="mt-4 rounded-[24px] border border-rose-200 bg-rose-50 px-5 py-4 text-rose-900">
      AP health automation is degraded.
      Sync heartbeat: {{ healthRuntime?.sync_heartbeat_at || 'missing' }}.
      Reconcile heartbeat: {{ healthRuntime?.reconcile_heartbeat_at || 'missing' }}.
      Stale unknown APs: {{ healthRuntime?.stale_unknown_count || 0 }}.
    </section>

    <section v-if="billingRuntime?.degraded" class="mt-4 rounded-[24px] border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
      AP billing automation is degraded.
      Posting heartbeat: {{ billingRuntime?.post_heartbeat_at || 'missing' }}.
      Candidates waiting for billing: {{ billingRuntime?.candidate_count || 0 }}.
      Blocked by automation: {{ billingRuntime?.blocked_by_automation_count || 0 }}.
    </section>

    <section v-if="webhookCapabilityVerdict !== 'webhook_supported_and_implemented'" class="mt-4 rounded-[24px] border border-slate-200 bg-slate-50 px-5 py-4 text-slate-700">
      Realtime webhook health is not trusted in this setup. Device health is controller-reconciled, and stale inventory blocks action.
    </section>

    <section class="mt-8 space-y-6">
      <section class="app-card-strong p-7">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr),30rem]">
          <div class="space-y-5">
            <div>
              <p class="app-kicker">Claim Workflow</p>
              <h2 class="mt-2 app-section-title">Submit AP ownership claim</h2>
              <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-500">
                Device names are noise. Claim with serial number or MAC, tie it to your intended site, then wait for admin approval before adoption.
              </p>
            </div>

            <div class="grid gap-3 md:grid-cols-3">
              <article class="app-panel">
                <p class="app-label">Step 1</p>
                <p class="text-sm font-semibold text-slate-950">Target the correct site</p>
                <p class="mt-2 text-sm leading-6 text-slate-500">Claims without the right site mapping become admin cleanup. Stop feeding bad data into the queue.</p>
              </article>
              <article class="app-panel">
                <p class="app-label">Step 2</p>
                <p class="text-sm font-semibold text-slate-950">Use serial first</p>
                <p class="mt-2 text-sm leading-6 text-slate-500">Serial is the clean fingerprint. MAC is fallback. AP name is just a hint and not authoritative.</p>
              </article>
              <article class="app-panel">
                <p class="app-label">Step 3</p>
                <p class="text-sm font-semibold text-slate-950">Wait for approval</p>
                <p class="mt-2 text-sm leading-6 text-slate-500">Adoption stays blocked until admin review and fresh controller inventory both agree.</p>
              </article>
            </div>
          </div>

          <form
            method="POST"
            :action="route('operator.access-point-claims.store')"
            class="overflow-hidden rounded-[28px] border border-slate-200/80 bg-[linear-gradient(180deg,rgba(255,255,255,0.98),rgba(248,250,252,0.92))] shadow-[0_28px_70px_-44px_rgba(19,27,46,0.4)]"
          >
            <input type="hidden" name="_token" :value="csrfToken" />

            <div class="border-b border-slate-200/80 bg-slate-50/70 px-5 py-4">
              <p class="app-kicker">Claim Console</p>
              <p class="mt-2 text-sm leading-6 text-slate-500">Submit one clean fingerprinted claim. Sloppy input here creates stale review debt later.</p>
            </div>

            <div class="grid gap-5 p-5">
              <div>
                <label class="app-label">Assigned Site</label>
                <select name="site_id" class="app-field" required>
                  <option value="">Select site</option>
                  <option v-for="site in claimableSites" :key="site.id" :value="site.id">
                    {{ site.name }}
                  </option>
                </select>
              </div>

              <div>
                <label class="app-label">Serial Number</label>
                <input name="requested_serial_number" type="text" class="app-field" placeholder="Serial number (preferred)" />
              </div>

              <div>
                <label class="app-label">MAC Address</label>
                <input name="requested_mac_address" type="text" class="app-field" placeholder="MAC address (fallback if serial is unavailable)" />
              </div>

              <div>
                <label class="app-label">AP Name Hint</label>
                <input name="requested_ap_name" type="text" class="app-field" placeholder="AP name hint only (optional)" />
              </div>

              <button type="submit" class="app-button-primary w-full justify-center">
                Submit claim
              </button>
            </div>
          </form>
        </div>
        <div class="mt-6 space-y-3">
          <article v-for="claim in claimRequests" :key="claim.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <p class="font-semibold text-slate-950">{{ claim.site_name }}</p>
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
                </div>
                <p class="mt-1 text-sm text-slate-500">
                  {{ claim.requested_serial_number || 'No serial' }} • {{ claim.requested_mac_address || 'No MAC' }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                  Match state: {{ claim.claim_match_status || 'unmatched' }}
                </p>
                <p v-if="claim.requires_re_review" class="mt-1 text-xs text-rose-600">Admin re-review required before adoption.</p>
                <p v-if="claim.conflict_state" class="mt-1 text-xs text-rose-600">{{ claim.conflict_state }}</p>
                <p v-if="claim.matched_access_point" class="mt-2 text-xs text-slate-500">
                  Matched pending device: {{ claim.matched_access_point.name || 'Unnamed AP' }} • {{ claim.matched_access_point.mac_address || claim.matched_access_point.serial_number }}
                </p>
                <p v-if="claim.failure_reason" class="mt-2 text-xs text-rose-600">{{ claim.failure_reason }}</p>
                <p v-else-if="claim.denial_reason" class="mt-2 text-xs text-rose-600">{{ claim.denial_reason }}</p>
                <p v-else-if="claim.review_notes" class="mt-2 text-xs text-slate-500">{{ claim.review_notes }}</p>
              </div>
              <form
                v-if="['approved', 'adoption_failed'].includes(claim.claim_status)"
                method="POST"
                :action="route('operator.access-point-claims.adopt', claim.id)"
                class="inline"
              >
                <input type="hidden" name="_token" :value="csrfToken" />
                <button type="submit" class="app-button-primary" :disabled="!syncHealth?.is_fresh || claim.requires_re_review">
                  Adopt
                </button>
              </form>
            </div>
          </article>
          <div v-if="!claimRequests.length" class="app-empty">No AP claims yet.</div>
        </div>
      </section>

      <section class="grid gap-6 xl:grid-cols-2">
        <div class="app-card p-7">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="app-kicker">Connected Devices</p>
              <h2 class="mt-2 app-section-title">Stable inventory</h2>
            </div>
            <span class="app-badge bg-emerald-100 text-emerald-700">{{ connectedDevices.length }} connected</span>
          </div>
          <div class="mt-6 space-y-3">
            <article v-for="device in connectedDevices" :key="device.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
              <p class="font-semibold text-slate-950">{{ device.name }}</p>
              <p class="mt-1 text-sm text-slate-500">{{ device.mac_address }} • {{ device.model }} • {{ device.site_name }}</p>
              <p class="mt-2 text-xs text-emerald-700">
                {{ device.health.health_label }} • {{ device.health.freshness_label || 'No freshness data' }} • {{ device.health.status_source || 'unknown' }}
              </p>
              <p class="mt-1 text-xs text-slate-500">
                Billing: {{ device.billing.billing_label }}
                <span v-if="device.billing.latest_entry">• PHP {{ device.billing.latest_entry.amount }} • {{ device.billing.latest_entry.posted_at || 'not posted yet' }}</span>
              </p>
            </article>
            <div v-if="!connectedDevices.length" class="app-empty">No connected devices.</div>
          </div>
        </div>

        <div class="app-card p-7">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="app-kicker">Failed Devices</p>
              <h2 class="mt-2 app-section-title">Requires intervention</h2>
            </div>
            <span class="app-badge bg-rose-100 text-rose-700">{{ failedDevices.length }} failed</span>
          </div>
          <div class="mt-6 space-y-3">
            <article v-for="device in failedDevices" :key="device.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
              <p class="font-semibold text-slate-950">{{ device.name }}</p>
              <p class="mt-1 text-sm text-slate-500">{{ device.mac_address }} • {{ device.model }} • {{ device.site_name }}</p>
              <p class="mt-2 text-xs text-rose-600">
                {{ device.health.health_label }} • {{ device.health.freshness_label || 'No freshness data' }} • {{ device.health.status_source || 'unknown' }}
              </p>
              <p v-if="device.health.health_warning" class="mt-1 text-xs text-rose-600">{{ device.health.health_warning }}</p>
              <p class="mt-1 text-xs text-slate-500">
                Billing: {{ device.billing.billing_label }}
                <span v-if="device.billing.latest_entry">• PHP {{ device.billing.latest_entry.amount }} • {{ device.billing.latest_entry.posted_at || 'not posted yet' }}</span>
              </p>
              <p v-if="device.billing.billing_block_reason" class="mt-1 text-xs text-rose-600">{{ device.billing.billing_block_reason }}</p>
            </article>
            <div v-if="!failedDevices.length" class="app-empty">No failed devices.</div>
          </div>
        </div>
      </section>
    </section>
  </MainLayout>
</template>
