<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

defineProps({
  pendingDevices: Array,
  connectedDevices: Array,
  failedDevices: Array,
});

const csrfToken = usePage().props.csrf_token;
</script>

<template>
  <Head title="Device Management" />

  <MainLayout title="Device Management">
    <div class="space-y-6">
      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Pending Devices</h2>
        <div class="mt-4">
          <div v-if="pendingDevices.length" class="space-y-3">
            <article v-for="device in pendingDevices" :key="device.id" class="flex items-center justify-between rounded-md border border-slate-200 px-4 py-3">
              <div>
                <p class="font-medium text-slate-900">{{ device.name }}</p>
                <p class="text-sm text-slate-500">{{ device.mac_address }} • {{ device.model }} • {{ device.site_name }}</p>
              </div>
              <form method="POST" :action="route('operator.devices.adopt')" class="inline">
                <input type="hidden" name="_token" :value="csrfToken" />
                <input type="hidden" name="access_point_id" :value="device.id" />
                <button type="submit" class="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700">
                  Adopt
                </button>
              </form>
            </article>
          </div>
          <p v-else class="text-sm text-slate-500">No pending devices.</p>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Connected Devices</h2>
        <div class="mt-4">
          <div v-if="connectedDevices.length" class="space-y-3">
            <article v-for="device in connectedDevices" :key="device.id" class="rounded-md border border-slate-200 px-4 py-3">
              <p class="font-medium text-slate-900">{{ device.name }}</p>
              <p class="text-sm text-slate-500">{{ device.mac_address }} • {{ device.model }} • {{ device.site_name }}</p>
            </article>
          </div>
          <p v-else class="text-sm text-slate-500">No connected devices.</p>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Failed Devices</h2>
        <div class="mt-4">
          <div v-if="failedDevices.length" class="space-y-3">
            <article v-for="device in failedDevices" :key="device.id" class="rounded-md border border-slate-200 px-4 py-3">
              <p class="font-medium text-slate-900">{{ device.name }}</p>
              <p class="text-sm text-slate-500">{{ device.mac_address }} • {{ device.model }} • {{ device.site_name }}</p>
            </article>
          </div>
          <p v-else class="text-sm text-slate-500">No failed devices.</p>
        </div>
      </section>
    </div>
  </MainLayout>
</template>