<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';

const props = defineProps({
  title: {
    type: String,
    default: 'Captive Portal',
  },
});

const page = usePage();
const navOpen = ref(false);

const user = computed(() => page.props.auth?.user || null);
const currentPath = computed(() => page.url || '');
const isAdmin = computed(() => Boolean(user.value?.is_admin));
const isOperator = computed(() => Boolean(user.value?.can_access_operator_panel));

const adminNavigationGroups = [
  {
    label: 'Overview',
    icon: 'dashboard',
    items: [
      { label: 'Dashboard', href: '/admin/dashboard', icon: 'dashboard' }
    ]
  },
  {
    label: 'Network Management',
    icon: 'router',
    items: [
      { label: 'Controller', href: '/admin/controller', icon: 'settings_input_component' },
      { label: 'Access Points', href: '/admin/access-points', icon: 'router' }
    ]
  },
  {
    label: 'Business Operations',
    icon: 'sell',
    items: [
      { label: 'Promos', href: '/admin/plans', icon: 'sell' },
      { label: 'Sessions', href: '/admin/sessions', icon: 'wifi_find' },
      { label: 'Payments', href: '/admin/payments', icon: 'payments' },
      { label: 'Service Fees', href: '/admin/service-fees', icon: 'percent' },
      { label: 'Payouts', href: '/admin/payout-requests', icon: 'account_balance_wallet' }
    ]
  },
  {
    label: 'User Management',
    icon: 'groups',
    items: [
      { label: 'Operators', href: '/admin/operators', icon: 'groups' },
      { label: 'Knowledge Base', href: '/admin/knowledge-base', icon: 'menu_book' }
    ]
  }
];

const operatorNavigation = [
  { label: 'Dashboard', href: '/operator/dashboard', icon: 'dashboard' },
  { label: 'Devices', href: '/operator/devices', icon: 'router' },
  { label: 'Payouts', href: '/operator/payouts', icon: 'payments' },
];

const accountNavigation = [
  { label: 'Profile', href: '/profile', icon: 'person' },
  { label: 'Settings', href: '/settings', icon: 'settings' },
];

// Collapsible state for navigation groups
const collapsedGroups = ref({});

const navigation = computed(() => (isAdmin.value ? adminNavigationGroups : operatorNavigation));
const workspaceLabel = computed(() => (isAdmin.value ? 'Admin Authority' : 'Operator Workspace'));
const workspaceNote = computed(() => (isAdmin.value ? 'Network control plane' : 'Site-scoped operations'));

const isActive = (href) => {
  if (href === '/admin/dashboard' || href === '/operator/dashboard') {
    return currentPath.value === href;
  }

  return currentPath.value.startsWith(href);
};

const closeNav = () => {
  navOpen.value = false;
};

const toggleGroup = (groupLabel) => {
  collapsedGroups.value[groupLabel] = !collapsedGroups.value[groupLabel];
};

const isGroupCollapsed = (groupLabel) => {
  return collapsedGroups.value[groupLabel] || false;
};

const logout = () => {
  window.axios.post('/admin/logout')
    .then(() => {
      window.location.href = '/admin/login';
    })
    .catch((error) => {
      console.error('Logout failed:', error);
    });
};
</script>

<template>
  <div class="min-h-screen">
    <div
      v-if="navOpen"
      class="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-sm lg:hidden"
      @click="closeNav"
    />

    <aside
      class="fixed inset-y-0 left-0 z-50 w-72 transform overflow-y-auto px-5 py-6 transition duration-300 lg:translate-x-0"
      :class="navOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex h-full flex-col rounded-[32px] bg-[linear-gradient(180deg,#131b2e_0%,#1a243b_100%)] px-5 py-6 shadow-[40px_0_80px_-32px_rgba(19,27,46,0.9)]">
        <div class="px-3">
          <p class="text-[11px] font-bold uppercase tracking-[0.3em] text-sky-200/70">Captive Portal</p>
          <h1 class="mt-3 text-2xl font-extrabold tracking-[-0.05em] text-white">Captive Portal</h1>
          <p class="mt-3 max-w-[15rem] text-sm leading-6 text-slate-400">
            {{ isAdmin ? 'Omada operations, operators, billing, and payout review.' : 'Your sites, devices, sessions, and payout activity.' }}
          </p>
        </div>

        <nav class="mt-8 flex-1 space-y-2">
          <!-- Admin navigation groups -->
          <template v-if="isAdmin">
            <div
              v-for="group in navigation"
              :key="group.label"
              class="space-y-1"
            >
              <!-- Group header -->
              <button
                @click="toggleGroup(group.label)"
                class="flex w-full items-center justify-between rounded-2xl px-4 py-2.5 text-sm font-semibold tracking-[0.01em] transition text-slate-300 hover:bg-white/6 hover:text-white"
              >
                <div class="flex items-center gap-3">
                  <span class="material-symbols-outlined text-[18px]">{{ group.icon }}</span>
                  <span>{{ group.label }}</span>
                </div>
                <span
                  class="material-symbols-outlined text-[16px] transition-transform duration-200"
                  :class="{ 'rotate-180': !isGroupCollapsed(group.label) }"
                >
                  expand_more
                </span>
              </button>
              
              <!-- Group items -->
              <div
                v-show="!isGroupCollapsed(group.label)"
                class="ml-2 space-y-1"
              >
                <Link
                  v-for="item in group.items"
                  :key="item.href"
                  :href="item.href"
                  class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium tracking-[0.01em] transition"
                  :class="isActive(item.href)
                    ? 'bg-white/12 text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.15)]'
                    : 'text-slate-400 hover:bg-white/6 hover:text-white'"
                  @click="closeNav"
                >
                  <span class="material-symbols-outlined text-[18px]">{{ item.icon }}</span>
                  <span>{{ item.label }}</span>
                </Link>
              </div>
            </div>
          </template>
          
          <!-- Operator navigation (flat) -->
          <template v-else>
            <Link
              v-for="item in navigation"
              :key="item.href"
              :href="item.href"
              class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold tracking-[0.01em] transition"
              :class="isActive(item.href)
                ? 'bg-white/12 text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.15)]'
                : 'text-slate-400 hover:bg-white/6 hover:text-white'"
              @click="closeNav"
            >
              <span class="material-symbols-outlined text-[20px]">{{ item.icon }}</span>
              <span>{{ item.label }}</span>
            </Link>
          </template>
        </nav>

        <div class="mt-2 border-t border-white/10 pt-5">
          <p class="px-4 text-[10px] font-bold uppercase tracking-[0.24em] text-slate-500">Account</p>
          <div class="mt-3 space-y-2">
            <Link
              v-for="item in accountNavigation"
              :key="item.href"
              :href="item.href"
              class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold tracking-[0.01em] transition"
              :class="isActive(item.href)
                ? 'bg-white/12 text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.15)]'
                : 'text-slate-400 hover:bg-white/6 hover:text-white'"
              @click="closeNav"
            >
              <span class="material-symbols-outlined text-[20px]">{{ item.icon }}</span>
              <span>{{ item.label }}</span>
            </Link>
          </div>
        </div>

        <div class="mt-8 rounded-[24px] border border-white/10 bg-white/6 px-4 py-4">
          <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-sky-200/60">{{ workspaceLabel }}</p>
          <p class="mt-2 text-sm font-semibold text-white">{{ user?.operator_business_name || user?.name || 'Portal user' }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ workspaceNote }}</p>
        </div>

        <button
          class="mt-4 inline-flex items-center justify-center gap-2 rounded-full border border-white/10 bg-white/8 px-4 py-3 text-sm font-semibold text-slate-200 transition hover:bg-white/14 hover:text-white"
          @click="logout"
        >
          <span class="material-symbols-outlined text-[18px]">logout</span>
          <span>Logout</span>
        </button>
      </div>
    </aside>

    <div class="lg:pl-72">
      <header class="fixed inset-x-0 top-0 z-30 px-4 py-4 lg:left-72 lg:px-8">
        <div class="mx-auto flex max-w-[1600px] items-center justify-between rounded-[26px] border border-white/60 bg-white/72 px-4 py-3 shadow-[0_24px_60px_-40px_rgba(19,27,46,0.35)] backdrop-blur-xl sm:px-6">
          <div class="flex items-center gap-3 sm:gap-4">
            <button
              class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-700 lg:hidden"
              @click="navOpen = true"
            >
              <span class="material-symbols-outlined text-[20px]">menu</span>
            </button>
            <div class="flex-1">
              <p class="text-[11px] font-bold uppercase tracking-[0.28em] text-slate-400">WiFi Management</p>
              <div class="mt-1 flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-2">
                <h2 class="text-lg font-semibold tracking-[-0.03em] text-slate-950">{{ props.title }}</h2>
                <Breadcrumbs class="text-sm" />
              </div>
            </div>
          </div>

          <div class="hidden items-center gap-4 md:flex">
            <div class="flex items-center gap-3 rounded-full border border-slate-200/70 bg-slate-50/80 px-4 py-2.5">
              <span class="material-symbols-outlined text-[18px] text-slate-400">search</span>
              <span class="text-sm text-slate-500">Search is staged into the redesign shell.</span>
            </div>
            <div class="rounded-full border border-slate-200/70 bg-white px-4 py-2 text-sm font-medium text-slate-600">
              {{ user?.email || 'Authenticated user' }}
            </div>
          </div>
        </div>
      </header>

      <main class="mx-auto max-w-[1600px] px-4 pb-10 pt-28 sm:px-6 lg:px-8">
        <slot />
      </main>
    </div>
  </div>
</template>
