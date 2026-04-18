<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  operator: Object,
  availableSites: Array,
  sitesSynced: Boolean,
  recentPayoutRequests: Array,
  recentSessions: Array,
});

const statusForm = useForm({
  status: props.operator.status,
  approval_notes: props.operator.approval_notes || '',
});

const siteForm = useForm({
  site_ids: props.operator.sites.map(site => site.id),
});

const updateStatus = () => {
  statusForm.put(`/admin/operators/${props.operator.id}/status`, { preserveScroll: true });
};

const updateSites = () => {
  siteForm.put(`/admin/operators/${props.operator.id}/sites`, { preserveScroll: true });
};
</script>

<template>
  <Head :title="operator.business_name" />

  <MainLayout :title="operator.business_name">
    <div class="grid gap-6 xl:grid-cols-[0.9fr,1.1fr]">
      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Operator profile</h2>
        <div class="mt-4 space-y-2 text-sm text-slate-700">
          <p><span class="font-semibold text-slate-900">Contact:</span> {{ operator.contact_name }}</p>
          <p><span class="font-semibold text-slate-900">Email:</span> {{ operator.email }}</p>
          <p><span class="font-semibold text-slate-900">Phone:</span> {{ operator.phone_number }}</p>
          <p><span class="font-semibold text-slate-900">Requested site:</span> {{ operator.requested_site_name || 'None' }}</p>
          <p><span class="font-semibold text-slate-900">Revenue:</span> ₱{{ operator.summary.revenue_total }}</p>
          <p><span class="font-semibold text-slate-900">Available balance:</span> ₱{{ operator.summary.available_balance }}</p>
        </div>

        <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
          <h3 class="text-sm font-semibold text-slate-900">Payout preference placeholder</h3>
          <div class="mt-3 space-y-2 text-sm text-slate-700">
            <p><span class="font-semibold text-slate-900">Method:</span> {{ operator.payout_preferences?.method || 'N/A' }}</p>
            <p><span class="font-semibold text-slate-900">Account name:</span> {{ operator.payout_preferences?.account_name || 'N/A' }}</p>
            <p><span class="font-semibold text-slate-900">Account reference:</span> {{ operator.payout_preferences?.account_reference || 'N/A' }}</p>
            <p><span class="font-semibold text-slate-900">Notes:</span> {{ operator.payout_preferences?.notes || 'N/A' }}</p>
          </div>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Approval and site mapping</h2>

        <form class="mt-4 space-y-4" @submit.prevent="updateStatus">
          <div>
            <label class="block text-sm font-medium text-slate-700">Status</label>
            <select v-model="statusForm.status" class="mt-1 w-full rounded-md border-slate-300">
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700">Admin notes</label>
            <textarea v-model="statusForm.approval_notes" class="mt-1 w-full rounded-md border-slate-300"></textarea>
          </div>
          <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Save status</button>
        </form>

        <form class="mt-6 space-y-3" @submit.prevent="updateSites">
          <h3 class="text-sm font-semibold text-slate-900">Assign sites</h3>
          <p v-if="!sitesSynced || !availableSites.length" class="text-sm text-amber-700">
            Sync controller sites first from Controller Settings. Site assignment should come from Omada, not ad-hoc database rows.
          </p>
          <label v-for="site in availableSites" :key="site.id" class="flex items-center justify-between rounded-md border border-slate-200 px-4 py-3 text-sm">
            <span>
              <span class="font-medium text-slate-900">{{ site.name }}</span>
              <span class="ml-2 text-slate-500">{{ site.slug }} · {{ site.omada_site_id }}</span>
            </span>
            <input v-model="siteForm.site_ids" :value="site.id" type="checkbox" class="rounded border-slate-300" />
          </label>
          <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Save site mapping</button>
        </form>
      </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Recent payout requests</h2>
        <div class="mt-4 space-y-3">
          <article v-for="item in recentPayoutRequests" :key="item.id" class="rounded-md border border-slate-200 px-4 py-3">
            <p class="font-medium text-slate-900">₱{{ item.amount }} · {{ item.status }}</p>
            <p class="text-sm text-slate-500">{{ item.processing_mode || 'manual review' }} · {{ item.provider || 'manual' }}</p>
          </article>
          <p v-if="!recentPayoutRequests.length" class="text-sm text-slate-500">No payout requests yet.</p>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Recent sessions</h2>
        <div class="mt-4 space-y-3">
          <article v-for="session in recentSessions" :key="session.id" class="rounded-md border border-slate-200 px-4 py-3">
            <p class="font-medium text-slate-900">{{ session.site_name || 'Unassigned site' }} · {{ session.plan_name || 'No plan' }}</p>
            <p class="text-sm text-slate-500">{{ session.payment_status }} · ₱{{ session.amount_paid }}</p>
          </article>
          <p v-if="!recentSessions.length" class="text-sm text-slate-500">No sessions yet.</p>
        </div>
      </section>
    </div>
  </MainLayout>
</template>
