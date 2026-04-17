<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  syncConfigured: Boolean,
  accessPoints: {
    type: Array,
    required: true,
  },
});

const defaultForm = () => ({
  name: '',
  serial_number: '',
  mac_address: '',
  site_name: '',
  vendor: 'TP-Link',
  model: '',
  ip_address: '',
  omada_device_id: '',
  claim_status: 'claimed',
  custom_ssid: 'KennFi Lab',
  voucher_ssid_name: '',
  allow_client_pause: true,
  block_tethering: true,
  is_portal_enabled: true,
});

const form = reactive(defaultForm());
const editingId = ref(null);
const editForm = reactive(defaultForm());

const resetForm = () => {
  Object.assign(form, defaultForm());
};

const saveCreate = () => {
  router.post('/admin/access-points', form, {
    preserveScroll: true,
    onSuccess: () => resetForm(),
  });
};

const startEdit = (accessPoint) => {
  editingId.value = accessPoint.id;
  Object.assign(editForm, {
    name: accessPoint.name || '',
    serial_number: accessPoint.serial_number || '',
    mac_address: accessPoint.mac_address || '',
    site_name: accessPoint.site_name || '',
    vendor: accessPoint.vendor || '',
    model: accessPoint.model || '',
    ip_address: accessPoint.ip_address || '',
    omada_device_id: accessPoint.omada_device_id || '',
    claim_status: accessPoint.claim_status,
    custom_ssid: accessPoint.custom_ssid || '',
    voucher_ssid_name: accessPoint.voucher_ssid_name || '',
    allow_client_pause: Boolean(accessPoint.allow_client_pause),
    block_tethering: Boolean(accessPoint.block_tethering),
    is_portal_enabled: Boolean(accessPoint.is_portal_enabled),
  });
};

const saveEdit = () => {
  router.put(`/admin/access-points/${editingId.value}`, editForm, {
    preserveScroll: true,
    onSuccess: () => {
      editingId.value = null;
    },
  });
};

const deleteAccessPoint = (accessPointId) => {
  if (!window.confirm('Delete this access point?')) return;

  router.delete(`/admin/access-points/${accessPointId}`, {
    preserveScroll: true,
  });
};

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
      <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">Claim and configure APs</h2>
          <p class="mt-1 text-sm text-slate-600">
            This is the single-business inventory. Each AP stores its claim state, SSID, pause behavior, and anti-tethering policy.
            Omada auto-sync runs every minute once local controller sync credentials are saved.
          </p>
        </div>
        <p class="text-sm" :class="props.syncConfigured ? 'text-emerald-700' : 'text-amber-700'">
          {{ props.syncConfigured ? 'Automatic Omada sync is enabled.' : 'Automatic Omada sync is disabled until local controller username/password are saved.' }}
        </p>
      </div>

      <button
        class="mt-4 rounded-md px-4 py-2 text-sm font-semibold"
        :class="props.syncConfigured ? 'bg-slate-900 text-white' : 'cursor-not-allowed bg-slate-200 text-slate-500'"
        :disabled="!props.syncConfigured"
        @click="syncAccessPoints"
      >
        Sync from Omada now
      </button>

      <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <input v-model="form.name" class="rounded-md border-slate-300" placeholder="AP name" />
        <input v-model="form.serial_number" class="rounded-md border-slate-300" placeholder="Serial number" />
        <input v-model="form.mac_address" class="rounded-md border-slate-300" placeholder="MAC address" />
        <input v-model="form.site_name" class="rounded-md border-slate-300" placeholder="Location" />
        <input v-model="form.vendor" class="rounded-md border-slate-300" placeholder="Vendor" />
        <input v-model="form.model" class="rounded-md border-slate-300" placeholder="Model" />
        <input v-model="form.ip_address" class="rounded-md border-slate-300" placeholder="IP address" />
        <input v-model="form.omada_device_id" class="rounded-md border-slate-300" placeholder="Omada device id" />
        <select v-model="form.claim_status" class="rounded-md border-slate-300">
          <option value="claimed">Claimed</option>
          <option value="pending">Pending</option>
          <option value="unclaimed">Unclaimed</option>
          <option value="error">Error</option>
        </select>
        <input v-model="form.custom_ssid" class="rounded-md border-slate-300" placeholder="Primary SSID" />
        <input v-model="form.voucher_ssid_name" class="rounded-md border-slate-300" placeholder="Voucher SSID (optional)" />
      </div>

      <div class="mt-4 flex flex-wrap gap-4 text-sm text-slate-700">
        <label class="inline-flex items-center gap-2">
          <input v-model="form.allow_client_pause" type="checkbox" class="rounded border-slate-300" />
          Allow pause
        </label>
        <label class="inline-flex items-center gap-2">
          <input v-model="form.block_tethering" type="checkbox" class="rounded border-slate-300" />
          Block tethering
        </label>
        <label class="inline-flex items-center gap-2">
          <input v-model="form.is_portal_enabled" type="checkbox" class="rounded border-slate-300" />
          External portal enabled
        </label>
      </div>

      <button class="mt-4 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white" @click="saveCreate">
        Save access point
      </button>
    </div>

    <div class="mt-6 space-y-4">
      <article v-for="accessPoint in props.accessPoints" :key="accessPoint.id" class="rounded-lg bg-white p-5 shadow">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="text-lg font-semibold text-slate-900">{{ accessPoint.name }}</h3>
              <span class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="{
                  'bg-emerald-100 text-emerald-700': accessPoint.claim_status === 'claimed',
                  'bg-amber-100 text-amber-700': accessPoint.claim_status === 'pending',
                  'bg-slate-200 text-slate-700': accessPoint.claim_status === 'unclaimed',
                  'bg-rose-100 text-rose-700': accessPoint.claim_status === 'error',
                }">
                {{ accessPoint.claim_status }}
              </span>
              <span class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="accessPoint.is_online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'">
                {{ accessPoint.is_online ? 'online' : 'offline' }}
              </span>
            </div>
            <p class="mt-1 text-sm text-slate-600">{{ accessPoint.site_name || 'No location assigned' }}</p>
            <div class="mt-3 grid gap-2 text-sm text-slate-700 md:grid-cols-2 xl:grid-cols-4">
              <p><span class="font-medium">MAC:</span> {{ accessPoint.mac_address }}</p>
              <p><span class="font-medium">Serial:</span> {{ accessPoint.serial_number || 'N/A' }}</p>
              <p><span class="font-medium">Model:</span> {{ accessPoint.model || 'N/A' }}</p>
              <p><span class="font-medium">Omada ID:</span> {{ accessPoint.omada_device_id || 'N/A' }}</p>
              <p><span class="font-medium">SSID:</span> {{ accessPoint.custom_ssid || 'N/A' }}</p>
              <p><span class="font-medium">Voucher SSID:</span> {{ accessPoint.voucher_ssid_name || 'N/A' }}</p>
              <p><span class="font-medium">Claimed:</span> {{ accessPoint.claimed_at || 'Not yet' }}</p>
              <p><span class="font-medium">Last seen:</span> {{ accessPoint.last_seen_at || 'Unknown' }}</p>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">
                Pause {{ accessPoint.allow_client_pause ? 'enabled' : 'disabled' }}
              </span>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">
                Anti-tethering {{ accessPoint.block_tethering ? 'enabled' : 'disabled' }}
              </span>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">
                Portal {{ accessPoint.is_portal_enabled ? 'enabled' : 'disabled' }}
              </span>
            </div>
          </div>

          <div class="flex gap-2">
            <button class="rounded-md bg-slate-700 px-3 py-1.5 text-sm font-semibold text-white" @click="startEdit(accessPoint)">Edit</button>
            <button class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-semibold text-white" @click="deleteAccessPoint(accessPoint.id)">Delete</button>
          </div>
        </div>

        <div v-if="editingId === accessPoint.id" class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <input v-model="editForm.name" class="rounded-md border-slate-300" />
          <input v-model="editForm.serial_number" class="rounded-md border-slate-300" />
          <input v-model="editForm.mac_address" class="rounded-md border-slate-300" />
          <input v-model="editForm.site_name" class="rounded-md border-slate-300" />
          <input v-model="editForm.vendor" class="rounded-md border-slate-300" />
          <input v-model="editForm.model" class="rounded-md border-slate-300" />
          <input v-model="editForm.ip_address" class="rounded-md border-slate-300" />
          <input v-model="editForm.omada_device_id" class="rounded-md border-slate-300" />
          <select v-model="editForm.claim_status" class="rounded-md border-slate-300">
            <option value="claimed">Claimed</option>
            <option value="pending">Pending</option>
            <option value="unclaimed">Unclaimed</option>
            <option value="error">Error</option>
          </select>
          <input v-model="editForm.custom_ssid" class="rounded-md border-slate-300" />
          <input v-model="editForm.voucher_ssid_name" class="rounded-md border-slate-300" />
          <div class="flex flex-wrap gap-4 text-sm text-slate-700 md:col-span-2 xl:col-span-3">
            <label class="inline-flex items-center gap-2">
              <input v-model="editForm.allow_client_pause" type="checkbox" class="rounded border-slate-300" />
              Allow pause
            </label>
            <label class="inline-flex items-center gap-2">
              <input v-model="editForm.block_tethering" type="checkbox" class="rounded border-slate-300" />
              Block tethering
            </label>
            <label class="inline-flex items-center gap-2">
              <input v-model="editForm.is_portal_enabled" type="checkbox" class="rounded border-slate-300" />
              External portal enabled
            </label>
          </div>
          <button class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white" @click="saveEdit">Save changes</button>
        </div>
      </article>

      <div v-if="!props.accessPoints.length" class="rounded-lg bg-white p-6 text-center text-sm text-slate-500 shadow">
        No access points have been claimed yet.
      </div>
    </div>
  </MainLayout>
</template>
