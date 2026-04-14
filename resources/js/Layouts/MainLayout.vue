<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

defineProps({
  title: {
    type: String,
    default: 'KapitWiFi',
  },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
const isAdmin = computed(() => Boolean(user.value?.is_admin));

const logout = () => {
  window.axios.post('/admin/logout')
    .then(() => {
      window.location.href = '/admin/login';
    })
    .catch(error => {
      console.error('Logout failed:', error);
    });
};
</script>

<template>
  <div class="min-h-screen bg-slate-100">
    <header class="bg-slate-900 text-white shadow-sm">
      <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between lg:w-full">
          <h1 class="text-xl font-semibold">{{ title }}</h1>
          <div class="flex flex-wrap items-center gap-4">
            <nav v-if="isAdmin" class="flex flex-wrap gap-2 text-sm">
              <Link href="/admin/dashboard" class="rounded-md px-3 py-1.5 transition hover:bg-slate-800">Dashboard</Link>
              <Link href="/admin/controller" class="rounded-md px-3 py-1.5 transition hover:bg-slate-800">Controller</Link>
              <Link href="/admin/access-points" class="rounded-md px-3 py-1.5 transition hover:bg-slate-800">Access Points</Link>
              <Link href="/admin/plans" class="rounded-md px-3 py-1.5 transition hover:bg-slate-800">Promos</Link>
              <Link href="/admin/sessions" class="rounded-md px-3 py-1.5 transition hover:bg-slate-800">Sessions</Link>
              <Link href="/admin/payments" class="rounded-md px-3 py-1.5 transition hover:bg-slate-800">Payments</Link>
            </nav>
            <div v-if="user" class="flex items-center gap-3 text-sm">
              <span class="text-slate-300">{{ user.name }}</span>
              <button 
                @click="logout"
                class="rounded-md px-3 py-1.5 bg-red-600 hover:bg-red-700 transition font-medium"
              >
                Logout
              </button>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
      <p v-if="flashSuccess" class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ flashSuccess }}
      </p>
      <p v-if="flashError" class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ flashError }}
      </p>
      <slot />
    </main>
  </div>
</template>
