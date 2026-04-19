<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  controllerSettings: {
    type: Object,
    required: true,
  },
  canSyncSites: {
    type: Boolean,
    required: true,
  },
  syncedSites: {
    type: Array,
    required: true,
  },
});

const settingsForm = ref(null);

const form = reactive({
  controller_name: props.controllerSettings.controller_name || '',
  base_url: props.controllerSettings.base_url || '',
  site_identifier: props.controllerSettings.site_identifier || '',
  site_name: props.controllerSettings.site_name || '',
  portal_base_url: props.controllerSettings.portal_base_url || '',
  username: props.controllerSettings.username || '',
  password: '',
  hotspot_operator_username: props.controllerSettings.hotspot_operator_username || '',
  hotspot_operator_password: '',
  api_client_id: props.controllerSettings.api_client_id || '',
  api_client_secret: '',
  default_session_minutes: props.controllerSettings.default_session_minutes || 60,
});

const selectedSyncedSiteId = ref(
  props.syncedSites.find((site) => site.omada_site_id === props.controllerSettings.site_identifier)?.id || '',
);

const buildPayload = () => {
  if (!settingsForm.value) {
    return { ...form };
  }

  const formData = new FormData(settingsForm.value);

  return {
    controller_name: String(formData.get('controller_name') ?? form.controller_name ?? ''),
    base_url: String(formData.get('base_url') ?? form.base_url ?? ''),
    site_identifier: String(formData.get('site_identifier') ?? form.site_identifier ?? ''),
    site_name: String(formData.get('site_name') ?? form.site_name ?? ''),
    portal_base_url: String(formData.get('portal_base_url') ?? form.portal_base_url ?? ''),
    username: String(formData.get('username') ?? form.username ?? ''),
    password: String(formData.get('password') ?? ''),
    hotspot_operator_username: String(formData.get('hotspot_operator_username') ?? form.hotspot_operator_username ?? ''),
    hotspot_operator_password: String(formData.get('hotspot_operator_password') ?? ''),
    api_client_id: String(formData.get('api_client_id') ?? form.api_client_id ?? ''),
    api_client_secret: String(formData.get('api_client_secret') ?? ''),
    default_session_minutes: Number(formData.get('default_session_minutes') ?? form.default_session_minutes ?? 60),
  };
};

const save = () => {
  router.put('/admin/controller', buildPayload(), {
    preserveScroll: true,
  });
};

const testConnection = () => {
  router.post('/admin/controller/test-connection', buildPayload(), {
    preserveScroll: true,
  });
};

const syncSites = () => {
  router.post('/admin/controller/sync-sites', buildPayload(), {
    preserveScroll: true,
  });
};

const applySyncedSite = () => {
  const selectedSite = props.syncedSites.find((site) => String(site.id) === String(selectedSyncedSiteId.value));

  form.site_identifier = selectedSite?.omada_site_id || '';
  form.site_name = selectedSite?.name || '';
};
</script>

<template>
  <Head title="Controller Settings" />

  <MainLayout title="Controller Settings">
    <section class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <p class="app-kicker">System Architecture</p>
        <h1 class="mt-3 app-title">Omada controller authority</h1>
        <p class="mt-4 app-subtitle">
          This page defines the control plane. Base URL, credentials, hotspot operator access, synced sites, and portal base URL all belong here. Stop scattering controller state across the app.
        </p>
      </div>

      <div class="flex flex-wrap gap-3">
        <button class="app-button-secondary" @click="testConnection">
          <span class="material-symbols-outlined text-[18px]">wifi_tethering</span>
          Save and test
        </button>
        <button class="app-button-primary" :disabled="!props.canSyncSites" @click="syncSites">
          <span class="material-symbols-outlined text-[18px]">sync</span>
          Sync sites
        </button>
      </div>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[1.2fr,0.8fr]">
      <div class="app-card-strong p-7 sm:p-8">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="app-kicker">Primary Controller</p>
            <h2 class="mt-3 app-section-title">Connectivity, sites, and authority credentials</h2>
          </div>
          <span class="app-badge bg-emerald-100 text-emerald-700">Control node</span>
        </div>

        <form ref="settingsForm" class="mt-8" @submit.prevent="save">
          <div class="grid gap-5 md:grid-cols-2">
            <div>
              <label class="app-label">Controller Name</label>
              <input v-model="form.controller_name" name="controller_name" class="app-field" autocomplete="off" />
            </div>
            <div>
              <label class="app-label">Controller URL</label>
              <input v-model="form.base_url" name="base_url" class="app-field" placeholder="https://controller.example.com" autocomplete="url" />
            </div>
            <div>
              <label class="app-label">Synced Omada Site</label>
              <select v-model="selectedSyncedSiteId" class="app-field" @change="applySyncedSite">
                <option value="">No default site selected</option>
                <option v-for="site in props.syncedSites" :key="site.id" :value="site.id">
                  {{ site.name }} ({{ site.omada_site_id }})
                </option>
              </select>
              <p class="mt-2 text-sm text-slate-500">The default site must come from the Omada sync inventory, not from ad-hoc database rows.</p>
            </div>
            <div>
              <label class="app-label">Site Name</label>
              <input v-model="form.site_name" name="site_name" class="app-field" placeholder="Main Branch" autocomplete="organization" />
            </div>
            <div>
              <label class="app-label">Site Identifier</label>
              <input v-model="form.site_identifier" name="site_identifier" class="app-field" placeholder="Omada site ID" autocomplete="off" />
            </div>
            <div>
              <label class="app-label">Default Session Minutes</label>
              <input v-model="form.default_session_minutes" name="default_session_minutes" type="number" min="1" class="app-field" />
            </div>
            <div class="md:col-span-2">
              <label class="app-label">Portal Base URL</label>
              <input v-model="form.portal_base_url" name="portal_base_url" class="app-field" placeholder="https://portal.example.com" autocomplete="url" />
            </div>
          </div>

          <div class="mt-8 rounded-[24px] border border-slate-200/80 bg-slate-50/70 p-5">
            <p class="app-kicker">Authority Credentials</p>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
              <div>
                <label class="app-label">Controller Username</label>
                <input v-model="form.username" name="username" class="app-field" autocomplete="username" />
              </div>
              <div>
                <label class="app-label">Controller Password</label>
                <input v-model="form.password" name="password" type="password" class="app-field" autocomplete="current-password" />
                <p class="mt-2 text-sm text-slate-500">
                  {{ props.controllerSettings.has_password ? 'Leave blank to keep the saved password.' : 'No password has been stored yet.' }}
                </p>
              </div>
              <div>
                <label class="app-label">Hotspot Operator Username</label>
                <input v-model="form.hotspot_operator_username" name="hotspot_operator_username" class="app-field" autocomplete="username" />
              </div>
              <div>
                <label class="app-label">Hotspot Operator Password</label>
                <input v-model="form.hotspot_operator_password" name="hotspot_operator_password" type="password" class="app-field" autocomplete="current-password" />
                <p class="mt-2 text-sm text-slate-500">
                  {{ props.controllerSettings.has_hotspot_operator_password ? 'Leave blank to keep the saved operator password.' : 'No hotspot operator password saved yet.' }}
                </p>
              </div>
              <div>
                <label class="app-label">API Client ID</label>
                <input v-model="form.api_client_id" name="api_client_id" class="app-field" autocomplete="off" />
              </div>
              <div>
                <label class="app-label">API Client Secret</label>
                <input v-model="form.api_client_secret" name="api_client_secret" type="password" class="app-field" autocomplete="off" />
                <p class="mt-2 text-sm text-slate-500">
                  {{ props.controllerSettings.has_api_client_secret ? 'Leave blank to keep the saved API secret.' : 'No API client secret saved yet.' }}
                </p>
              </div>
            </div>
          </div>

          <div class="mt-8 flex flex-wrap justify-end gap-3">
            <button type="button" class="app-button-secondary" @click="testConnection">
              Save and test connection
            </button>
            <button type="submit" class="app-button-primary">
              Save controller settings
            </button>
          </div>
        </form>
      </div>

      <aside class="space-y-6">
        <div class="app-card p-7">
          <p class="app-kicker">Current Status</p>
          <h2 class="mt-3 app-section-title">Connection posture</h2>
          <div class="mt-6 space-y-4">
            <div class="app-panel">
              <p class="app-metric-label">Last Tested</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ props.controllerSettings.last_tested_at || 'Not tested yet' }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Synced Sites</p>
              <p class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-slate-950">{{ props.syncedSites.length }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Default Site</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ form.site_name || 'No default site selected' }}</p>
              <p class="mt-2 break-all text-sm text-slate-500">{{ form.site_identifier || 'No Omada site ID mapped yet' }}</p>
            </div>
          </div>
        </div>

        <div class="app-card-dark p-7">
          <p class="app-top-stat">
            <span class="material-symbols-outlined text-[16px]">warning</span>
            Deployment notes
          </p>
          <ul class="mt-6 space-y-4 text-sm leading-7 text-slate-300">
            <li>The controller must be reachable over the correct public URL if APs and portal callbacks run outside the LAN.</li>
            <li>Hotspot operator credentials should be distinct when Omada permissions need tighter separation than the main admin account.</li>
            <li>Site assignment for operators must come from the synced Omada site list. Anything else is bad data hygiene.</li>
          </ul>
        </div>
      </aside>
    </section>
  </MainLayout>
</template>
