<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  syncConfigured: Boolean,
  statusSummary: {
    type: Object,
    required: true,
  },
  accessPoints: {
    type: Array,
    required: true,
  },
});

const groupedAccessPoints = computed(() => ({
  Connected: props.accessPoints.filter((accessPoint) => accessPoint.status_label === 'Connected'),
  Pending: props.accessPoints.filter((accessPoint) => accessPoint.status_label === 'Pending'),
  Failed: props.accessPoints.filter((accessPoint) => accessPoint.status_label === 'Failed'),
  Offline: props.accessPoints.filter((accessPoint) => accessPoint.status_label === 'Offline'),
}));

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
    label: 'Failed',
    value: props.statusSummary.failed || 0,
    tone: 'rose',
    icon: 'report_gmailerrorred',
  },
]);

const syncAccessPoints = () => {
  router.post('/admin/access-points/sync', {}, {
    preserveScroll: true,
  });
};

const badgeClass = (status) => ({
  'bg-emerald-100 text-emerald-700': status === 'Connected',
  'bg-sky-100 text-sky-700': status === 'Pending',
  'bg-rose-100 text-rose-700': status === 'Failed',
  'bg-slate-100 text-slate-600': status === 'Offline',
});
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
          <span class="material-symbols-outlined text-[18px]">sync</span>
          Sync from Omada now
        </button>
      </div>
    </section>

    <section class="mt-8 grid gap-4 md:grid-cols-3">
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
              'bg-rose-100 text-rose-700': card.tone === 'rose',
            }"
          >
            <span class="material-symbols-outlined">{{ card.icon }}</span>
          </div>
        </div>
        <p class="app-metric-note">
          {{ card.label === 'Connected' ? 'Live APs reporting to the controller' : card.label === 'Pending' ? 'Devices awaiting operator action or sync completion' : 'Devices that need intervention' }}
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
                <th>Claim</th>
                <th>Last Synced</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="accessPoint in items" :key="accessPoint.id">
                <td>
                  <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
                      <span class="material-symbols-outlined text-[20px]">router</span>
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
                </td>
                <td>{{ accessPoint.claim_status }}</td>
                <td>{{ accessPoint.last_synced_at || 'Never' }}</td>
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
