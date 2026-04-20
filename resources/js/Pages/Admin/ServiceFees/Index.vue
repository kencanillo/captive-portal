<script setup>
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  feeSettings: Object,
  operators: Array,
});

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(amount);
};

const formatPercentage = (rate) => {
  return (rate * 100).toFixed(2) + '%';
};
</script>

<template>
  <Head title="Service Fees" />

  <MainLayout title="Service Fees">
    <section class="space-y-6">
      <div>
        <p class="app-kicker">Service Fee Management</p>
        <h1 class="mt-3 app-title">Service Fee Configuration</h1>
        <p class="mt-4 app-subtitle">
          Configure site-wide, operator-specific, and revenue-based service fees. Fees are applied in priority order: operator-specific > revenue tiers > site-wide.
        </p>
      </div>

      <!-- Site-wide Fees -->
      <div class="app-card-strong p-7">
        <div class="flex items-center justify-between">
          <div>
            <p class="app-kicker">Site-wide Default</p>
            <h2 class="mt-3 app-section-title">Default Service Fee</h2>
            <p class="mt-2 text-sm text-slate-600">Applied to all operators unless they have specific or revenue-based fees.</p>
          </div>
          <a href="/admin/service-fees/create" class="app-button-primary">Add Fee Setting</a>
        </div>

        <div class="mt-6 space-y-4">
          <div v-if="feeSettings.site_wide.length === 0" class="app-empty">No site-wide fee configured. Using default 5% rate.</div>
          
          <div
            v-for="fee in feeSettings.site_wide"
            :key="fee.id"
            class="rounded-[20px] border border-slate-200/80 bg-white/80 p-5"
            :class="{ 'border-emerald-300 bg-emerald-50': fee.is_active }"
          >
            <div class="flex items-center justify-between">
              <div>
                <div class="flex items-center gap-3">
                  <span class="text-lg font-semibold text-slate-950">{{ formatPercentage(fee.fee_rate) }}</span>
                  <span
                    v-if="fee.is_active"
                    class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700"
                  >
                    Active
                  </span>
                  <span
                    v-else
                    class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"
                  >
                    Inactive
                  </span>
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ fee.description || 'No description' }}</p>
                <p class="mt-1 text-xs text-slate-500">Created: {{ new Date(fee.created_at).toLocaleDateString() }}</p>
              </div>
              
              <div class="flex gap-2">
                <a :href="`/admin/service-fees/${fee.id}/edit`" class="app-button-secondary">Edit</a>
                <form
                  v-if="fee.is_active"
                  :action="`/admin/service-fees/${fee.id}/deactivate`"
                  method="POST"
                  class="inline"
                >
                  <button type="submit" class="app-button-secondary">Deactivate</button>
                </form>
                <form
                  v-else
                  :action="`/admin/service-fees/${fee.id}/activate`"
                  method="POST"
                  class="inline"
                >
                  <button type="submit" class="app-button-primary">Activate</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Operator-specific Fees -->
      <div class="app-card-strong p-7">
        <div>
          <p class="app-kicker">Operator-specific</p>
          <h2 class="mt-3 app-section-title">Custom Operator Rates</h2>
          <p class="mt-2 text-sm text-slate-600">Special rates for specific operators that override all other fees.</p>
        </div>

        <div class="mt-6 space-y-4">
          <div v-if="feeSettings.operator_specific.length === 0" class="app-empty">No operator-specific fees configured.</div>
          
          <div
            v-for="fee in feeSettings.operator_specific"
            :key="fee.id"
            class="rounded-[20px] border border-slate-200/80 bg-white/80 p-5"
            :class="{ 'border-emerald-300 bg-emerald-50': fee.is_active }"
          >
            <div class="flex items-center justify-between">
              <div>
                <div class="flex items-center gap-3">
                  <span class="text-lg font-semibold text-slate-950">{{ formatPercentage(fee.fee_rate) }}</span>
                  <span
                    v-if="fee.is_active"
                    class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700"
                  >
                    Active
                  </span>
                  <span
                    v-else
                    class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"
                  >
                    Inactive
                  </span>
                </div>
                <p class="mt-2 text-sm font-medium text-slate-950">{{ fee.operator?.business_name || 'Unknown Operator' }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ fee.description || 'No description' }}</p>
                <p class="mt-1 text-xs text-slate-500">Created: {{ new Date(fee.created_at).toLocaleDateString() }}</p>
              </div>
              
              <div class="flex gap-2">
                <a :href="`/admin/service-fees/${fee.id}/edit`" class="app-button-secondary">Edit</a>
                <form
                  v-if="fee.is_active"
                  :action="`/admin/service-fees/${fee.id}/deactivate`"
                  method="POST"
                  class="inline"
                >
                  <button type="submit" class="app-button-secondary">Deactivate</button>
                </form>
                <form
                  v-else
                  :action="`/admin/service-fees/${fee.id}/activate`"
                  method="POST"
                  class="inline"
                >
                  <button type="submit" class="app-button-primary">Activate</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Revenue Tiers -->
      <div class="app-card-strong p-7">
        <div>
          <p class="app-kicker">Revenue Tiers</p>
          <h2 class="mt-3 app-section-title">Progressive Fee Structure</h2>
          <p class="mt-2 text-sm text-slate-600">Tiered fees based on operator revenue ranges.</p>
        </div>

        <div class="mt-6 space-y-4">
          <div v-if="feeSettings.revenue_tiers.length === 0" class="app-empty">No revenue tiers configured.</div>
          
          <div
            v-for="fee in feeSettings.revenue_tiers"
            :key="fee.id"
            class="rounded-[20px] border border-slate-200/80 bg-white/80 p-5"
            :class="{ 'border-emerald-300 bg-emerald-50': fee.is_active }"
          >
            <div class="flex items-center justify-between">
              <div>
                <div class="flex items-center gap-3">
                  <span class="text-lg font-semibold text-slate-950">{{ formatPercentage(fee.fee_rate) }}</span>
                  <span
                    v-if="fee.is_active"
                    class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700"
                  >
                    Active
                  </span>
                  <span
                    v-else
                    class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600"
                  >
                    Inactive
                  </span>
                </div>
                <p class="mt-2 text-sm font-medium text-slate-950">
                  Revenue: 
                  <span v-if="fee.revenue_threshold_min">PHP {{ formatCurrency(fee.revenue_threshold_min) }}</span>
                  <span v-else>PHP 0</span>
                  -
                  <span v-if="fee.revenue_threshold_max">PHP {{ formatCurrency(fee.revenue_threshold_max) }}</span>
                  <span v-else>Unlimited</span>
                </p>
                <p class="mt-1 text-sm text-slate-600">{{ fee.description || 'No description' }}</p>
                <p class="mt-1 text-xs text-slate-500">Created: {{ new Date(fee.created_at).toLocaleDateString() }}</p>
              </div>
              
              <div class="flex gap-2">
                <a :href="`/admin/service-fees/${fee.id}/edit`" class="app-button-secondary">Edit</a>
                <form
                  v-if="fee.is_active"
                  :action="`/admin/service-fees/${fee.id}/deactivate`"
                  method="POST"
                  class="inline"
                >
                  <button type="submit" class="app-button-secondary">Deactivate</button>
                </form>
                <form
                  v-else
                  :action="`/admin/service-fees/${fee.id}/activate`"
                  method="POST"
                  class="inline"
                >
                  <button type="submit" class="app-button-primary">Activate</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
