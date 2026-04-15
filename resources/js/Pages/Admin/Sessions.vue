<script setup>
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  sessions: {
    type: Object,
    required: true,
  },
});
</script>

<template>
  <Head title="Sessions" />

  <MainLayout title="WiFi Sessions">
    <div class="overflow-x-auto rounded-lg bg-white p-5 shadow">
      <table class="min-w-full text-left text-sm">
        <thead>
          <tr class="border-b">
            <th class="px-2 py-2">ID</th>
            <th class="px-2 py-2">Client</th>
            <th class="px-2 py-2">Site</th>
            <th class="px-2 py-2">Access Point</th>
            <th class="px-2 py-2">SSID</th>
            <th class="px-2 py-2">Plan</th>
            <th class="px-2 py-2">Status</th>
            <th class="px-2 py-2">Active</th>
            <th class="px-2 py-2">Time Left</th>
            <th class="px-2 py-2">Ends</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in props.sessions.data" :key="item.id" class="border-b border-slate-100">
            <td class="px-2 py-2">{{ item.id }}</td>
            <td class="px-2 py-2">
              <p class="font-medium text-slate-900">{{ item.client?.name || 'Unknown client' }}</p>
              <p v-if="item.client?.phone_number" class="text-xs text-slate-500">{{ item.client.phone_number }}</p>
              <p class="text-xs text-slate-500">{{ item.mac_address }}</p>
            </td>
            <td class="px-2 py-2">{{ item.site?.name || '-' }}</td>
            <td class="px-2 py-2">
              <p>{{ item.access_point?.name || item.ap_name || '-' }}</p>
              <p v-if="item.ap_mac" class="text-xs text-slate-500">{{ item.ap_mac }}</p>
            </td>
            <td class="px-2 py-2">{{ item.ssid_name || '-' }}</td>
            <td class="px-2 py-2">{{ item.plan?.name }}</td>
            <td class="px-2 py-2 uppercase">{{ item.payment_status }}</td>
            <td class="px-2 py-2">{{ item.is_active ? 'Yes' : 'No' }}</td>
            <td class="px-2 py-2 font-medium">{{ item.remaining_time }}</td>
            <td class="px-2 py-2">{{ item.end_time || '-' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </MainLayout>
</template>
