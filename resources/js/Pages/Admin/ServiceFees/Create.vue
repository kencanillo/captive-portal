<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  operators: Array,
});

const form = useForm({
  type: 'site_wide',
  operator_id: '',
  fee_rate: '0.05',
  revenue_threshold_min: '',
  revenue_threshold_max: '',
  description: '',
});

const submit = () => {
  form.post('/admin/service-fees');
};

const formatPercentage = (value) => {
  return (parseFloat(value) * 100).toFixed(2) + '%';
};
</script>

<template>
  <Head title="Create Service Fee" />

  <MainLayout title="Create Service Fee">
    <section class="space-y-6">
      <div>
        <p class="app-kicker">Service Fee Management</p>
        <h1 class="mt-3 app-title">Create Service Fee Setting</h1>
        <p class="mt-4 app-subtitle">
          Add a new service fee configuration. Choose the type and specify the rate and conditions.
        </p>
      </div>

      <div class="app-card-strong p-7">
        <form @submit.prevent="submit" class="space-y-6">
          <!-- Fee Type -->
          <div>
            <label class="app-label">Fee Type</label>
            <select v-model="form.type" class="app-field">
              <option value="site_wide">Site-wide Default</option>
              <option value="operator_specific">Operator-specific</option>
              <option value="revenue_tier">Revenue Tier</option>
            </select>
            <p class="mt-2 text-xs text-slate-500">
              <span v-if="form.type === 'site_wide'">
                Applied to all operators unless overridden by specific fees.
              </span>
              <span v-else-if="form.type === 'operator_specific'">
                Custom rate for a specific operator that overrides all other fees.
              </span>
              <span v-else>
                Tiered rate based on operator revenue range.
              </span>
            </p>
          </div>

          <!-- Operator Selection (for operator-specific) -->
          <div v-if="form.type === 'operator_specific'">
            <label class="app-label">Operator</label>
            <select v-model="form.operator_id" class="app-field" required>
              <option value="">Select an operator</option>
              <option v-for="operator in operators" :key="operator.id" :value="operator.id">
                {{ operator.business_name }}
              </option>
            </select>
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

          <!-- Revenue Thresholds (for revenue tiers) -->
          <div v-if="form.type === 'revenue_tier'" class="space-y-4">
            <div>
              <label class="app-label">Minimum Revenue Threshold</label>
              <input
                v-model="form.revenue_threshold_min"
                type="number"
                step="0.01"
                min="0"
                class="app-field"
                required
              />
              <p class="mt-2 text-xs text-slate-500">Minimum revenue to apply this rate (e.g., 10000.00)</p>
            </div>

            <div>
              <label class="app-label">Maximum Revenue Threshold (Optional)</label>
              <input
                v-model="form.revenue_threshold_max"
                type="number"
                step="0.01"
                min="0"
                class="app-field"
              />
              <p class="mt-2 text-xs text-slate-500">Leave blank for unlimited upper bound</p>
            </div>
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
            <p class="text-sm font-medium text-slate-950">Preview</p>
            <div class="mt-3 space-y-2 text-sm">
              <p>
                <span class="font-medium">Type:</span> 
                <span v-if="form.type === 'site_wide'">Site-wide Default</span>
                <span v-else-if="form.type === 'operator_specific'">Operator-specific</span>
                <span v-else>Revenue Tier</span>
              </p>
              <p>
                <span class="font-medium">Rate:</span> {{ formatPercentage(form.fee_rate) }}
              </p>
              <p v-if="form.type === 'operator_specific' && form.operator_id">
                <span class="font-medium">Operator:</span> 
                {{ operators.find(op => op.id === parseInt(form.operator_id))?.business_name || 'Selected' }}
              </p>
              <p v-if="form.type === 'revenue_tier'">
                <span class="font-medium">Revenue Range:</span>
                PHP {{ parseFloat(form.revenue_threshold_min || 0).toLocaleString() }}
                <span v-if="form.revenue_threshold_max">
                  - PHP {{ parseFloat(form.revenue_threshold_max).toLocaleString() }}
                </span>
                <span v-else>+</span>
              </p>
            </div>
          </div>

          <!-- Submit Buttons -->
          <div class="flex gap-3">
            <button type="submit" class="app-button-primary" :disabled="form.processing">
              Create Service Fee
            </button>
            <a href="/admin/service-fees" class="app-button-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </section>
  </MainLayout>
</template>
