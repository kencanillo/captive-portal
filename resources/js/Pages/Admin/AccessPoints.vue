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
  Connected: props.accessPoints.filter(accessPoint => accessPoint.status_label === 'Connected'),
  Pending: props.accessPoints.filter(accessPoint => accessPoint.status_label === 'Pending'),
  Failed: props.accessPoints.filter(accessPoint => accessPoint.status_label === 'Failed'),
  Offline: props.accessPoints.filter(accessPoint => accessPoint.status_label === 'Offline'),
}));

const syncAccessPoints = () => {
  router.post('/admin/access-points/sync', {}, {
    preserveScroll: true,
  });
};
</script>

<template>
  <Head title="Access Points" />

  <MainLayout title="Access Points">
    <div class="rounded-lg border border-slate-200 bg-white p-5 shadow">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">Omada-synced access point inventory</h2>
          <p class="mt-1 text-sm text-slate-600">
            Manual AP creation and editing are gone. This page is now the sync-backed inventory and status board.
          </p>
        </div>
        <div class="flex flex-col items-start gap-2 lg:items-end">
          <p class="text-sm" :class="props.syncConfigured ? 'text-emerald-700' : 'text-amber-700'">
            {{ props.syncConfigured ? 'Automatic Omada sync is enabled.' : 'Automatic Omada sync is disabled until local controller username/password are saved.' }}
          </p>
          <button
            class="rounded-md px-4 py-2 text-sm font-semibold"
            :class="props.syncConfigured ? 'bg-slate-900 text-white' : 'cursor-not-allowed bg-slate-200 text-slate-500'"
            :disabled="!props.syncConfigured"
            @click="syncAccessPoints"
          >
            Sync from Omada now
          </button>
        </div>
      </div>

      <div class="mt-5 grid gap-4 md:grid-cols-3">
        <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">
          <p class="text-sm text-emerald-700">Connected</p>
          <p class="mt-2 text-2xl font-bold text-emerald-900">{{ props.statusSummary.connected || 0 }}</p>
        </div>
        <div class="rounded-lg border border-amber-100 bg-amber-50 p-4">
          <p class="text-sm text-amber-700">Pending</p>
          <p class="mt-2 text-2xl font-bold text-amber-900">{{ props.statusSummary.pending || 0 }}</p>
        </div>
        <div class="rounded-lg border border-rose-100 bg-rose-50 p-4">
          <p class="text-sm text-rose-700">Failed</p>
          <p class="mt-2 text-2xl font-bold text-rose-900">{{ props.statusSummary.failed || 0 }}</p>
        </div>
      </div>
    </div>

    <div class="mt-6 space-y-6">
      <section v-for="(items, label) in groupedAccessPoints" :key="label" class="rounded-lg bg-white p-5 shadow">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-900">{{ label }}</h3>
          <span class="text-sm text-slate-500">{{ items.length }} item(s)</span>
        </div>

        <div v-if="items.length" class="mt-4 overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-200 text-slate-500">
                <th class="px-2 py-2">AP name</th>
                <th class="px-2 py-2">MAC</th>
                <th class="px-2 py-2">Site</th>
                <th class="px-2 py-2">Status</th>
                <th class="px-2 py-2">Claim</th>
                <th class="px-2 py-2">Last synced</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="accessPoint in items" :key="accessPoint.id" class="border-b border-slate-100">
                <td class="px-2 py-3">
                  <p class="font-medium text-slate-900">{{ accessPoint.name || 'Unnamed AP' }}</p>
                  <p class="text-xs text-slate-500">{{ accessPoint.model || accessPoint.vendor || 'Unknown device' }}</p>
                </td>
                <td class="px-2 py-3 text-slate-700">{{ accessPoint.mac_address }}</td>
                <td class="px-2 py-3 text-slate-700">{{ accessPoint.site_name || 'Unassigned' }}</td>
                <td class="px-2 py-3">
                  <span class="rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="{
                      'bg-emerald-100 text-emerald-700': accessPoint.status_label === 'Connected',
                      'bg-amber-100 text-amber-700': accessPoint.status_label === 'Pending',
                      'bg-rose-100 text-rose-700': accessPoint.status_label === 'Failed',
                      'bg-slate-200 text-slate-700': accessPoint.status_label === 'Offline',
                    }">
                    {{ accessPoint.status_label }}
                  </span>
                </td>
                <td class="px-2 py-3 text-slate-700">{{ accessPoint.claim_status }}</td>
                <td class="px-2 py-3 text-slate-700">{{ accessPoint.last_synced_at || 'Never' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-else class="mt-4 text-sm text-slate-500">No access points in this status bucket.</p>
      </section>
    </div>
  </MainLayout>
</template>
