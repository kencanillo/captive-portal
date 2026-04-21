<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';
import { formatCurrency } from '@/utils/formatters';

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
  site_ids: props.operator.sites.map((site) => site.id),
});

const credentialForm = useForm({
  hotspot_operator_username: props.operator.credentials?.hotspot_operator_username || '',
  hotspot_operator_password: '',
  notes: props.operator.credentials?.notes || '',
});

const updateStatus = () => {
  statusForm.put(`/admin/operators/${props.operator.id}/status`, { preserveScroll: true });
};

const updateSites = () => {
  siteForm.put(`/admin/operators/${props.operator.id}/sites`, { preserveScroll: true });
};

const updateCredentials = () => {
  credentialForm.put(`/admin/operators/${props.operator.id}/credentials`, { preserveScroll: true });
};

const deleteCredentials = () => {
  if (confirm('Are you sure you want to remove these Omada credentials? This will affect client authorization.')) {
    credentialForm.delete(`/admin/operators/${props.operator.id}/credentials`, { preserveScroll: true });
  }
};
</script>

<template>
  <Head :title="operator.business_name" />

  <MainLayout :title="operator.business_name">
    <section class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <p class="app-kicker">Operator Profile</p>
        <h1 class="mt-3 app-title">{{ operator.business_name }}</h1>
        <p class="mt-4 app-subtitle">
          Approval, site mapping, payout preference placeholders, and operator performance should be reviewed from one screen. Splitting that context across multiple pages is wasteful.
        </p>
      </div>
      <span
        class="app-badge"
        :class="{
          'bg-emerald-100 text-emerald-700': operator.status === 'approved',
          'bg-amber-100 text-amber-700': operator.status === 'pending',
          'bg-rose-100 text-rose-700': operator.status === 'rejected',
        }"
      >
        {{ operator.status }}
      </span>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[0.95fr,1.05fr]">
      <div class="space-y-6">
        <div class="app-card-strong p-7">
          <p class="app-kicker">Profile</p>
          <h2 class="mt-3 app-section-title">Business and contact information</h2>
          <div class="mt-6 grid gap-4">
            <div class="app-panel">
              <p class="app-metric-label">Contact</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ operator.contact_name }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Email</p>
              <p class="mt-3 break-all text-base font-semibold text-slate-950">{{ operator.email }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Phone</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ operator.phone_number }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Requested Site</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ operator.requested_site_name || 'None' }}</p>
            </div>
          </div>
        </div>

        <div class="app-card-dark p-7">
          <p class="app-top-stat">
            <span class="material-symbols-outlined text-[16px]">account_balance_wallet</span>
            Commercial summary
          </p>
          <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div>
              <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Revenue</p>
              <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-white">{{ formatCurrency(operator.summary.revenue_total) }}</p>
            </div>
            <div>
              <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Available Balance</p>
              <p class="mt-3 text-3xl font-semibold tracking-[-0.05em] text-white">{{ formatCurrency(operator.summary.available_balance) }}</p>
            </div>
          </div>
        </div>

        <div class="app-card p-7">
          <p class="app-kicker">Payout Preference Placeholder</p>
          <h2 class="mt-3 app-section-title">Stored operator payout metadata</h2>
          <div class="mt-6 space-y-4">
            <div class="app-panel">
              <p class="app-metric-label">Method</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ operator.payout_preferences?.method || 'N/A' }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Account Name</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ operator.payout_preferences?.account_name || 'N/A' }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Account Reference</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ operator.payout_preferences?.account_reference || 'N/A' }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Notes</p>
              <p class="mt-3 text-sm leading-6 text-slate-600">{{ operator.payout_preferences?.notes || 'N/A' }}</p>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-6">
        <div class="app-card-strong p-7">
          <p class="app-kicker">Approval Control</p>
          <h2 class="mt-3 app-section-title">Status and admin notes</h2>

          <form class="mt-6 space-y-5" @submit.prevent="updateStatus">
            <div>
              <label class="app-label">Status</label>
              <select v-model="statusForm.status" class="app-field">
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>

            <div>
              <label class="app-label">Admin Notes</label>
              <textarea v-model="statusForm.approval_notes" class="app-field min-h-[120px]" />
            </div>

            <button type="submit" class="app-button-primary">Save status</button>
          </form>
        </div>

        <div class="app-card p-7">
          <p class="app-kicker">Site Mapping</p>
          <h2 class="mt-3 app-section-title">Assign Omada-synced sites</h2>
          <p v-if="!sitesSynced || !availableSites.length" class="mt-4 rounded-[20px] bg-amber-50 px-4 py-4 text-sm text-amber-700">
            Sync controller sites first. Site ownership must come from Omada data, not random database inserts.
          </p>

          <form class="mt-6 space-y-3" @submit.prevent="updateSites">
            <label
              v-for="site in availableSites"
              :key="site.id"
              class="flex items-center justify-between gap-4 rounded-[22px] border border-slate-200/80 bg-white/80 px-5 py-4"
            >
              <span>
                <span class="block text-sm font-semibold text-slate-950">{{ site.name }}</span>
                <span class="mt-1 block text-xs text-slate-500">{{ site.slug }} • {{ site.omada_site_id }}</span>
              </span>
              <input v-model="siteForm.site_ids" :value="site.id" type="checkbox" class="rounded border-slate-300" />
            </label>

            <button type="submit" class="app-button-primary">Save site mapping</button>
          </form>
        </div>

        <div class="app-card p-7">
          <p class="app-kicker">Omada Credentials</p>
          <h2 class="mt-3 app-section-title">Hotspot operator authentication</h2>
          <p class="mt-4 text-sm text-slate-600">
            These credentials are used for client authorization in Omada. Each operator should have their own hotspot operator account.
          </p>

          <div v-if="operator.credentials" class="mt-6 rounded-[20px] bg-emerald-50 px-4 py-4 text-sm text-emerald-700">
            <p class="font-semibold">Credentials configured</p>
            <p class="mt-1">Username: {{ operator.credentials.hotspot_operator_username }}</p>
            <p class="mt-1">Added: {{ operator.credentials.created_at }}</p>
          </div>

          <form class="mt-6 space-y-5" @submit.prevent="updateCredentials">
            <div>
              <label class="app-label">Hotspot Operator Username</label>
              <input v-model="credentialForm.hotspot_operator_username" type="text" class="app-field" placeholder="e.g., juleanne_operator" />
            </div>

            <div>
              <label class="app-label">Hotspot Operator Password</label>
              <input v-model="credentialForm.hotspot_operator_password" type="password" class="app-field" placeholder="Enter password" />
              <p class="mt-2 text-xs text-slate-500">Required even when updating existing credentials.</p>
            </div>

            <div>
              <label class="app-label">Notes (Optional)</label>
              <textarea v-model="credentialForm.notes" class="app-field min-h-[80px]" placeholder="Internal notes about these credentials" />
            </div>

            <div class="flex gap-3">
              <button type="submit" class="app-button-primary">Save credentials</button>
              <button v-if="operator.credentials" type="button" @click="deleteCredentials" class="app-button-secondary">Remove credentials</button>
            </div>
          </form>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
          <section class="app-card p-6">
            <p class="app-kicker">Payout Requests</p>
            <h2 class="mt-2 app-section-title">Recent payout activity</h2>
            <div class="mt-5 space-y-3">
              <article v-for="item in recentPayoutRequests" :key="item.id" class="rounded-[20px] border border-slate-200/80 bg-white/80 px-4 py-4">
                <p class="font-semibold text-slate-950">{{ formatCurrency(item.amount) }} • {{ item.status }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ item.processing_mode || 'manual review' }} • {{ item.provider || 'manual' }}</p>
              </article>
              <div v-if="!recentPayoutRequests.length" class="app-empty">No payout requests yet.</div>
            </div>
          </section>

          <section class="app-card p-6">
            <p class="app-kicker">Session Activity</p>
            <h2 class="mt-2 app-section-title">Recent sessions</h2>
            <div class="mt-5 space-y-3">
              <article v-for="session in recentSessions" :key="session.id" class="rounded-[20px] border border-slate-200/80 bg-white/80 px-4 py-4">
                <p class="font-semibold text-slate-950">{{ session.site_name || 'Unassigned site' }} • {{ session.plan_name || 'No plan' }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ session.payment_status }} • {{ formatCurrency(session.amount_paid) }}</p>
              </article>
              <div v-if="!recentSessions.length" class="app-empty">No sessions yet.</div>
            </div>
          </section>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
