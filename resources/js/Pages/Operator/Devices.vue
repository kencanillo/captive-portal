<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

defineProps({
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
      <h1 class="mt-3 app-title">Access point inventory</h1>
    </section>

    <section class="mt-8 grid gap-4 md:grid-cols-3">
      <article class="app-metric-card">
        <p class="app-metric-label">Claims</p>
        <p class="app-metric-value">{{ formatNumber(claimRequests.length) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Connected APs</p>
        <p class="app-metric-value">{{ formatNumber(connectedDevices.length) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Disconnected APs</p>
        <p class="app-metric-value">{{ formatNumber(failedDevices.length) }}</p>
      </article>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1fr),26rem]">
      <section class="space-y-6">
        <div class="app-card p-7">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="app-kicker">Connected APs</p>
              <h2 class="mt-2 app-section-title">Online inventory</h2>
            </div>
            <span class="app-badge bg-emerald-100 text-emerald-700">{{ connectedDevices.length }} connected</span>
          </div>
          <div class="mt-6 space-y-3">
            <article v-for="device in connectedDevices" :key="device.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p class="font-semibold text-slate-950">{{ device.name || device.mac_address }}</p>
                  <p class="mt-1 text-sm text-slate-500">{{ device.site_name || 'Unassigned site' }} • {{ device.mac_address }}</p>
                </div>
                <div class="text-left sm:text-right">
                  <span class="app-badge bg-emerald-100 text-emerald-700">{{ device.health.health_label }}</span>
                  <p class="mt-2 text-xs text-slate-500">{{ formatNumber(device.current_sessions_count || 0) }} client sessions</p>
                </div>
              </div>
            </article>
            <div v-if="!connectedDevices.length" class="app-empty">No connected APs.</div>
          </div>
        </div>

        <div class="app-card p-7">
          <div class="flex items-center justify-between gap-4">
            <div>
              <p class="app-kicker">Disconnected APs</p>
              <h2 class="mt-2 app-section-title">Offline inventory</h2>
            </div>
            <span class="app-badge bg-rose-100 text-rose-700">{{ failedDevices.length }} disconnected</span>
          </div>
          <div class="mt-6 space-y-3">
            <article v-for="device in failedDevices" :key="device.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p class="font-semibold text-slate-950">{{ device.name || device.mac_address }}</p>
                  <p class="mt-1 text-sm text-slate-500">{{ device.site_name || 'Unassigned site' }} • {{ device.mac_address }}</p>
                </div>
                <div class="text-left sm:text-right">
                  <span class="app-badge bg-rose-100 text-rose-700">{{ device.health.health_label }}</span>
                  <p class="mt-2 text-xs text-slate-500">{{ formatNumber(device.current_sessions_count || 0) }} client sessions</p>
                </div>
              </div>
            </article>
            <div v-if="!failedDevices.length" class="app-empty">No disconnected APs.</div>
          </div>
        </div>
      </section>

      <aside class="space-y-6">
        <form
          method="POST"
          :action="route('operator.access-point-claims.store')"
          class="app-card p-7"
        >
          <input type="hidden" name="_token" :value="csrfToken" />

          <p class="app-kicker">Claim AP</p>
          <h2 class="mt-3 app-section-title">Submit claim</h2>

          <div class="mt-6 grid gap-5">
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
              <input name="requested_serial_number" type="text" class="app-field" />
            </div>

            <div>
              <label class="app-label">MAC Address</label>
              <input name="requested_mac_address" type="text" class="app-field" />
            </div>

            <div>
              <label class="app-label">AP Name Hint</label>
              <input name="requested_ap_name" type="text" class="app-field" />
            </div>

            <button type="submit" class="app-button-primary w-full justify-center">
              Submit claim
            </button>
          </div>
        </form>

        <div class="app-card p-7">
          <p class="app-kicker">Claims</p>
          <h2 class="mt-3 app-section-title">Claim status</h2>
          <div class="mt-6 space-y-3">
            <article v-for="claim in claimRequests" :key="claim.id" class="rounded-[18px] border border-slate-200/80 bg-white/80 px-4 py-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="font-semibold text-slate-950">{{ claim.site_name }}</p>
                  <p class="mt-1 text-xs text-slate-500">{{ claim.requested_serial_number || claim.requested_mac_address || 'No fingerprint' }}</p>
                </div>
                <span class="app-badge bg-slate-100 text-slate-700">{{ claim.claim_status }}</span>
              </div>
            </article>
            <div v-if="!claimRequests.length" class="app-empty">No AP claims yet.</div>
          </div>
        </div>
      </aside>
    </section>
  </MainLayout>
</template>
