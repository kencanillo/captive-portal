<script setup>
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

defineProps({
  operator: {
    type: Object,
    required: true,
  },
});
</script>

<template>
  <Head title="Operator Approval Pending" />

  <MainLayout title="Operator Approval Pending">
    <div class="mx-auto max-w-2xl rounded-lg bg-white p-6 shadow">
      <h2 class="text-lg font-semibold text-slate-900">{{ operator.business_name }}</h2>
      <p class="mt-2 text-sm text-slate-600">
        This operator account is still under admin review. Dashboard access stays blocked until the status is approved.
      </p>

      <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
        <p><span class="font-semibold text-slate-900">Status:</span> {{ operator.status }}</p>
        <p v-if="operator.approval_notes" class="mt-2"><span class="font-semibold text-slate-900">Admin notes:</span> {{ operator.approval_notes }}</p>
      </div>
    </div>
  </MainLayout>
</template>
