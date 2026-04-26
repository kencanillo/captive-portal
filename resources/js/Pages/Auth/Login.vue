<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import SvgIcon from '@/Components/SvgIcon.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
  canResetPassword: {
    type: Boolean,
  },
  status: {
    type: String,
  },
});

const form = useForm({
  email: '',
  password: '',
  remember: false,
});

const submit = () => {
  form.post(route('admin.login'), {
    onFinish: () => form.reset('password'),
  });
};
</script>

<template>
  <Head title="Management Portal Login" />

  <div class="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(91,184,254,0.18),transparent_26%),linear-gradient(180deg,#f7f9fb_0%,#eef2f7_100%)] px-4 py-6 sm:px-6 lg:px-8">
    <main class="mx-auto flex min-h-[calc(100vh-3rem)] w-full max-w-7xl overflow-hidden rounded-[32px] border border-white/70 bg-white/75 shadow-[0_40px_120px_-48px_rgba(19,27,46,0.45)] backdrop-blur-xl">
      <section class="relative hidden lg:flex lg:w-3/5 flex-col justify-between overflow-hidden bg-[linear-gradient(160deg,#131b2e_0%,#090d18_100%)] p-14 text-white">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(91,184,254,0.22),transparent_28%),radial-gradient(circle_at_bottom_right,rgba(78,222,163,0.12),transparent_24%)]" />

        <div class="relative z-10 flex items-center gap-3">
          <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-300/90 text-slate-950">
            <SvgIcon name="hub" class="h-5 w-5" />
          </div>
          <span class="text-2xl font-black uppercase tracking-[0.18em]">BruckeLab</span>
        </div>

        <div class="relative z-10 max-w-xl">
          <p class="text-[11px] font-bold uppercase tracking-[0.34em] text-sky-200/80">Security Gateway</p>
          <h1 class="mt-6 text-6xl font-light leading-[1.02] tracking-[-0.07em]">
            Captive
            <span class="font-bold">Portal.</span>
          </h1>
          <p class="mt-6 max-w-md text-lg leading-8 text-slate-300">
            Premium WiFi operations for admins and approved operators. One access point for the management plane, not a cluttered commodity login.
          </p>
        </div>

        <div class="relative z-10 flex items-center gap-4 text-xs font-bold uppercase tracking-[0.24em] text-slate-400">
          <div class="h-px w-12 bg-slate-500/40" />
          Encryption standard v4.2
        </div>
      </section>

      <section class="flex w-full items-center justify-center bg-white/70 px-6 py-12 sm:px-10 lg:w-2/5 lg:px-16">
        <div class="w-full max-w-md">
          <div class="mb-10">
            <p class="app-kicker">Management Portal</p>
            <h2 class="mt-3 text-4xl font-black tracking-[-0.05em] text-slate-950">Initialize session</h2>
            <p class="mt-3 text-sm leading-7 text-slate-500">
              Admins and approved operators use the same secure entry point.
            </p>
          </div>

          <div class="mb-8 rounded-[24px] border border-slate-200/70 bg-slate-50/80 p-5">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Operator onboarding</p>
            <p class="mt-2 text-sm text-slate-600">
              New operator?
              <Link :href="route('operator.register')" class="font-semibold text-slate-950 underline decoration-slate-300 underline-offset-4">
                Register here
              </Link>
            </p>
          </div>

          <form class="space-y-6" @submit.prevent="submit">
            <div>
              <label class="app-label" for="email">Email Address</label>
              <div class="relative">
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  required
                  autofocus
                  autocomplete="username"
                  class="app-field h-14 pr-12"
                  placeholder="operator@bruckelab.com"
                />
                <SvgIcon name="alternate_email" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
              </div>
              <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
              <div class="mb-2 flex items-center justify-between">
                <label class="app-label mb-0" for="password">Password</label>
                <Link
                  v-if="canResetPassword"
                  :href="route('password.request')"
                  class="text-xs font-bold uppercase tracking-[0.16em] text-sky-700 transition hover:text-sky-900"
                >
                  Forgot?
                </Link>
              </div>
              <div class="relative">
                <input
                  id="password"
                  v-model="form.password"
                  type="password"
                  required
                  autocomplete="current-password"
                  class="app-field h-14 pr-12"
                  placeholder="••••••••••••"
                />
                <SvgIcon name="lock" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
              </div>
              <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <label class="flex items-center gap-3 rounded-[18px] bg-slate-50/90 px-4 py-3 text-sm text-slate-600">
              <Checkbox name="remember" v-model:checked="form.remember" />
              Keep me signed in
            </label>

            <button
              type="submit"
              class="inline-flex h-16 w-full items-center justify-center gap-3 rounded-[22px] bg-[linear-gradient(160deg,#131b2e_0%,#090d18_100%)] text-lg font-bold text-white transition hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="form.processing"
            >
              <span>Initialize Session</span>
              <SvgIcon name="arrow_forward" class="h-[18px] w-[18px]" />
            </button>
          </form>

          <div class="mt-10 rounded-[22px] border border-rose-100/70 bg-slate-50/80 px-5 py-4">
            <div class="flex items-start gap-3">
              <SvgIcon name="security" class="mt-0.5 h-5 w-5 text-rose-600" />
              <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-700">Authorized access only</p>
                <p class="mt-2 text-sm leading-6 text-slate-500">Unapproved or unknown accounts are blocked from the management portal.</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</template>
