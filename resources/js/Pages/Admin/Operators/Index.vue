<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

defineProps({
  operators: Array,
});
</script>

<template>
  <Head title="Operators" />

  <MainLayout title="Operators">
    <div class="rounded-lg bg-white p-5 shadow">
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-slate-500">
              <th class="px-2 py-2">Operator</th>
              <th class="px-2 py-2">Contact</th>
              <th class="px-2 py-2">Assigned sites</th>
              <th class="px-2 py-2">Status</th>
              <th class="px-2 py-2">Balance</th>
              <th class="px-2 py-2">Revenue</th>
              <th class="px-2 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="operator in operators" :key="operator.id" class="border-b border-slate-100">
              <td class="px-2 py-3">
                <p class="font-medium text-slate-900">{{ operator.business_name }}</p>
                <p class="text-xs text-slate-500">{{ operator.requested_site_name || 'No site request' }}</p>
              </td>
              <td class="px-2 py-3">
                <p>{{ operator.contact_name }}</p>
                <p class="text-xs text-slate-500">{{ operator.email }} · {{ operator.phone_number }}</p>
              </td>
              <td class="px-2 py-3">{{ operator.sites.join(', ') || 'Unassigned' }}</td>
              <td class="px-2 py-3">{{ operator.status }}</td>
              <td class="px-2 py-3">₱{{ operator.available_balance }}</td>
              <td class="px-2 py-3">₱{{ operator.revenue_total }}</td>
              <td class="px-2 py-3">
                <Link :href="`/admin/operators/${operator.id}`" class="font-semibold text-slate-900 underline">Open</Link>
              </td>
            </tr>
            <tr v-if="!operators.length">
              <td colspan="7" class="px-2 py-6 text-center text-slate-500">No operators yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </MainLayout>
</template>
