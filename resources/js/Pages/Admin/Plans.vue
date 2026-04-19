<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatCurrency, formatNumber } from '@/utils/formatters';

const props = defineProps({
  plans: {
    type: Array,
    required: true,
  },
});

const form = reactive({
  name: '',
  description: '',
  price: '',
  duration_minutes: '',
  speed_limit: '',
  is_active: true,
  supports_pause: true,
  enforce_no_tethering: true,
  sort_order: 0,
});

const editingId = ref(null);
const editForm = reactive({
  name: '',
  description: '',
  price: '',
  duration_minutes: '',
  speed_limit: '',
  is_active: true,
  supports_pause: true,
  enforce_no_tethering: true,
  sort_order: 0,
});

const stats = computed(() => ({
  total: props.plans.length,
  active: props.plans.filter((plan) => plan.is_active).length,
  pausable: props.plans.filter((plan) => plan.supports_pause).length,
  tetheringStrict: props.plans.filter((plan) => plan.enforce_no_tethering).length,
}));

const createPlan = () => {
  router.post('/admin/plans', form, {
    preserveScroll: true,
    onSuccess: () => {
      form.name = '';
      form.description = '';
      form.price = '';
      form.duration_minutes = '';
      form.speed_limit = '';
      form.is_active = true;
      form.supports_pause = true;
      form.enforce_no_tethering = true;
      form.sort_order = 0;
    },
  });
};

const deletePlan = (planId) => {
  if (!window.confirm('Delete this plan?')) {
    return;
  }

  router.delete(`/admin/plans/${planId}`, { preserveScroll: true });
};

const startEdit = (plan) => {
  editingId.value = plan.id;
  editForm.name = plan.name;
  editForm.description = plan.description || '';
  editForm.price = plan.price;
  editForm.duration_minutes = plan.duration_minutes;
  editForm.speed_limit = plan.speed_limit || '';
  editForm.is_active = Boolean(plan.is_active);
  editForm.supports_pause = Boolean(plan.supports_pause);
  editForm.enforce_no_tethering = Boolean(plan.enforce_no_tethering);
  editForm.sort_order = plan.sort_order ?? 0;
};

const saveEdit = () => {
  router.put(`/admin/plans/${editingId.value}`, editForm, {
    preserveScroll: true,
    onSuccess: () => {
      editingId.value = null;
    },
  });
};
</script>

<template>
  <Head title="Plans" />

  <MainLayout title="Promos and Plans">
    <section class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <p class="app-kicker">Commerce Rules</p>
        <h1 class="mt-3 app-title">Manage captive portal promos</h1>
        <p class="mt-4 app-subtitle">
          Plans drive checkout behavior, session duration, pause support, and anti-tethering policy. Keep them explicit and sortable. Hidden plan logic is how billing systems rot.
        </p>
      </div>
    </section>

    <section class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article class="app-metric-card">
        <p class="app-metric-label">Total Promos</p>
        <p class="app-metric-value">{{ formatNumber(stats.total) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Active</p>
        <p class="app-metric-value">{{ formatNumber(stats.active) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">Pause Enabled</p>
        <p class="app-metric-value">{{ formatNumber(stats.pausable) }}</p>
      </article>
      <article class="app-metric-card">
        <p class="app-metric-label">No Tethering</p>
        <p class="app-metric-value">{{ formatNumber(stats.tetheringStrict) }}</p>
      </article>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[0.85fr,1.15fr]">
      <div class="app-card-strong p-7">
        <p class="app-kicker">New Promo</p>
        <h2 class="mt-3 app-section-title">Create a sellable plan</h2>
        <p class="app-section-copy">Define pricing, duration, order, and enforcement flags once. The operator and portal surfaces should consume these cleanly.</p>

        <div class="mt-8 grid gap-5 md:grid-cols-2">
          <div>
            <label class="app-label">Plan Name</label>
            <input v-model="form.name" class="app-field" placeholder="Starter 30" />
          </div>
          <div>
            <label class="app-label">Sort Order</label>
            <input v-model="form.sort_order" type="number" min="0" class="app-field" placeholder="0" />
          </div>
          <div class="md:col-span-2">
            <label class="app-label">Description</label>
            <textarea v-model="form.description" class="app-field min-h-[110px]" rows="4" placeholder="Short plan summary for checkout" />
          </div>
          <div>
            <label class="app-label">Price</label>
            <input v-model="form.price" type="number" min="1" step="0.01" class="app-field" placeholder="49.00" />
          </div>
          <div>
            <label class="app-label">Duration (Minutes)</label>
            <input v-model="form.duration_minutes" type="number" min="1" class="app-field" placeholder="60" />
          </div>
          <div class="md:col-span-2">
            <label class="app-label">Speed Limit</label>
            <input v-model="form.speed_limit" class="app-field" placeholder="Optional speed profile or limit" />
          </div>
        </div>

        <div class="mt-6 grid gap-3">
          <label class="app-panel flex items-center justify-between gap-3">
            <span>
              <span class="block text-sm font-semibold text-slate-950">Active plan</span>
              <span class="mt-1 block text-sm text-slate-500">Expose this promo to the captive portal.</span>
            </span>
            <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
          </label>
          <label class="app-panel flex items-center justify-between gap-3">
            <span>
              <span class="block text-sm font-semibold text-slate-950">Allow pause</span>
              <span class="mt-1 block text-sm text-slate-500">Let qualifying sessions pause and resume later.</span>
            </span>
            <input v-model="form.supports_pause" type="checkbox" class="rounded border-slate-300" />
          </label>
          <label class="app-panel flex items-center justify-between gap-3">
            <span>
              <span class="block text-sm font-semibold text-slate-950">Enforce no tethering</span>
              <span class="mt-1 block text-sm text-slate-500">Apply stricter session rules to prevent hotspot sharing.</span>
            </span>
            <input v-model="form.enforce_no_tethering" type="checkbox" class="rounded border-slate-300" />
          </label>
        </div>

        <button class="app-button-primary mt-8" @click="createPlan">
          <span class="material-symbols-outlined text-[18px]">add_circle</span>
          Create promo
        </button>
      </div>

      <div class="app-table-shell">
        <div class="px-6 py-6">
          <p class="app-kicker">Promo Inventory</p>
          <h2 class="mt-2 app-section-title">Existing plans</h2>
        </div>

        <div v-if="props.plans.length" class="space-y-4 px-6 pb-6">
          <article
            v-for="plan in props.plans"
            :key="plan.id"
            class="rounded-[24px] border border-slate-200/80 bg-white/80 p-5 shadow-[0_18px_40px_-32px_rgba(19,27,46,0.35)]"
          >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <p class="text-lg font-semibold text-slate-950">{{ plan.name }}</p>
                  <span class="app-badge" :class="plan.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'">
                    {{ plan.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </div>
                <p class="mt-2 text-sm text-slate-500">
                  {{ formatCurrency(plan.price) }} • {{ formatNumber(plan.duration_minutes) }} minutes • Order {{ plan.sort_order ?? 0 }}
                </p>
                <p v-if="plan.description" class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">{{ plan.description }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                  <span class="app-badge-neutral">Pause {{ plan.supports_pause ? 'on' : 'off' }}</span>
                  <span class="app-badge-neutral">No tethering {{ plan.enforce_no_tethering ? 'on' : 'off' }}</span>
                  <span v-if="plan.speed_limit" class="app-badge-neutral">{{ plan.speed_limit }}</span>
                </div>
              </div>

              <div class="flex flex-wrap gap-2">
                <button class="app-button-secondary px-4 py-2.5" @click="startEdit(plan)">
                  <span class="material-symbols-outlined text-[18px]">edit</span>
                  Edit
                </button>
                <button class="inline-flex items-center justify-center gap-2 rounded-full bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-500" @click="deletePlan(plan.id)">
                  <span class="material-symbols-outlined text-[18px]">delete</span>
                  Delete
                </button>
              </div>
            </div>

            <div v-if="editingId === plan.id" class="mt-6 rounded-[22px] border border-slate-200/80 bg-slate-50/80 p-5">
              <div class="grid gap-4 md:grid-cols-2">
                <div>
                  <label class="app-label">Plan Name</label>
                  <input v-model="editForm.name" class="app-field" />
                </div>
                <div>
                  <label class="app-label">Sort Order</label>
                  <input v-model="editForm.sort_order" type="number" min="0" class="app-field" />
                </div>
                <div class="md:col-span-2">
                  <label class="app-label">Description</label>
                  <textarea v-model="editForm.description" class="app-field min-h-[96px]" rows="3" />
                </div>
                <div>
                  <label class="app-label">Price</label>
                  <input v-model="editForm.price" type="number" min="1" step="0.01" class="app-field" />
                </div>
                <div>
                  <label class="app-label">Duration (Minutes)</label>
                  <input v-model="editForm.duration_minutes" type="number" min="1" class="app-field" />
                </div>
                <div class="md:col-span-2">
                  <label class="app-label">Speed Limit</label>
                  <input v-model="editForm.speed_limit" class="app-field" />
                </div>
              </div>

              <div class="mt-5 flex flex-wrap gap-3">
                <label class="app-badge-neutral flex items-center gap-2">
                  <input v-model="editForm.is_active" type="checkbox" class="rounded border-slate-300" />
                  Active
                </label>
                <label class="app-badge-neutral flex items-center gap-2">
                  <input v-model="editForm.supports_pause" type="checkbox" class="rounded border-slate-300" />
                  Allow pause
                </label>
                <label class="app-badge-neutral flex items-center gap-2">
                  <input v-model="editForm.enforce_no_tethering" type="checkbox" class="rounded border-slate-300" />
                  Enforce no tethering
                </label>
              </div>

              <div class="mt-5 flex flex-wrap gap-3">
                <button class="app-button-primary" @click="saveEdit">
                  Save changes
                </button>
                <button class="app-button-ghost" @click="editingId = null">
                  Cancel
                </button>
              </div>
            </div>
          </article>
        </div>

        <div v-else class="px-6 pb-6">
          <div class="app-empty">No promos exist yet. Create the first plan instead of shipping an empty checkout experience.</div>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
