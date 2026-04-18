<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
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
  <GuestLayout>
    <Head title="Operator Registration" />

    <div class="mb-6">
      <h1 class="text-xl font-semibold text-slate-900">Register as an operator</h1>
      <p class="mt-2 text-sm text-slate-600">
        This creates a pending operator account and linked user login. Admin approval is required before dashboard access.
      </p>
    </div>

    <form class="space-y-5" @submit.prevent="submit">
      <div>
        <InputLabel for="business_name" value="Business / Operator Name" />
        <TextInput id="business_name" v-model="form.business_name" class="mt-1 block w-full" required />
        <InputError class="mt-2" :message="form.errors.business_name" />
      </div>

      <div>
        <InputLabel for="contact_name" value="Contact Person Name" />
        <TextInput id="contact_name" v-model="form.contact_name" class="mt-1 block w-full" required />
        <InputError class="mt-2" :message="form.errors.contact_name" />
      </div>

      <div>
        <InputLabel for="email" value="Email" />
        <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
        <InputError class="mt-2" :message="form.errors.email" />
      </div>

      <div>
        <InputLabel for="phone_number" value="Phone Number" />
        <TextInput id="phone_number" v-model="form.phone_number" class="mt-1 block w-full" required />
        <InputError class="mt-2" :message="form.errors.phone_number" />
      </div>

      <div class="grid gap-5 md:grid-cols-2">
        <div>
          <InputLabel for="password" value="Password" />
          <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" required />
          <InputError class="mt-2" :message="form.errors.password" />
        </div>

        <div>
          <InputLabel for="password_confirmation" value="Confirm Password" />
          <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" class="mt-1 block w-full" required />
        </div>
      </div>

      <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <h2 class="text-sm font-semibold text-slate-900">Payout preference placeholder</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <div>
            <InputLabel for="payout_method" value="Preferred payout method" />
            <TextInput id="payout_method" v-model="form.payout_method" class="mt-1 block w-full" placeholder="Bank transfer, GCash, wallet" />
          </div>
          <div>
            <InputLabel for="payout_account_name" value="Account name" />
            <TextInput id="payout_account_name" v-model="form.payout_account_name" class="mt-1 block w-full" />
          </div>
          <div>
            <InputLabel for="payout_account_reference" value="Account number / reference" />
            <TextInput id="payout_account_reference" v-model="form.payout_account_reference" class="mt-1 block w-full" />
          </div>
          <div>
            <InputLabel for="site_name_request" value="Requested site name (optional)" />
            <TextInput id="site_name_request" v-model="form.site_name_request" class="mt-1 block w-full" />
          </div>
        </div>
        <div class="mt-4">
          <InputLabel for="payout_notes" value="Notes" />
          <textarea id="payout_notes" v-model="form.payout_notes" class="mt-1 block w-full rounded-md border-slate-300"></textarea>
        </div>
      </div>

      <div class="flex items-center justify-between">
        <Link href="/admin/login" class="text-sm text-slate-600 underline">Already have an account?</Link>
        <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
          Submit registration
        </PrimaryButton>
      </div>
    </form>
  </GuestLayout>
</template>
