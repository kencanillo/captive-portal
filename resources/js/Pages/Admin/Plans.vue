<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

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
  if (!window.confirm('Delete this plan?')) return;
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

  <MainLayout title="Manage Plans">
    <div class="rounded-lg bg-white p-5 shadow">
      <h2 class="text-lg font-semibold">Create promo</h2>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <input v-model="form.name" class="rounded-md border-slate-300" placeholder="Plan name" />
        <input v-model="form.sort_order" type="number" min="0" class="rounded-md border-slate-300" placeholder="Sort order" />
        <textarea v-model="form.description" class="rounded-md border-slate-300 sm:col-span-2" rows="3" placeholder="Promo description"></textarea>
        <input v-model="form.price" type="number" min="1" step="0.01" class="rounded-md border-slate-300" placeholder="Price" />
        <input v-model="form.duration_minutes" type="number" min="1" class="rounded-md border-slate-300" placeholder="Duration (minutes)" />
        <input v-model="form.speed_limit" class="rounded-md border-slate-300" placeholder="Speed limit (optional)" />
      </div>
      <div class="mt-4 flex flex-wrap gap-4 text-sm text-slate-700">
        <label class="inline-flex items-center gap-2">
          <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
          Active
        </label>
        <label class="inline-flex items-center gap-2">
          <input v-model="form.supports_pause" type="checkbox" class="rounded border-slate-300" />
          Allow pause
        </label>
        <label class="inline-flex items-center gap-2">
          <input v-model="form.enforce_no_tethering" type="checkbox" class="rounded border-slate-300" />
          Enforce no tethering
        </label>
      </div>
      <button class="mt-3 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" @click="createPlan">Create</button>
    </div>

    <div class="mt-6 rounded-lg bg-white p-5 shadow">
      <h2 class="text-lg font-semibold">Existing promos</h2>
      <div class="mt-4 space-y-3">
        <div v-for="plan in props.plans" :key="plan.id" class="rounded-md border border-slate-200 px-4 py-3">
          <div>
            <p class="font-semibold">{{ plan.name }}</p>
            <p class="text-sm text-slate-600">₱{{ Number(plan.price).toFixed(2) }} • {{ plan.duration_minutes }} mins</p>
            <p v-if="plan.description" class="mt-1 text-sm text-slate-500">{{ plan.description }}</p>
            <div class="mt-2 flex flex-wrap gap-2 text-xs font-semibold">
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">Order {{ plan.sort_order ?? 0 }}</span>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ plan.is_active ? 'Active' : 'Inactive' }}</span>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">Pause {{ plan.supports_pause ? 'on' : 'off' }}</span>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">No tethering {{ plan.enforce_no_tethering ? 'on' : 'off' }}</span>
            </div>
          </div>
          <div class="mt-3 flex gap-2">
            <button class="rounded-md bg-slate-700 px-3 py-1.5 text-sm font-semibold text-white" @click="startEdit(plan)">Edit</button>
            <button class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-semibold text-white" @click="deletePlan(plan.id)">Delete</button>
          </div>

          <div v-if="editingId === plan.id" class="mt-3 grid gap-2 sm:grid-cols-2">
            <input v-model="editForm.name" class="rounded-md border-slate-300" />
            <input v-model="editForm.sort_order" type="number" min="0" class="rounded-md border-slate-300" />
            <textarea v-model="editForm.description" class="rounded-md border-slate-300 sm:col-span-2" rows="3"></textarea>
            <input v-model="editForm.price" type="number" min="1" step="0.01" class="rounded-md border-slate-300" />
            <input v-model="editForm.duration_minutes" type="number" min="1" class="rounded-md border-slate-300" />
            <input v-model="editForm.speed_limit" class="rounded-md border-slate-300" />
            <div class="sm:col-span-2 flex flex-wrap gap-4 text-sm text-slate-700">
              <label class="inline-flex items-center gap-2">
                <input v-model="editForm.is_active" type="checkbox" class="rounded border-slate-300" />
                Active
              </label>
              <label class="inline-flex items-center gap-2">
                <input v-model="editForm.supports_pause" type="checkbox" class="rounded border-slate-300" />
                Allow pause
              </label>
              <label class="inline-flex items-center gap-2">
                <input v-model="editForm.enforce_no_tethering" type="checkbox" class="rounded border-slate-300" />
                Enforce no tethering
              </label>
            </div>
            <button class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white" @click="saveEdit">Save</button>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>
