<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const currentPath = computed(() => page.url || '');

const breadcrumbs = computed(() => {
  const path = currentPath.value;
  const segments = path.split('/').filter(segment => segment);
  
  if (segments.length === 0) return [];
  
  const crumbs = [];
  
  // Handle admin routes
  if (segments[0] === 'admin') {
    crumbs.push({
      label: 'Admin',
      href: '/admin/dashboard'
    });
    
    // Add specific page breadcrumbs
    if (segments[1]) {
      const pageMap = {
        'dashboard': 'Dashboard',
        'controller': 'Controller Settings',
        'access-points': 'Access Points',
        'plans': 'Promos',
        'sessions': 'Sessions',
        'payments': 'Payments',
        'service-fees': 'Service Fees',
        'operators': 'Operators',
        'knowledge-base': 'Knowledge Base',
        'payout-requests': 'Payouts'
      };
      
      const pageLabel = pageMap[segments[1]] || segments[1].charAt(0).toUpperCase() + segments[1].slice(1);
      crumbs.push({
        label: pageLabel,
        href: `/admin/${segments[1]}`
      });
      
      // Handle nested routes like /admin/operators/123
      if (segments[2]) {
        crumbs.push({
          label: `ID: ${segments[2]}`,
          href: null
        });
      }
    }
  }
  
  // Handle operator routes
  else if (segments[0] === 'operator') {
    crumbs.push({
      label: 'Operator',
      href: '/operator/dashboard'
    });
    
    if (segments[1]) {
      const pageMap = {
        'dashboard': 'Dashboard',
        'devices': 'Devices',
        'payouts': 'Payouts'
      };
      
      const pageLabel = pageMap[segments[1]] || segments[1].charAt(0).toUpperCase() + segments[1].slice(1);
      crumbs.push({
        label: pageLabel,
        href: `/operator/${segments[1]}`
      });
    }
  }
  
  // Handle other routes
  else {
    crumbs.push({
      label: 'Home',
      href: '/'
    });
    
    if (segments[0]) {
      crumbs.push({
        label: segments[0].charAt(0).toUpperCase() + segments[0].slice(1),
        href: null
      });
    }
  }
  
  return crumbs;
});
</script>

<template>
  <nav class="flex items-center space-x-1 text-sm text-slate-500">
    <template v-for="(crumb, index) in breadcrumbs" :key="index">
      <span
        v-if="index === breadcrumbs.length - 1"
        class="font-medium text-slate-900"
      >
        {{ crumb.label }}
      </span>
      <Link
        v-else-if="crumb.href"
        :href="crumb.href"
        class="transition-colors hover:text-slate-700"
      >
        {{ crumb.label }}
      </Link>
      <span v-else class="font-medium text-slate-700">
        {{ crumb.label }}
      </span>
      
      <span
        v-if="index < breadcrumbs.length - 1"
        class="material-symbols-outlined text-[16px] text-slate-400"
      >
        chevron_right
      </span>
    </template>
  </nav>
</template>
