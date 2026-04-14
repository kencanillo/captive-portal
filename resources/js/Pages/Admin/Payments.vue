<script setup>
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  payments: {
    type: Object,
    required: true,
  },
});
</script>

<template>
  <Head title="Payments" />

  <MainLayout title="Payments">
    <div class="overflow-x-auto rounded-lg bg-white p-5 shadow">
      <table class="min-w-full text-left text-sm">
        <thead>
          <tr class="border-b">
            <th class="px-2 py-2">ID</th>
            <th class="px-2 py-2">Session</th>
            <th class="px-2 py-2">Site</th>
            <th class="px-2 py-2">Access Point</th>
            <th class="px-2 py-2">Provider</th>
            <th class="px-2 py-2">Reference</th>
            <th class="px-2 py-2">Status</th>
            <th class="px-2 py-2">Created</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="payment in props.payments.data" :key="payment.id" class="border-b border-slate-100">
            <td class="px-2 py-2">{{ payment.id }}</td>
            <td class="px-2 py-2">{{ payment.wifi_session_id }}</td>
            <td class="px-2 py-2">{{ payment.wifi_session?.site?.name || '-' }}</td>
            <td class="px-2 py-2">{{ payment.wifi_session?.access_point?.name || payment.wifi_session?.ap_name || '-' }}</td>
            <td class="px-2 py-2">{{ payment.provider }}</td>
            <td class="px-2 py-2">{{ payment.reference_id }}</td>
            <td class="px-2 py-2 uppercase">{{ payment.status }}</td>
            <td class="px-2 py-2">{{ payment.created_at }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </MainLayout>
</template>
