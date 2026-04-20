<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  serviceFee: Object,
  operators: Array,
});

const form = useForm({
  fee_rate: props.serviceFee.fee_rate.toString(),
  description: props.serviceFee.description || '',
});

const submit = () => {
  form.put(`/admin/service-fees/${props.serviceFee.id}`);
};

const formatPercentage = (value) => {
  return (parseFloat(value) * 100).toFixed(2) + '%';
};

const getFeeTypeLabel = (type) => {
  switch (type) {
    case 'site_wide': return 'Site-wide Default';
    case 'operator_specific': return 'Operator-specific';
    case 'revenue_tier': return 'Revenue Tier';
    default: return type;
  }
};
</script>

<template>
  <Head :title="`Edit Service Fee - ${serviceFee.id}`" />

  <MainLayout :title="`Edit Service Fee #${serviceFee.id}`">
    <section class="space-y-6">
      <div>
        <p class="app-kicker">Service Fee Management</p>
        <h1 class="mt-3 app-title">Edit Service Fee Setting</h1>
        <p class="mt-4 app-subtitle">
          Modify the service fee configuration. Only rate and description can be edited.
        </p>
      </div>

      <div class="app-card-strong p-7">
        <form @submit.prevent="submit" class="space-y-6">
          <!-- Fee Information (Read-only) -->
          <div class="rounded-[20px] bg-slate-50 p-5">
            <p class="text-sm font-medium text-slate-950 mb-3">Current Configuration</p>
            <div class="space-y-2 text-sm">
              <p>
                <span class="font-medium">Type:</span> {{ getFeeTypeLabel(serviceFee.type) }}
              </p>
              <p v-if="serviceFee.operator_id">
                <span class="font-medium">Operator:</span> {{ serviceFee.operator?.business_name }}
              </p>
              <p v-if="serviceFee.type === 'revenue_tier'">
                <span class="font-medium">Revenue Range:</span>
                PHP {{ parseFloat(serviceFee.revenue_threshold_min || 0).toLocaleString() }}
                <span v-if="serviceFee.revenue_threshold_max">
                  - PHP {{ parseFloat(serviceFee.revenue_threshold_max).toLocaleString() }}
                </span>
                <span v-else>+</span>
              </p>
              <p>
                <span class="font-medium">Status:</span>
                <span :class="serviceFee.is_active ? 'text-emerald-700' : 'text-slate-600'">
                  {{ serviceFee.is_active ? 'Active' : 'Inactive' }}
                </span>
              </p>
            </div>
          </div>

          <!-- Fee Rate -->
          <div>
            <label class="app-label">Fee Rate</label>
            <div class="flex items-center gap-3">
              <input
                v-model="form.fee_rate"
                type="number"
                step="0.0001"
                min="0"
                max="1"
                class="app-field flex-1"
                required
              />
              <span class="text-sm font-medium text-slate-700">
                {{ formatPercentage(form.fee_rate) }}
              </span>
            </div>
            <p class="mt-2 text-xs text-slate-500">
              Enter as decimal (e.g., 0.05 for 5%, 0.07 for 7%)
            </p>
          </div>

          <!-- Description -->
          <div>
            <label class="app-label">Description (Optional)</label>
            <textarea
              v-model="form.description"
              class="app-field min-h-[100px]"
              placeholder="Internal notes about this fee setting..."
            />
          </div>

          <!-- Preview -->
          <div class="rounded-[20px] bg-slate-50 p-5">
            <p class="text-sm font-medium text-slate-950">Updated Preview</p>
            <div class="mt-3 space-y-2 text-sm">
              <p>
                <span class="font-medium">Type:</span> {{ getFeeTypeLabel(serviceFee.type) }}
              </p>
              <p>
                <span class="font-medium">Rate:</span> {{ formatPercentage(form.fee_rate) }}
              </p>
              <p v-if="serviceFee.operator_id">
                <span class="font-medium">Operator:</span> {{ serviceFee.operator?.business_name }}
              </p>
              <p v-if="serviceFee.type === 'revenue_tier'">
                <span class="font-medium">Revenue Range:</span>
                PHP {{ parseFloat(serviceFee.revenue_threshold_min || 0).toLocaleString() }}
                <span v-if="serviceFee.revenue_threshold_max">
                  - PHP {{ parseFloat(serviceFee.revenue_threshold_max).toLocaleString() }}
                </span>
                <span v-else>+</span>
              </p>
              <p v-if="form.description">
                <span class="font-medium">Description:</span> {{ form.description }}
              </p>
            </div>
          </div>

          <!-- Submit Buttons -->
          <div class="flex gap-3">
            <button type="submit" class="app-button-primary" :disabled="form.processing">
              Update Service Fee
            </button>
            <a href="/admin/service-fees" class="app-button-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </section>
  </MainLayout>
</template>
