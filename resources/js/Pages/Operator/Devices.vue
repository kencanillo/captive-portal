<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

defineProps({
  pendingDevices: Array,
  connectedDevices: Array,
  failedDevices: Array,
  operatorStatus: String,
  canAdoptDevices: Boolean,
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
        <p class="app-metric-label">Pending</p>
        <p class="app-metric-value">{{ formatNumber(pendingDevices.length) }}</p>
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

    <section class="mt-8 space-y-6">
      <section class="app-card-strong p-7">
        <div class="flex items-center justify-between gap-4">
          <div>
            <p class="app-kicker">Pending Devices</p>
            <h2 class="mt-2 app-section-title">Ready for action</h2>
            <p v-if="!canAdoptDevices" class="mt-2 text-sm text-amber-600">
              Operator account pending approval - device adoption disabled
            </p>
          </div>
          <span class="app-badge bg-sky-100 text-sky-700">{{ pendingDevices.length }} pending</span>
        </div>
        <div class="mt-6 space-y-3">
          <article v-for="device in pendingDevices" :key="device.id" class="rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <p class="font-semibold text-slate-950">{{ device.name }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ device.mac_address }} • {{ device.model }} • {{ device.site_name }}</p>
                <p class="mt-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
                  {{ device.claim_status }}
                </p>
              </div>
              <form v-if="canAdoptDevices" method="POST" :action="route('operator.devices.adopt')" class="inline">
                <input type="hidden" name="_token" :value="csrfToken" />
                <input type="hidden" name="access_point_id" :value="device.id" />
                <button type="submit" class="app-button-primary">
                  Adopt
                </button>
              </form>
              <div v-else class="inline">
                <button disabled class="app-button-primary opacity-50 cursor-not-allowed">
                  Adopt (Pending Approval)
                </button>
              </div>
            </div>
          </article>
          <div v-if="!pendingDevices.length" class="app-empty">No pending or unclaimed devices.</div>
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
            </article>
            <div v-if="!failedDevices.length" class="app-empty">No failed devices.</div>
          </div>
        </div>
      </section>
    </section>
  </MainLayout>
</template>
