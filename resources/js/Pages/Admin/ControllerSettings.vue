<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  controllerSettings: {
    type: Object,
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
  api_client_id: props.controllerSettings.api_client_id || '',
  api_client_secret: '',
  default_session_minutes: props.controllerSettings.default_session_minutes || 60,
});

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
</script>

<template>
  <Head title="Controller Settings" />

  <MainLayout title="Omada Controller Settings">
    <div class="grid gap-6 xl:grid-cols-[2fr,1fr]">
      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Primary controller</h2>
        <p class="mt-1 text-sm text-slate-600">
          Store the controller endpoint and credentials here so the admin can later claim APs and authorize clients after payment.
        </p>

        <form ref="settingsForm" class="contents">
        <div class="mt-5 grid gap-4 md:grid-cols-2">
          <div>
            <label class="text-sm font-medium text-slate-700">Controller name</label>
            <input v-model="form.controller_name" name="controller_name" class="mt-1 w-full rounded-md border-slate-300" autocomplete="off" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Controller URL</label>
            <input v-model="form.base_url" name="base_url" class="mt-1 w-full rounded-md border-slate-300" placeholder="https://controller.example.com" autocomplete="url" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Site identifier</label>
            <input v-model="form.site_identifier" name="site_identifier" class="mt-1 w-full rounded-md border-slate-300" placeholder="Default or Omada site id" autocomplete="off" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Site name</label>
            <input v-model="form.site_name" name="site_name" class="mt-1 w-full rounded-md border-slate-300" placeholder="Main Branch" autocomplete="organization" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Portal base URL</label>
            <input v-model="form.portal_base_url" name="portal_base_url" class="mt-1 w-full rounded-md border-slate-300" placeholder="https://portal.example.com" autocomplete="url" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Default session minutes</label>
            <input v-model="form.default_session_minutes" name="default_session_minutes" type="number" min="1" class="mt-1 w-full rounded-md border-slate-300" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Controller username</label>
            <input v-model="form.username" name="username" class="mt-1 w-full rounded-md border-slate-300" autocomplete="username" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">Controller password</label>
            <input v-model="form.password" name="password" type="password" class="mt-1 w-full rounded-md border-slate-300" autocomplete="current-password" />
            <p class="mt-1 text-xs text-slate-500">
              {{ props.controllerSettings.has_password ? 'Leave blank to keep the current password.' : 'No password saved yet.' }}
            </p>
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">API client ID</label>
            <input v-model="form.api_client_id" name="api_client_id" class="mt-1 w-full rounded-md border-slate-300" autocomplete="off" />
          </div>
          <div>
            <label class="text-sm font-medium text-slate-700">API client secret</label>
            <input v-model="form.api_client_secret" name="api_client_secret" type="password" class="mt-1 w-full rounded-md border-slate-300" autocomplete="off" />
            <p class="mt-1 text-xs text-slate-500">
              {{ props.controllerSettings.has_api_client_secret ? 'Leave blank to keep the current secret.' : 'No API secret saved yet.' }}
            </p>
          </div>
        </div>

        <button type="button" class="mt-5 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white" @click="save">
          Save controller settings
        </button>
        <button type="button" class="mt-5 ml-3 rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="testConnection">
          Save and test connection
        </button>
        </form>
      </section>

      <aside class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Pilot notes</h2>
        <ul class="mt-4 space-y-3 text-sm text-slate-700">
          <li>The controller must stay online if you use Omada external portal authorization.</li>
          <li>Use a public HTTPS URL for the controller, not a LAN-only IP, if your APs will be adopted over the internet.</li>
          <li>Keep the portal and controller on stable domains before you start testing AP claim and client authorization.</li>
          <li>The test button saves the current form values first, then tests the Omada connection.</li>
        </ul>

        <div class="mt-6 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
          <p class="font-medium text-slate-900">Current status</p>
          <p class="mt-2">Last tested: {{ props.controllerSettings.last_tested_at || 'Not tested yet' }}</p>
        </div>
      </aside>
    </div>
  </MainLayout>
</template>
