<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatCurrency } from '@/utils/formatters';

const props = defineProps({
  portalRequestId: {
    type: String,
    required: true,
  },
  deviceContextUrl: {
    type: String,
    required: true,
  },
  plansUrl: {
    type: String,
    required: true,
  },
  initialPlans: {
    type: Array,
    default: () => [],
  },
  plansPrefetched: {
    type: Boolean,
    default: false,
  },
  deviceContextTimeoutMs: {
    type: Number,
    default: 8000,
  },
  initialPortalContext: {
    type: Object,
    required: true,
  },
  initialDeviceContextState: {
    type: Object,
    default: () => ({
      status: 'pending',
      error_code: null,
    }),
  },
});

const deviceContextLoading = ref(false);
const plansLoading = ref(!props.plansPrefetched);
const plansError = ref('');
const plans = ref(props.initialPlans);
const portalToken = ref(null);
const portalContext = ref({
  ...props.initialPortalContext,
  mac_address: null,
});
const existingClient = ref(null);
const activeSession = ref(null);
const loadingPlanId = ref(null);
const errorMessage = ref('');
const activeSessionRemainingSeconds = ref(0);
const deviceContextStatus = ref(props.initialDeviceContextState?.status || 'pending');
const deviceContextErrorCode = ref(props.initialDeviceContextState?.error_code || null);
const deviceContextMessage = ref('Detecting device...');
const deviceContextStalled = ref(false);
const deviceContextAttempts = ref(0);

const maxAutomaticDeviceContextAttempts = 3;

let activeSessionCountdownTimer = null;
let deviceContextRetryTimer = null;
let deviceContextStallTimer = null;
let bootTasksTimer = null;

const registrationForm = ref({
  name: '',
  phone_number: '',
  pin: '',
  pin_confirmation: '',
  mac_address: '',
});

const clearDeviceContextTimers = () => {
  if (deviceContextRetryTimer) {
    window.clearTimeout(deviceContextRetryTimer);
    deviceContextRetryTimer = null;
  }

  if (deviceContextStallTimer) {
    window.clearTimeout(deviceContextStallTimer);
    deviceContextStallTimer = null;
  }
};

const syncActiveSessionCountdown = () => {
  if (!activeSession.value?.end_time) {
    activeSessionRemainingSeconds.value = 0;
    return;
  }

  const endTimeMs = new Date(activeSession.value.end_time).getTime();
  activeSessionRemainingSeconds.value = Math.max(0, Math.floor((endTimeMs - Date.now()) / 1000));
};

const syncResolvedContext = (payload) => {
  portalContext.value = {
    ...portalContext.value,
    ...(payload.portal_context || {}),
  };

  portalToken.value = payload.portal_token || portalToken.value;
  registrationForm.value.mac_address = payload?.portal_context?.mac_address || registrationForm.value.mac_address;
  existingClient.value = payload.existing_client || existingClient.value;
  activeSession.value = payload.active_session || null;
  syncActiveSessionCountdown();

  if (existingClient.value) {
    registrationForm.value.name = registrationForm.value.name || existingClient.value.name || '';
    registrationForm.value.phone_number = registrationForm.value.phone_number || existingClient.value.phone_number || '';
  }
};

const describeDeviceContextFailure = (errorCode) => {
  switch (errorCode) {
    case 'missing_captive_context':
      return 'No captive portal device context detected. Connect to the Wi-Fi network and reopen the sign-in page.';
    case 'invalid_client_mac':
      return 'The captive portal MAC address was invalid. Reopen the Wi-Fi sign-in page from the network login flow.';
    case 'omada_ssl':
      return 'Device detection failed because the Omada controller certificate was rejected. Fix SSL trust or the internal controller URL, then retry.';
    case 'auth':
      return 'Device detection failed because Omada authentication did not succeed.';
    case 'controller_unavailable':
      return 'Device detection is unavailable because controller settings are incomplete.';
    case 'not_found':
      return 'The device is not visible in Omada yet. You can continue filling out the form while detection retries.';
    case 'timeout':
      return 'Omada did not answer in time. You can continue filling out the form and retry device detection.';
    default:
      return 'Device detection is unavailable right now. Retry when the controller path is healthy.';
  }
};

if (deviceContextStatus.value !== 'pending') {
  deviceContextMessage.value = describeDeviceContextFailure(deviceContextErrorCode.value);
}

const scheduleDeviceContextStallNotice = () => {
  if (deviceContextStatus.value === 'resolved') return;

  deviceContextStallTimer = window.setTimeout(() => {
    if (deviceContextStatus.value !== 'resolved') {
      deviceContextStalled.value = true;
      deviceContextMessage.value = 'Still detecting. You can continue filling out the form while device resolution runs in the background.';
    }
  }, 2000);
};

const scheduleDeviceContextRetry = (delayMs) => {
  if (deviceContextAttempts.value >= maxAutomaticDeviceContextAttempts) return;

  deviceContextRetryTimer = window.setTimeout(() => {
    fetchDeviceContext(false);
  }, delayMs);
};

const fetchDeviceContext = async (isManualRetry = false) => {
  clearDeviceContextTimers();
  deviceContextLoading.value = true;
  deviceContextStalled.value = false;

  if (isManualRetry) {
    deviceContextAttempts.value = 0;
  }

  deviceContextAttempts.value += 1;
  deviceContextMessage.value = 'Detecting device...';

  try {
    scheduleDeviceContextStallNotice();
    const response = await window.axios.get(props.deviceContextUrl, {
      timeout: props.deviceContextTimeoutMs,
      headers: {
        'X-Portal-Request-Id': props.portalRequestId,
      },
    });

    const payload = response?.data?.data || {};
    deviceContextStatus.value = payload.status || 'pending';
    deviceContextErrorCode.value = payload.error_code || null;
    syncResolvedContext(payload);

    if (deviceContextStatus.value === 'resolved') {
      deviceContextMessage.value = `Device detected${registrationForm.value.mac_address ? `: ${registrationForm.value.mac_address}` : '.'}`;
    } else {
      deviceContextMessage.value = describeDeviceContextFailure(deviceContextErrorCode.value);

      if (
        ['pending', 'retryable'].includes(deviceContextStatus.value)
        && !['missing_captive_context', 'invalid_client_mac'].includes(deviceContextErrorCode.value)
        && deviceContextAttempts.value < maxAutomaticDeviceContextAttempts
      ) {
        scheduleDeviceContextRetry(payload.retry_after_ms || 1500);
      }
    }
  } catch (error) {
    deviceContextStatus.value = 'retryable';
    deviceContextErrorCode.value = error?.code === 'ECONNABORTED' ? 'timeout' : 'request_failed';
    deviceContextMessage.value = error?.code === 'ECONNABORTED'
      ? 'Device detection timed out. You can keep filling out the form and retry.'
      : error?.response?.data?.message || 'Unable to refresh device context right now.';

    if (deviceContextAttempts.value < maxAutomaticDeviceContextAttempts) {
      scheduleDeviceContextRetry(1500);
    }
  } finally {
    deviceContextLoading.value = false;
  }
};

const loadPlans = async () => {
  if (plansLoading.value && plans.value.length > 0) {
    return;
  }

  plansLoading.value = true;
  plansError.value = '';

  try {
    const response = await window.axios.get(props.plansUrl);
    plans.value = response?.data?.data?.plans || [];
  } catch (error) {
    plansError.value = error?.response?.data?.message || 'Unable to load plans right now.';
  } finally {
    plansLoading.value = false;
  }
};

onMounted(() => {
  const queueBootTasks = () => {
    bootTasksTimer = window.setTimeout(() => {
      if (!props.plansPrefetched) {
        void loadPlans();
      }

      void fetchDeviceContext(false);
    }, 0);
  };

  if ('requestAnimationFrame' in window) {
    window.requestAnimationFrame(queueBootTasks);
  } else {
    queueBootTasks();
  }

  activeSessionCountdownTimer = window.setInterval(() => {
    syncActiveSessionCountdown();
  }, 1000);
});

onBeforeUnmount(() => {
  clearDeviceContextTimers();

  if (bootTasksTimer) {
    window.clearTimeout(bootTasksTimer);
    bootTasksTimer = null;
  }

  if (activeSessionCountdownTimer) {
    window.clearInterval(activeSessionCountdownTimer);
  }
});

const activeMacAddress = computed(() => registrationForm.value.mac_address || portalContext.value?.mac_address || '');
const hasDetectedMacAddress = computed(() => Boolean(activeMacAddress.value.trim()));
const hasActiveSession = computed(() => Boolean(activeSession.value));
const deviceContextResolved = computed(() => deviceContextStatus.value === 'resolved' && Boolean(portalToken.value) && hasDetectedMacAddress.value);
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

const validateRegistrationForm = (requireDeviceContext = true) => {
  if (requireDeviceContext && !hasDetectedMacAddress.value) return 'Device detection is still in progress.';
  if (requireDeviceContext && !portalToken.value) return 'Portal context is unavailable. Retry device detection before starting payment.';
  if (!registrationForm.value.name.trim()) return 'Name is required.';
  if (!registrationForm.value.phone_number.trim()) return 'Phone number is required.';
  if (!registrationForm.value.pin.trim()) return 'PIN is required.';
  if (registrationForm.value.pin.length < 4) return 'PIN must be at least 4 characters.';
  if (!registrationForm.value.pin_confirmation.trim()) return 'Confirm PIN is required.';
  if (registrationForm.value.pin !== registrationForm.value.pin_confirmation) return 'PIN confirmation does not match.';

  return null;
};

const paymentActionLockedReason = computed(() => {
  if (hasActiveSession.value) {
    return 'This device already has active internet access.';
  }

  const validationError = validateRegistrationForm(false);

  if (validationError) {
    return validationError;
  }

  if (!deviceContextResolved.value) {
    return deviceContextMessage.value || 'Device detection is still in progress.';
  }

  return '';
});

const payWithGCash = async (planId) => {
  errorMessage.value = '';
  loadingPlanId.value = planId;

  try {
    if (hasActiveSession.value) {
      throw new Error('This device already has active internet access. Disconnect from WiFi and reconnect if you need the captive portal again.');
    }

    const validationError = validateRegistrationForm(true);

    if (validationError) {
      throw new Error(validationError);
    }

    if (!deviceContextResolved.value) {
      throw new Error(paymentActionLockedReason.value || 'Device detection is still in progress.');
    }

    const payload = {
      plan_id: planId,
      portal_token: portalToken.value,
      client_registration: {
        name: registrationForm.value.name,
        phone_number: registrationForm.value.phone_number,
        pin: registrationForm.value.pin,
        pin_confirmation: registrationForm.value.pin_confirmation,
      },
    };

    const selectResp = await window.axios.post('/api/select-plan', payload);
    const sessionToken = selectResp?.data?.data?.session_token;
    const paymentResp = await window.axios.post('/api/create-payment', { session_token: sessionToken });
    const paymentUrl = paymentResp?.data?.data?.payment_url;

    if (!paymentUrl) {
      throw new Error('Payment page URL not returned.');
    }

    window.location.href = paymentUrl;
  } catch (error) {
    const backendCode = error?.response?.data?.data?.code;
    errorMessage.value = backendCode === 'transfer_required'
      ? error?.response?.data?.message
      : error?.response?.data?.message || error?.message || 'Unable to process payment.';
    loadingPlanId.value = null;
  }
};

const isPaymentDisabled = (planId) => {
  if (loadingPlanId.value === planId) {
    return true;
  }

  return Boolean(paymentActionLockedReason.value);
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
            The page is live immediately. Device detection runs in the background so the captive portal does not feel hung.
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
                Fill out the form while the portal resolves the device context in the background.
              </p>
            </div>

            <div
              class="mb-6 rounded-[22px] border px-5 py-4 text-sm"
              :class="deviceContextResolved ? 'border-emerald-200/70 bg-emerald-50/90 text-emerald-800' : 'border-slate-200/70 bg-slate-50/80 text-slate-600'"
            >
              <p class="font-semibold">
                {{ deviceContextResolved ? 'Device detected.' : (deviceContextStatus === 'failed' ? 'Device context unavailable.' : 'Detecting device...') }}
              </p>
              <p class="mt-1">
                {{ deviceContextMessage }}
              </p>
              <div class="mt-3 flex flex-wrap items-center gap-3 text-xs uppercase tracking-[0.18em] text-slate-500">
                <span>Status: {{ deviceContextStatus }}</span>
                <span v-if="deviceContextErrorCode">Error: {{ deviceContextErrorCode }}</span>
                <span v-if="deviceContextStalled && !deviceContextResolved">You can keep filling out the form.</span>
              </div>
              <button
                v-if="['retryable', 'failed'].includes(deviceContextStatus) && !deviceContextLoading"
                type="button"
                class="mt-3 font-semibold underline"
                @click="fetchDeviceContext(true)"
              >
                Retry device detection
              </button>
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
                <p><span class="font-semibold">MAC:</span> {{ activeMacAddress || 'Pending detection' }}</p>
              </div>
            </div>

            <div v-else-if="existingClient" class="mb-6 rounded-[22px] border border-emerald-200/70 bg-emerald-50/90 px-5 py-4">
              <p class="text-sm font-semibold text-emerald-900">Welcome back, {{ existingClient.name }}.</p>
              <p class="mt-1 text-sm text-emerald-700">Your device is already registered. Payment unlocks once device detection is ready.</p>
            </div>

            <div v-if="!hasActiveSession" class="space-y-6">
              <div>
                <label class="app-label" for="mac_address">MAC Address</label>
                <div
                  id="mac_address"
                  class="app-field flex h-14 items-center font-mono"
                  :class="hasDetectedMacAddress ? 'text-slate-950' : 'text-slate-400'"
                >
                  {{ hasDetectedMacAddress ? activeMacAddress : 'Waiting for device detection' }}
                </div>
                <p class="mt-2 text-sm text-slate-500">
                  This field is controller-driven and cannot be edited by the client.
                </p>
              </div>

              <div v-if="existingClient" class="rounded-[22px] bg-slate-50/90 px-5 py-4">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Known Account</p>
                <div class="mt-3 space-y-2 text-sm text-slate-600">
                  <p><span class="font-semibold text-slate-950">Registered Name:</span> {{ existingClient?.name }}</p>
                  <p><span class="font-semibold text-slate-950">Registered Phone:</span> {{ existingClient?.phone_number }}</p>
                </div>
                <p class="mt-3 text-sm text-slate-500">
                  PIN verification is still required before payment. Existing-device detection does not unlock checkout by itself.
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
                  Phone number plus PIN identifies the account. Internet access still depends on this detected device MAC.
                </p>
              </div>
            </div>

            <div v-if="!hasActiveSession" class="mt-8 space-y-6">
              <div>
                <p class="app-kicker">Plan Selection</p>
                <h3 class="mt-2 text-2xl font-bold tracking-[-0.04em] text-slate-950">Choose a plan</h3>
                <p class="mt-2 text-sm text-slate-500">Plans are ready as soon as the page settles. Payment unlocks when registration and device detection are both ready.</p>
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
                    :disabled="isPaymentDisabled(plan.id)"
                    @click="payWithGCash(plan.id)"
                  >
                    {{ loadingPlanId === plan.id ? 'Preparing payment...' : (deviceContextResolved ? 'Pay via QRPh' : 'Waiting for device detection') }}
                  </button>
                </article>
              </div>

              <div v-else class="app-empty">
                No plans are available right now.
              </div>

              <p class="text-sm text-slate-500">
                {{ paymentActionLockedReason || 'Device context is ready. Choose a plan to continue.' }}
              </p>
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
