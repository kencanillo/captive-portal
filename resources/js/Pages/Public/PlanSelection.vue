<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatCurrency } from '@/utils/formatters';

const props = defineProps({
  bootstrapUrl: {
    type: String,
    required: true,
  },
  plansUrl: {
    type: String,
    required: true,
  },
  bootstrapTimeoutMs: {
    type: Number,
    default: 8000,
  },
  initialPortalContext: {
    type: Object,
    required: true,
  },
});

const bootstrapLoading = ref(true);
const plansLoading = ref(false);
const bootstrapError = ref('');
const plansError = ref('');
const plans = ref([]);
const portalToken = ref(null);
const portalContext = ref({
  ...props.initialPortalContext,
  mac_address: null,
});
const existingClient = ref(null);
const activeSession = ref(null);
const loadingPlanId = ref(null);
const errorMessage = ref('');
const plansRequested = ref(false);
const activeSessionRemainingSeconds = ref(0);

let activeSessionCountdownTimer = null;

const registrationForm = ref({
  name: '',
  phone_number: '',
  pin: '',
  pin_confirmation: '',
  mac_address: '',
});

const syncActiveSessionCountdown = () => {
  if (!activeSession.value?.end_time) {
    activeSessionRemainingSeconds.value = 0;
    return;
  }

  const endTimeMs = new Date(activeSession.value.end_time).getTime();
  activeSessionRemainingSeconds.value = Math.max(0, Math.floor((endTimeMs - Date.now()) / 1000));
};

const fetchBootstrap = async () => {
  const startTime = performance.now();
  bootstrapLoading.value = true;
  bootstrapError.value = '';

  try {
    console.log('[PlanSelection] Starting bootstrap fetch');
    const response = await window.axios.get(props.bootstrapUrl, {
      timeout: props.bootstrapTimeoutMs,
    });
    const fetchDuration = performance.now() - startTime;
    console.log(`[PlanSelection] Bootstrap fetch completed in ${fetchDuration.toFixed(2)}ms`);

    const payload = response?.data?.data || {};

    portalContext.value = {
      ...portalContext.value,
      ...(payload.portal_context || {}),
    };

    portalToken.value = payload.portal_token || null;
    registrationForm.value.mac_address = payload?.portal_context?.mac_address || '';
    existingClient.value = payload.existing_client || null;
    activeSession.value = payload.active_session || null;
    syncActiveSessionCountdown();

    if (existingClient.value) {
      registrationForm.value.name = existingClient.value.name || '';
      registrationForm.value.phone_number = existingClient.value.phone_number || '';
    }

    console.log(`[PlanSelection] Bootstrap processing completed. MAC detected: ${Boolean(registrationForm.value.mac_address)}`);
  } catch (error) {
    const errorDuration = performance.now() - startTime;
    console.error(`[PlanSelection] Bootstrap fetch failed after ${errorDuration.toFixed(2)}ms:`, error);

    bootstrapError.value = error?.code === 'ECONNABORTED'
      ? 'Device detection timed out. The page is loaded, but Omada did not answer fast enough. Retry the lookup or enable query MAC fallback in deployment.'
      : error?.response?.data?.message || 'Unable to load device context from Omada. Plan selection stays locked until the MAC address is detected.';
    existingClient.value = null;
    activeSession.value = null;
    portalToken.value = null;
    registrationForm.value.mac_address = '';
  } finally {
    bootstrapLoading.value = false;
  }
};

onMounted(() => {
  console.log('[PlanSelection] Page mounted, starting bootstrap');
  const mountStart = performance.now();
  fetchBootstrap();

  activeSessionCountdownTimer = window.setInterval(() => {
    syncActiveSessionCountdown();
  }, 1000);

  // Log when the page is fully interactive
  setTimeout(() => {
    const interactiveTime = performance.now() - mountStart;
    console.log(`[PlanSelection] Page became interactive in ${interactiveTime.toFixed(2)}ms`);
  }, 100);
});

onBeforeUnmount(() => {
  if (activeSessionCountdownTimer) {
    window.clearInterval(activeSessionCountdownTimer);
  }
});

const activeMacAddress = computed(() => registrationForm.value.mac_address || portalContext.value?.mac_address || '');
const hasDetectedMacAddress = computed(() => Boolean(activeMacAddress.value.trim()));
const hasActiveSession = computed(() => Boolean(activeSession.value));
const hasValidRegistrationInput = computed(() => (
  Boolean(registrationForm.value.name.trim())
  && Boolean(registrationForm.value.phone_number.trim())
  && registrationForm.value.pin.trim().length >= 4
  && registrationForm.value.pin === registrationForm.value.pin_confirmation
));
const activeSessionRemainingLabel = computed(() => {
  if (!hasActiveSession.value) {
    return '';
  }

  const totalSeconds = Math.max(0, activeSessionRemainingSeconds.value);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (hours > 0) {
    return `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const validateRegistrationForm = () => {
  if (!hasDetectedMacAddress.value) return 'MAC address was not detected from Omada yet.';
  if (!portalToken.value) return 'Portal context is unavailable. Refresh the page and try again.';
  if (!registrationForm.value.name.trim()) return 'Name is required.';
  if (!registrationForm.value.phone_number.trim()) return 'Phone number is required.';
  if (!registrationForm.value.pin.trim()) return 'PIN is required.';
  if (registrationForm.value.pin.length < 4) return 'PIN must be at least 4 characters.';
  if (!registrationForm.value.pin_confirmation.trim()) return 'Confirm PIN is required.';
  if (registrationForm.value.pin !== registrationForm.value.pin_confirmation) return 'PIN confirmation does not match.';

  return null;
};

const loadPlans = async () => {
  plansLoading.value = true;
  plansError.value = '';

  try {
    const response = await window.axios.get(props.plansUrl);
    plans.value = response?.data?.data?.plans || [];
    plansRequested.value = true;
  } catch (error) {
    plansError.value = error?.response?.data?.message || 'Unable to load plans right now.';
  } finally {
    plansLoading.value = false;
  }
};

const continueToPlans = async () => {
  if (hasActiveSession.value) {
    errorMessage.value = 'This device already has active internet access. Disconnect from WiFi and reconnect if you need the captive portal again.';
    return;
  }

  if (!existingClient.value) {
    const validationError = validateRegistrationForm();

    if (validationError) {
      errorMessage.value = validationError;
      return;
    }
  }

  errorMessage.value = '';
  await loadPlans();
};

const payWithGCash = async (planId) => {
  errorMessage.value = '';
  loadingPlanId.value = planId;

  try {
    const payload = {
      plan_id: planId,
      portal_token: portalToken.value,
    };

    if (!existingClient.value) {
      payload.client_registration = {
        name: registrationForm.value.name,
        phone_number: registrationForm.value.phone_number,
        pin: registrationForm.value.pin,
        pin_confirmation: registrationForm.value.pin_confirmation,
      };
    }

    const selectResp = await window.axios.post('/api/select-plan', payload);
    const sessionToken = selectResp?.data?.data?.session_token;
    const paymentResp = await window.axios.post('/api/create-payment', { session_token: sessionToken });
    const paymentUrl = paymentResp?.data?.data?.payment_url;

    if (!paymentUrl) {
      throw new Error('Payment page URL not returned.');
    }

    window.location.href = paymentUrl;
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || error?.message || 'Unable to process payment.';
    loadingPlanId.value = null;
  }
};
</script>

<template>
  <Head title="Connect to WiFi" />

  <div class="min-h-screen bg-[linear-gradient(180deg,#f7f9fb_0%,#eef2f7_100%)]">
    <main class="grid min-h-screen grid-cols-1 lg:grid-cols-12">
      <section class="relative hidden overflow-hidden bg-[linear-gradient(160deg,#131b2e_0%,#0d1324_100%)] lg:col-span-5 lg:flex lg:items-center lg:justify-center lg:p-14">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(91,184,254,0.18),transparent_24%),radial-gradient(circle_at_bottom_right,rgba(78,222,163,0.14),transparent_22%)]" />
        <div class="relative z-10 max-w-lg">
          <span class="inline-flex rounded-full bg-sky-400/15 px-4 py-1 text-xs font-bold uppercase tracking-[0.24em] text-sky-200">
            BruckeLab Captive Portal
          </span>
          <h1 class="mt-8 text-6xl font-extrabold leading-[1.02] tracking-[-0.08em] text-white">
            Connect your
            <span class="font-light">device.</span>
          </h1>
          <p class="mt-6 text-lg leading-8 text-slate-300">
            Register the client first, then continue to plan selection. No navigation clutter, no dashboard chrome, just the captive portal flow.
          </p>

          <div v-if="portalContext?.site_name || portalContext?.ap_name || portalContext?.ssid_name" class="mt-10 rounded-[24px] border border-white/10 bg-white/8 px-5 py-5 backdrop-blur-sm">
            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-white/55">Detected Network Context</p>
            <div class="mt-4 space-y-2 text-sm text-slate-300">
              <p>Site: {{ portalContext?.site_name || 'Unknown' }}</p>
              <p>Access point: {{ portalContext?.ap_name || portalContext?.ap_mac || 'Unknown' }}</p>
              <p>SSID: {{ portalContext?.ssid_name || 'Unknown' }}</p>
            </div>
          </div>
        </div>
      </section>

      <section class="flex min-h-screen items-center justify-center px-6 py-10 sm:px-8 lg:col-span-7 lg:px-16 lg:py-16">
        <div class="w-full max-w-2xl">
          <div class="app-card-strong p-6 sm:p-8">
            <div class="mb-8">
              <p class="app-kicker">Client Registration</p>
              <h2 class="mt-3 text-4xl font-black tracking-[-0.05em] text-slate-950">Register your device</h2>
              <p class="mt-3 text-sm leading-7 text-slate-500">
                Enter the device details below. Plans are loaded only after you continue.
              </p>
            </div>

            <div v-if="bootstrapLoading" class="mb-6 rounded-[22px] border border-slate-200/70 bg-slate-50/80 px-5 py-4 text-sm text-slate-500">
              Detecting MAC address from Omada and loading portal context...
            </div>

            <div v-if="bootstrapError" class="mb-6 rounded-[22px] border border-amber-200/70 bg-amber-50/90 px-5 py-4 text-sm text-amber-700">
              {{ bootstrapError }}
              <button type="button" class="ml-2 font-semibold underline" @click="fetchBootstrap">Retry</button>
            </div>

            <div v-if="hasActiveSession" class="mb-6 rounded-[22px] border border-sky-200/70 bg-sky-50/90 px-5 py-5">
              <p class="text-sm font-semibold text-sky-950">This device already has active internet access.</p>
              <p class="mt-1 text-sm text-sky-700">
                The captive sign-in form is blocked while the session is active. Open WiFi settings again after disconnecting if you need to reconnect.
              </p>

              <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-[18px] bg-white/80 px-4 py-4">
                  <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Plan</p>
                  <p class="mt-2 text-lg font-semibold text-slate-950">{{ activeSession?.plan?.name || 'Active session' }}</p>
                </div>
                <div class="rounded-[18px] bg-white/80 px-4 py-4">
                  <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Time Remaining</p>
                  <p class="mt-2 text-lg font-semibold text-slate-950">{{ activeSessionRemainingLabel || '0:00' }}</p>
                </div>
              </div>

              <div class="mt-4 space-y-2 text-sm text-sky-800">
                <p><span class="font-semibold">Name:</span> {{ activeSession?.client_name || existingClient?.name || 'Unknown client' }}</p>
                <p><span class="font-semibold">Phone:</span> {{ activeSession?.phone_number || existingClient?.phone_number || 'Unknown phone' }}</p>
                <p><span class="font-semibold">MAC:</span> {{ activeMacAddress }}</p>
              </div>
            </div>

            <div v-else-if="existingClient" class="mb-6 rounded-[22px] border border-emerald-200/70 bg-emerald-50/90 px-5 py-4">
              <p class="text-sm font-semibold text-emerald-900">Welcome back, {{ existingClient.name }}.</p>
              <p class="mt-1 text-sm text-emerald-700">Your device is already registered. Plans will load only after you continue.</p>
            </div>

            <div v-if="!existingClient && !hasActiveSession" class="space-y-6">
              <div>
                <label class="app-label" for="mac_address">MAC Address</label>
                <div
                  id="mac_address"
                  class="app-field flex h-14 items-center font-mono"
                  :class="hasDetectedMacAddress ? 'text-slate-950' : 'text-slate-400'"
                >
                  {{ hasDetectedMacAddress ? activeMacAddress : 'Waiting for Omada MAC detection' }}
                </div>
                <p class="mt-2 text-sm text-slate-500">
                  This field is controller-driven and cannot be edited by the client.
                </p>
              </div>

              <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                  <label class="app-label" for="name">Full Name</label>
                  <input id="name" v-model="registrationForm.name" type="text" class="app-field h-14" />
                </div>

                <div>
                  <label class="app-label" for="phone_number">Phone Number</label>
                  <input id="phone_number" v-model="registrationForm.phone_number" type="tel" class="app-field h-14" placeholder="09XXXXXXXXX" />
                </div>
              </div>

              <div>
                <label class="app-label" for="pin">PIN</label>
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                  <input
                    id="pin"
                    v-model="registrationForm.pin"
                    type="password"
                    maxlength="20"
                    class="app-field h-14 text-center text-xl tracking-[0.45em]"
                    placeholder="PIN"
                  />
                  <input
                    id="pin_confirmation"
                    v-model="registrationForm.pin_confirmation"
                    type="password"
                    maxlength="20"
                    class="app-field h-14 text-center text-xl tracking-[0.45em]"
                    placeholder="Confirm PIN"
                  />
                </div>
                <p class="mt-2 text-sm text-slate-500">
                  Plan selection stays separate. Confirm your PIN first, then continue to load plans.
                </p>
              </div>

              <button class="app-button-primary h-14 w-full rounded-[22px]" :disabled="!hasDetectedMacAddress || plansLoading" @click="continueToPlans">
                {{ plansLoading ? 'Loading plans...' : 'Continue to plan selection' }}
                <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
              </button>
            </div>

            <div v-else-if="!hasActiveSession" class="space-y-6">
              <div class="rounded-[22px] bg-slate-50/90 px-5 py-4">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Registration Summary</p>
                <div class="mt-3 space-y-2 text-sm text-slate-600">
                  <p><span class="font-semibold text-slate-950">MAC:</span> {{ activeMacAddress }}</p>
                  <p><span class="font-semibold text-slate-950">Name:</span> {{ existingClient?.name }}</p>
                  <p><span class="font-semibold text-slate-950">Phone:</span> {{ existingClient?.phone_number }}</p>
                </div>
              </div>

              <button class="app-button-primary h-14 w-full rounded-[22px]" :disabled="!hasDetectedMacAddress || plansLoading" @click="continueToPlans">
                {{ plansLoading ? 'Loading plans...' : 'Continue to plan selection' }}
                <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
              </button>
            </div>

            <div v-if="(plansRequested || plansLoading) && !hasActiveSession" class="mt-8 space-y-6">
              <div>
                <p class="app-kicker">Plan Selection</p>
                <h3 class="mt-2 text-2xl font-bold tracking-[-0.04em] text-slate-950">Choose a plan</h3>
                <p class="mt-2 text-sm text-slate-500">Pay using QRPh or GCash to activate WiFi access immediately.</p>
              </div>

              <div v-if="plansLoading" class="grid gap-4 md:grid-cols-2">
                <div v-for="placeholder in 4" :key="placeholder" class="animate-pulse rounded-[24px] border border-slate-200/80 bg-white/80 p-5">
                  <div class="h-5 w-32 rounded bg-slate-200"></div>
                  <div class="mt-3 h-4 w-24 rounded bg-slate-200"></div>
                  <div class="mt-6 h-8 w-28 rounded bg-slate-200"></div>
                  <div class="mt-5 h-11 rounded-[20px] bg-slate-200"></div>
                </div>
              </div>

              <div v-else-if="plans.length" class="grid gap-4 md:grid-cols-2">
                <article v-for="plan in plans" :key="plan.id" class="rounded-[24px] border border-slate-200/80 bg-white/80 p-5 shadow-[0_16px_36px_-28px_rgba(19,27,46,0.35)]">
                  <p class="text-lg font-semibold text-slate-950">{{ plan.name }}</p>
                  <p class="mt-1 text-sm text-slate-500">{{ plan.duration_minutes }} minutes</p>
                  <p v-if="plan.speed_limit" class="mt-1 text-xs text-slate-500">{{ plan.speed_limit }}</p>
                  <p class="mt-5 text-3xl font-semibold tracking-[-0.05em] text-slate-950">{{ formatCurrency(plan.customer_price ?? plan.price) }}</p>
                  <p class="mt-2 text-sm text-slate-500">Final payable amount</p>
                  <button
                    class="app-button-primary mt-5 w-full rounded-[20px]"
                    :disabled="loadingPlanId === plan.id"
                    @click="payWithGCash(plan.id)"
                  >
                    {{ loadingPlanId === plan.id ? 'Preparing payment...' : 'Pay via QRPh' }}
                  </button>
                </article>
              </div>

              <div v-else class="app-empty">
                No plans are available right now.
              </div>
            </div>
          </div>

          <p v-if="plansError" class="mt-6 rounded-[22px] border border-amber-200/70 bg-amber-50/90 px-5 py-4 text-sm text-amber-700">
            {{ plansError }}
          </p>
          <p v-if="errorMessage" class="mt-6 rounded-[22px] border border-rose-200/70 bg-rose-50/90 px-5 py-4 text-sm text-rose-700">
            {{ errorMessage }}
          </p>
        </div>
      </section>
    </main>
  </div>
</template>
