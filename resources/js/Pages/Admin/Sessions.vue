<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatNumber } from '@/utils/formatters';

const props = defineProps({
  sessions: {
    type: Object,
    required: true,
  },
});

const sessionRows = computed(() => props.sessions.data || []);
const activeCount = computed(() => sessionRows.value.filter((item) => item.is_active).length);
const paidCount = computed(() => sessionRows.value.filter((item) => item.payment_status === 'paid').length);
const siteCount = computed(() => new Set(sessionRows.value.map((item) => item.site?.name).filter(Boolean)).size);
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
              <th>Status</th>
              <th>Active</th>
              <th>Time Left</th>
              <th>Ends</th>
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
              <td>{{ item.is_active ? 'Yes' : 'No' }}</td>
              <td class="font-semibold text-slate-950">{{ item.remaining_time }}</td>
              <td>{{ item.end_time || '-' }}</td>
            </tr>
            <tr v-if="!sessionRows.length">
              <td colspan="10">
                <div class="app-empty">No WiFi sessions are available in this dataset.</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </MainLayout>
</template>
