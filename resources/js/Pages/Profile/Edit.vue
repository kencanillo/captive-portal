<script setup>
import InputError from '@/Components/InputError.vue';
import MainLayout from '@/Layouts/MainLayout.vue';
import SvgIcon from '@/Components/SvgIcon.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
  mustVerifyEmail: {
    type: Boolean,
  },
  status: {
    type: String,
  },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const profileRole = computed(() => {
  if (user.value?.is_admin) {
    return 'Administrator';
  }

  if (user.value?.can_access_operator_panel) {
    return 'Approved operator';
  }

  if (user.value?.is_operator) {
    return 'Pending operator';
  }

  return 'Portal user';
});

const profileForm = useForm({
  name: user.value?.name || '',
  email: user.value?.email || '',
});

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const passwordForm = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
});

const updateProfile = () => {
  profileForm.patch(route('profile.update'), {
    preserveScroll: true,
  });
};

const updatePassword = () => {
  passwordForm.put(route('password.update'), {
    preserveScroll: true,
    onSuccess: () => passwordForm.reset(),
    onError: () => {
      if (passwordForm.errors.password) {
        passwordForm.reset('password', 'password_confirmation');
        passwordInput.value?.focus();
      }

      if (passwordForm.errors.current_password) {
        passwordForm.reset('current_password');
        currentPasswordInput.value?.focus();
      }
    },
  });
};
</script>

<template>
  <Head title="Profile" />

  <MainLayout title="Profile">
    <section>
      <p class="app-kicker">Account Profile</p>
      <h1 class="mt-3 app-title">Basic information and password</h1>
      <p class="mt-4 app-subtitle">
        This page is for account-level changes only. User details and password updates belong here, not mixed into controller settings or operator operations.
      </p>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[0.85fr,1.15fr]">
      <aside class="space-y-6">
        <div class="app-card-dark p-7">
          <p class="app-top-stat">
            <SvgIcon name="person" class="h-4 w-4" />
            Account summary
          </p>
          <h2 class="mt-6 text-3xl font-bold tracking-[-0.05em] text-white">{{ user?.name || 'Unknown user' }}</h2>
          <p class="mt-2 text-sm text-slate-300">{{ user?.email }}</p>
          <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div>
              <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Role</p>
              <p class="mt-2 text-base font-semibold text-white">{{ profileRole }}</p>
            </div>
            <div>
              <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-white/60">Operator</p>
              <p class="mt-2 text-base font-semibold text-white">{{ user?.operator_business_name || 'Not linked' }}</p>
            </div>
          </div>
        </div>

        <div class="app-card p-7">
          <p class="app-kicker">Account Details</p>
          <h2 class="mt-3 app-section-title">Current user information</h2>
          <div class="mt-6 space-y-4">
            <div class="app-panel">
              <p class="app-metric-label">Full Name</p>
              <p class="mt-3 text-base font-semibold text-slate-950">{{ user?.name || 'Not set' }}</p>
            </div>
            <div class="app-panel">
              <p class="app-metric-label">Email Address</p>
              <p class="mt-3 break-all text-base font-semibold text-slate-950">{{ user?.email || 'Not set' }}</p>
            </div>
          </div>
        </div>
      </aside>

      <div class="space-y-6">
        <section class="app-card-strong p-7">
          <p class="app-kicker">Basic Info</p>
          <h2 class="mt-3 app-section-title">Update profile information</h2>
          <form class="mt-6 space-y-5" @submit.prevent="updateProfile">
            <div>
              <label class="app-label" for="profile_name">Name</label>
              <input id="profile_name" v-model="profileForm.name" type="text" class="app-field" autocomplete="name" />
              <InputError class="mt-2" :message="profileForm.errors.name" />
            </div>

            <div>
              <label class="app-label" for="profile_email">Email</label>
              <input id="profile_email" v-model="profileForm.email" type="email" class="app-field" autocomplete="username" />
              <InputError class="mt-2" :message="profileForm.errors.email" />
            </div>

            <div class="flex items-center gap-4">
              <button type="submit" class="app-button-primary" :disabled="profileForm.processing">Save changes</button>
              <p v-if="profileForm.recentlySuccessful" class="text-sm text-emerald-700">Profile saved.</p>
            </div>
          </form>
        </section>

        <section class="app-card p-7">
          <p class="app-kicker">Password</p>
          <h2 class="mt-3 app-section-title">Change password</h2>
          <p class="app-section-copy">Use a long password that is unique to this account. This page is the correct place to rotate credentials.</p>

          <form class="mt-6 space-y-5" @submit.prevent="updatePassword">
            <div>
              <label class="app-label" for="current_password">Current Password</label>
              <input
                id="current_password"
                ref="currentPasswordInput"
                v-model="passwordForm.current_password"
                type="password"
                class="app-field"
                autocomplete="current-password"
              />
              <InputError class="mt-2" :message="passwordForm.errors.current_password" />
            </div>

            <div>
              <label class="app-label" for="new_password">New Password</label>
              <input
                id="new_password"
                ref="passwordInput"
                v-model="passwordForm.password"
                type="password"
                class="app-field"
                autocomplete="new-password"
              />
              <InputError class="mt-2" :message="passwordForm.errors.password" />
            </div>

            <div>
              <label class="app-label" for="password_confirmation">Confirm New Password</label>
              <input
                id="password_confirmation"
                v-model="passwordForm.password_confirmation"
                type="password"
                class="app-field"
                autocomplete="new-password"
              />
              <InputError class="mt-2" :message="passwordForm.errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
              <button type="submit" class="app-button-primary" :disabled="passwordForm.processing">Change password</button>
              <p v-if="passwordForm.recentlySuccessful" class="text-sm text-emerald-700">Password updated.</p>
            </div>
          </form>
        </section>
      </div>
    </section>
  </MainLayout>
</template>
