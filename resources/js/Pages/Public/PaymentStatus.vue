<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  status: {
    type: String,
    required: true,
  },
  session: {
    type: Object,
    required: true,
  },
});
</script>

<template>
  <Head title="Payment status" />

  <MainLayout title="KapitWiFi Payment">
    <div class="mx-auto max-w-xl rounded-lg bg-white p-6 shadow">
      <h2 class="text-xl font-semibold text-slate-900">
        <span v-if="props.status === 'success'">Payment successful</span>
        <span v-else-if="props.status === 'pending'">Payment pending confirmation</span>
        <span v-else>Payment failed</span>
      </h2>

      <p class="mt-3 text-sm text-slate-600">Session #{{ props.session.id }}</p>
      <p class="mt-1 text-sm text-slate-600">Status: <span class="font-semibold uppercase">{{ props.session.payment_status }}</span></p>

      <Link href="/" class="mt-6 inline-block rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">
        Back to portal
      </Link>
    </div>
  </MainLayout>
</template>
