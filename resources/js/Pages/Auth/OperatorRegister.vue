<script setup>
import InputError from '@/Components/InputError.vue';
import SvgIcon from '@/Components/SvgIcon.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
  business_name: '',
  contact_name: '',
  email: '',
  phone_number: '',
  password: '',
  password_confirmation: '',
  payout_method: '',
  payout_account_name: '',
  payout_account_reference: '',
  payout_notes: '',
  site_name_request: '',
});

const submit = () => {
  form.post('/operator/register', {
    preserveScroll: true,
    onFinish: () => form.reset('password', 'password_confirmation'),
  });
};
</script>

<template>
  <Head title="Register as Operator" />

  <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(91,184,254,0.16),transparent_24%),linear-gradient(180deg,#f7f9fb_0%,#eef2f7_100%)] px-4 py-6 sm:px-6 lg:px-8">
    <main class="mx-auto max-w-7xl">
      <div class="mb-10 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p class="app-kicker">Operator Onboarding</p>
          <h1 class="mt-3 text-5xl font-extralight tracking-[-0.06em] text-slate-950">Establish your network presence</h1>
          <p class="mt-4 max-w-2xl text-base leading-8 text-slate-500">
            This creates a pending operator account and linked user login. Approval happens inside the app before management access is granted.
          </p>
        </div>

        <div class="flex items-center gap-4">
          <Link href="/admin/login" class="app-button-secondary">
            Already have an account?
          </Link>
          <button class="app-button-primary" :disabled="form.processing" @click="submit">
            <span>Submit Application</span>
            <SvgIcon name="send" class="h-[18px] w-[18px]" />
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
        <section class="space-y-8 lg:col-span-7">
          <div class="app-card-strong p-8">
            <div class="mb-8 flex items-center gap-3">
              <div class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-200 text-slate-800">
                <SvgIcon name="business" class="h-5 w-5" />
              </div>
              <h2 class="text-2xl font-bold tracking-[-0.04em] text-slate-950">Business identity</h2>
            </div>

            <div class="grid grid-cols-1 gap-x-8 gap-y-6 md:grid-cols-2">
              <div>
                <label class="app-label">Business / Operator Name</label>
                <input v-model="form.business_name" class="app-field" placeholder="e.g. BruckeLab Connect Ltd" />
                <InputError class="mt-2" :message="form.errors.business_name" />
              </div>
              <div>
                <label class="app-label">Contact Person</label>
                <input v-model="form.contact_name" class="app-field" placeholder="Full legal name" />
                <InputError class="mt-2" :message="form.errors.contact_name" />
              </div>
              <div>
                <label class="app-label">Phone Number</label>
                <input v-model="form.phone_number" type="tel" class="app-field" placeholder="+63 9XX XXX XXXX" />
                <InputError class="mt-2" :message="form.errors.phone_number" />
              </div>
              <div>
                <label class="app-label">Email Address</label>
                <input v-model="form.email" type="email" class="app-field" placeholder="admin@business.com" />
                <InputError class="mt-2" :message="form.errors.email" />
              </div>
            </div>
          </div>

          <div class="app-card p-8">
            <div class="mb-8 flex items-center gap-3">
              <div class="flex h-11 w-11 items-center justify-center rounded-full bg-sky-100 text-sky-700">
                <SvgIcon name="account_balance_wallet" class="h-5 w-5" />
              </div>
              <h2 class="text-2xl font-bold tracking-[-0.04em] text-slate-950">Payout preferences</h2>
            </div>

            <div class="grid grid-cols-1 gap-x-8 gap-y-6 md:grid-cols-2">
              <div>
                <label class="app-label">Preferred Method</label>
                <input v-model="form.payout_method" class="app-field" placeholder="Bank transfer, GCash, wallet" />
                <InputError class="mt-2" :message="form.errors.payout_method" />
              </div>
              <div>
                <label class="app-label">Account Name</label>
                <input v-model="form.payout_account_name" class="app-field" placeholder="As per account records" />
                <InputError class="mt-2" :message="form.errors.payout_account_name" />
              </div>
              <div class="md:col-span-2">
                <label class="app-label">Account Number / Reference</label>
                <input v-model="form.payout_account_reference" class="app-field" placeholder="Routing details or wallet reference" />
                <InputError class="mt-2" :message="form.errors.payout_account_reference" />
              </div>
              <div class="md:col-span-2">
                <label class="app-label">Notes</label>
                <textarea v-model="form.payout_notes" class="app-field min-h-[120px]" placeholder="Payout instructions or handling notes" />
                <InputError class="mt-2" :message="form.errors.payout_notes" />
              </div>
            </div>
          </div>
        </section>

        <section class="space-y-8 lg:col-span-5">
          <div class="app-card-strong p-8">
            <div class="mb-8 flex items-center gap-3">
              <div class="flex h-11 w-11 items-center justify-center rounded-full bg-sky-500 text-white">
                <SvgIcon name="lock_person" class="h-5 w-5" />
              </div>
              <h2 class="text-2xl font-bold tracking-[-0.04em] text-slate-950">Security</h2>
            </div>

            <div class="space-y-6">
              <div>
                <label class="app-label">Access Password</label>
                <input v-model="form.password" type="password" class="app-field" placeholder="••••••••••••" />
                <InputError class="mt-2" :message="form.errors.password" />
              </div>
              <div>
                <label class="app-label">Confirm Password</label>
                <input v-model="form.password_confirmation" type="password" class="app-field" placeholder="••••••••••••" />
              </div>
              <div class="rounded-[22px] bg-sky-50 px-4 py-4">
                <p class="flex items-start gap-2 text-sm leading-6 text-sky-900">
                  <SvgIcon name="info" class="mt-0.5 h-[18px] w-[18px]" />
                  Passwords should be long enough to be worth something. Minimum length is enforced server-side.
                </p>
              </div>
            </div>
          </div>

          <div class="app-card p-8">
            <div class="mb-8 flex items-center gap-3">
              <div class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-200 text-slate-800">
                <SvgIcon name="lan" class="h-5 w-5" />
              </div>
              <h2 class="text-2xl font-bold tracking-[-0.04em] text-slate-950">Site details</h2>
            </div>

            <div class="space-y-6">
              <div>
                <label class="app-label">Requested Site Name</label>
                <input v-model="form.site_name_request" class="app-field" placeholder="e.g. Midtown Hub Alpha" />
                <InputError class="mt-2" :message="form.errors.site_name_request" />
              </div>
            </div>
          </div>

          <div class="app-card-dark overflow-hidden p-8">
            <p class="app-top-stat">
              <SvgIcon name="monitoring" class="h-4 w-4" />
              Advanced signal management
            </p>
            <h3 class="mt-6 text-3xl font-bold tracking-[-0.05em] text-white">Premium operator workflow</h3>
            <p class="mt-4 text-sm leading-7 text-slate-300">
              Once approved, operators gain access to site-scoped dashboards, payout requests, and device visibility without leaking other tenants’ data.
            </p>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>
