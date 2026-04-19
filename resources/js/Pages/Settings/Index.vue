<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
  <Head title="Settings" />

  <MainLayout title="Settings">
    <section>
      <p class="app-kicker">System Settings</p>
      <h1 class="mt-3 app-title">Settings</h1>
      <p class="mt-4 app-subtitle">
        This page is intentionally blank for now. Use it later for account preferences or system-wide controls that do not belong in the operational screens.
      </p>
    </section>

    <section class="mt-8">
      <div class="app-empty">
        Settings placeholder.
      </div>
    </section>
  </MainLayout>
</template>
