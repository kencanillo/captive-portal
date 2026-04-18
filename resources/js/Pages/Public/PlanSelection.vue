<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  plans: {
    type: Array,
    required: true,
  },
  bootstrapUrl: {
    type: String,
    required: true,
  },
  initialPortalContext: {
    type: Object,
    required: true,
  },
});

const bootstrapLoading = ref(true);
const bootstrapError = ref('');
const plans = ref(props.plans);
const portalContext = ref({
  ...props.initialPortalContext,
  mac_address: null,
});
const existingClient = ref(null);
const loadingPlanId = ref(null);
const errorMessage = ref('');
const showRegistrationForm = ref(false);
const manualMacAddress = ref('');

const registrationForm = ref({
  name: '',
  phone_number: '',
  pin: '',
  confirm_pin: '',
});

const fetchBootstrap = async () => {
  bootstrapLoading.value = true;
  bootstrapError.value = '';

  try {
    const response = await window.axios.get(props.bootstrapUrl);
    const payload = response?.data?.data || {};

    portalContext.value.mac_address = payload.mac_address || null;
    existingClient.value = payload.existing_client || null;
    showRegistrationForm.value = !existingClient.value;
  } catch (error) {
    bootstrapError.value = error?.response?.data?.message || 'Unable to load device context. You can still continue manually.';
    plans.value = [];
    existingClient.value = null;
    showRegistrationForm.value = true;
  } finally {
    bootstrapLoading.value = false;
  }
};

onMounted(() => {
  fetchBootstrap();
});

const detectedMacAddress = computed(() => portalContext.value?.mac_address || manualMacAddress.value);

const validateRegistrationForm = () => {
  if (!registrationForm.value.name.trim()) return 'Name is required.';
  if (!registrationForm.value.phone_number.trim()) return 'Phone number is required.';
  if (!registrationForm.value.pin.trim()) return 'PIN is required.';
  if (registrationForm.value.pin.length < 4) return 'PIN must be at least 4 characters.';
  if (registrationForm.value.pin !== registrationForm.value.confirm_pin) return 'PIN confirmation does not match.';

  return null;
};

const canProceedToPayment = () => {
  if (bootstrapLoading.value) return false;
  if (!detectedMacAddress.value) return false;

  if (existingClient.value) return true;

  return !showRegistrationForm.value;
};

const proceedToPlans = () => {
  const validationError = validateRegistrationForm();

  if (validationError) {
    errorMessage.value = validationError;
    return;
  }

  errorMessage.value = '';
  showRegistrationForm.value = false;
};

const payWithGCash = async (planId) => {
  errorMessage.value = '';
  loadingPlanId.value = planId;

  try {
    const payload = {
      plan_id: planId,
      mac_address: detectedMacAddress.value,
      ap_mac: portalContext.value?.ap_mac || null,
      ap_name: portalContext.value?.ap_name || null,
      site_name: portalContext.value?.site_name || null,
      ssid_name: portalContext.value?.ssid_name || null,
      radio_id: portalContext.value?.radio_id || null,
      client_ip: portalContext.value?.client_ip || null,
    };

    if (!existingClient.value) {
      payload.client_registration = {
        name: registrationForm.value.name,
        phone_number: registrationForm.value.phone_number,
        pin: registrationForm.value.pin,
      };
    }

    const selectResp = await window.axios.post('/api/select-plan', payload);
    const sessionId = selectResp?.data?.data?.session_id;
    const paymentResp = await window.axios.post('/api/create-payment', { session_id: sessionId });
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
  <Head title="Connect" />

  <MainLayout title="KennFi Lab Portal">
    <section class="mx-auto max-w-3xl space-y-4">
      <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Connect to WiFi</h2>
        <p class="mt-1 text-sm text-slate-600">
          The page shell loads first. Device context and plans follow in the background so the portal does not stall on MAC lookup.
        </p>

        <div v-if="portalContext?.site_name || portalContext?.ap_name || portalContext?.ssid_name" class="mt-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
          <p class="font-semibold text-slate-900">Detected network context</p>
          <p class="mt-1">Site: {{ portalContext?.site_name || 'Unknown' }}</p>
          <p>Access point: {{ portalContext?.ap_name || portalContext?.ap_mac || 'Unknown' }}</p>
          <p>SSID: {{ portalContext?.ssid_name || 'Unknown' }}</p>
        </div>

        <div class="mt-4">
          <div v-if="bootstrapLoading" class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
            Loading MAC address and available plans...
          </div>

          <div v-else-if="portalContext?.mac_address" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <p class="font-semibold">Device detected automatically</p>
            <p class="mt-1">MAC Address: {{ portalContext.mac_address }}</p>
          </div>

          <div v-else class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
            <label class="block text-sm font-medium text-slate-700" for="manual_mac_address">MAC Address</label>
            <input
              id="manual_mac_address"
              v-model="manualMacAddress"
              type="text"
              class="mt-1 w-full rounded-md border-slate-300 focus:border-slate-500 focus:ring-slate-500"
              placeholder="AA:BB:CC:DD:EE:FF"
            />
            <p class="mt-2 text-xs text-slate-500">MAC lookup failed or is still unavailable. Manual fallback stays enabled.</p>
          </div>
        </div>

        <p v-if="bootstrapError" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
          {{ bootstrapError }}
          <button type="button" class="ml-2 font-semibold underline" @click="fetchBootstrap">Retry</button>
        </p>
      </div>

      <div v-if="!bootstrapLoading && existingClient" class="rounded-lg border border-emerald-200 bg-emerald-50 p-6 shadow">
        <h2 class="text-lg font-semibold text-emerald-900">Welcome back, {{ existingClient.name }}!</h2>
        <p class="mt-1 text-sm text-emerald-700">Select a plan to continue your WiFi session.</p>
      </div>

      <div v-else-if="!bootstrapLoading && showRegistrationForm" class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Register your device</h2>
        <p class="mt-1 text-sm text-slate-600">Finish registration first, then move to plan selection.</p>

        <div class="mt-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700" for="name">Full Name</label>
            <input id="name" v-model="registrationForm.name" type="text" class="mt-1 w-full rounded-md border-slate-300" />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700" for="phone_number">Phone Number</label>
            <input id="phone_number" v-model="registrationForm.phone_number" type="tel" class="mt-1 w-full rounded-md border-slate-300" placeholder="09XXXXXXXXX" />
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-slate-700" for="pin">PIN Code</label>
              <input id="pin" v-model="registrationForm.pin" type="password" class="mt-1 w-full rounded-md border-slate-300" />
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700" for="confirm_pin">Confirm PIN</label>
              <input id="confirm_pin" v-model="registrationForm.confirm_pin" type="password" class="mt-1 w-full rounded-md border-slate-300" />
            </div>
          </div>
        </div>

        <button class="mt-6 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" @click="proceedToPlans">
          Continue to plan selection
        </button>
      </div>

      <div v-if="!showRegistrationForm" class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Select a plan</h2>
        <p class="mt-1 text-sm text-slate-600">Pay using GCash to get instant access.</p>

        <div v-if="bootstrapLoading" class="mt-4 grid gap-4 sm:grid-cols-2">
          <div v-for="placeholder in 4" :key="placeholder" class="animate-pulse rounded-lg border border-slate-200 p-5">
            <div class="h-4 w-24 rounded bg-slate-200"></div>
            <div class="mt-3 h-3 w-16 rounded bg-slate-200"></div>
            <div class="mt-5 h-8 w-20 rounded bg-slate-200"></div>
            <div class="mt-4 h-10 rounded bg-slate-200"></div>
          </div>
        </div>

        <div v-else-if="plans.length" class="mt-4 grid gap-4 sm:grid-cols-2">
          <article v-for="plan in plans" :key="plan.id" class="rounded-lg border border-slate-200 p-5">
            <h3 class="text-base font-semibold text-slate-900">{{ plan.name }}</h3>
            <p class="mt-1 text-sm text-slate-600">{{ plan.duration_minutes }} minutes</p>
            <p v-if="plan.speed_limit" class="mt-1 text-xs text-slate-500">{{ plan.speed_limit }}</p>
            <p class="mt-4 text-2xl font-bold text-slate-900">₱{{ Number(plan.price).toFixed(2) }}</p>
            <button
              class="mt-4 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="loadingPlanId === plan.id || !canProceedToPayment()"
              @click="payWithGCash(plan.id)"
            >
              {{ loadingPlanId === plan.id ? 'Preparing QR...' : 'Pay via QRPh' }}
            </button>
          </article>
        </div>

        <p v-else class="mt-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
          No plans are available yet.
        </p>
      </div>

      <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ errorMessage }}
      </p>
    </section>
  </MainLayout>
</template>
