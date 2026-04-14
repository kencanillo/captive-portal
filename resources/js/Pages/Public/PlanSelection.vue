<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  plans: {
    type: Array,
    required: true,
  },
  portalContext: {
    type: Object,
    required: true,
  },
  existingClient: {
    type: Object,
    default: null,
  },
});

const loadingPlanId = ref(null);
const errorMessage = ref('');
const showRegistrationForm = ref(!props.existingClient && !props.portalContext?.mac_address);

// Registration form data
const registrationForm = ref({
  name: '',
  phone_number: '',
  pin: '',
  confirm_pin: '',
});

// Manual MAC address input (fallback when API doesn't work)
const manualMacAddress = ref('');

const payWithGCash = async (planId) => {
  errorMessage.value = '';
  loadingPlanId.value = planId;

  try {
    const payload = {
      plan_id: planId,
      ap_mac: props.portalContext?.ap_mac || null,
      ap_name: props.portalContext?.ap_name || null,
      site_name: props.portalContext?.site_name || null,
      ssid_name: props.portalContext?.ssid_name || null,
      client_ip: props.portalContext?.client_ip || null,
    };

    // Add MAC address
    if (props.portalContext?.mac_address) {
      payload.mac_address = props.portalContext.mac_address;
    } else if (manualMacAddress.value) {
      payload.mac_address = manualMacAddress.value;
    }

    // Add client registration data for new users
    if (!props.existingClient && registrationForm.value.name) {
      payload.client_registration = {
        name: registrationForm.value.name,
        phone_number: registrationForm.value.phone_number,
        pin: registrationForm.value.pin,
      };
    }

    const selectResp = await window.axios.post('/api/select-plan', payload);

    const sessionId = selectResp?.data?.data?.session_id;

    const paymentResp = await window.axios.post('/api/create-payment', {
      session_id: sessionId,
    });

    const checkoutUrl = paymentResp?.data?.data?.checkout_url;

    if (!checkoutUrl) {
      throw new Error('Checkout URL not returned.');
    }

    window.location.href = checkoutUrl;
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || error?.message || 'Unable to process payment.';
    loadingPlanId.value = null;
  }
};

const validateRegistrationForm = () => {
  if (!registrationForm.value.name.trim()) {
    return 'Name is required.';
  }
  if (!registrationForm.value.phone_number.trim()) {
    return 'Phone number is required.';
  }
  if (!registrationForm.value.pin.trim()) {
    return 'PIN is required.';
  }
  if (registrationForm.value.pin.length < 4) {
    return 'PIN must be at least 4 characters.';
  }
  if (registrationForm.value.pin !== registrationForm.value.confirm_pin) {
    return 'PIN confirmation does not match.';
  }
  return null;
};

const canProceedToPayment = () => {
  // If we have an existing client, just need MAC address
  if (props.existingClient) {
    return props.portalContext?.mac_address || manualMacAddress.value;
  }
  
  // For new users, need registration form
  if (!showRegistrationForm.value) {
    return true; // Already registered
  }
  
  // Check if registration form is valid
  return !validateRegistrationForm() && (props.portalContext?.mac_address || manualMacAddress.value);
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
</script>

<template>
  <Head title="Connect" />

  <MainLayout title="KapitWiFi Portal">
    <section class="mx-auto max-w-2xl space-y-4">
      <!-- Welcome message for existing clients -->
      <div v-if="existingClient" class="rounded-lg bg-green-50 border border-green-200 p-6 shadow">
        <h2 class="text-lg font-semibold text-green-900">Welcome back, {{ existingClient.name }}!</h2>
        <p class="mt-1 text-sm text-green-700">Select a plan to continue your WiFi session.</p>
      </div>

      <!-- Registration form for new clients -->
      <div v-else-if="showRegistrationForm" class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Welcome to KapitWiFi</h2>
        <p class="mt-1 text-sm text-slate-600">Please register to get started with your WiFi connection.</p>

        <div class="mt-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700" for="name">Full Name</label>
            <input
              id="name"
              v-model="registrationForm.name"
              type="text"
              class="mt-1 w-full rounded-md border-slate-300 focus:border-slate-500 focus:ring-slate-500"
              placeholder="Enter your full name"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700" for="phone_number">Phone Number</label>
            <input
              id="phone_number"
              v-model="registrationForm.phone_number"
              type="tel"
              class="mt-1 w-full rounded-md border-slate-300 focus:border-slate-500 focus:ring-slate-500"
              placeholder="09XXXXXXXXX"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700" for="pin">PIN Code</label>
            <input
              id="pin"
              v-model="registrationForm.pin"
              type="password"
              class="mt-1 w-full rounded-md border-slate-300 focus:border-slate-500 focus:ring-slate-500"
              placeholder="Create a 4-digit PIN"
              maxlength="10"
              required
            />
            <p class="mt-1 text-xs text-slate-500">This PIN will be used for quick access next time.</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-700" for="confirm_pin">Confirm PIN</label>
            <input
              id="confirm_pin"
              v-model="registrationForm.confirm_pin"
              type="password"
              class="mt-1 w-full rounded-md border-slate-300 focus:border-slate-500 focus:ring-slate-500"
              placeholder="Confirm your PIN"
              maxlength="10"
              required
            />
          </div>
        </div>

        <button
          @click="proceedToPlans"
          class="mt-6 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
        >
          Continue to Plan Selection
        </button>
      </div>

      <!-- Plan selection for registered clients or after registration -->
      <div v-else class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-slate-900">Select a plan</h2>
        <p class="mt-1 text-sm text-slate-600">
          {{ existingClient ? 'Choose a plan to continue.' : 'Pay using GCash to get instant access.' }}
        </p>

        <div
          v-if="props.portalContext?.site_name || props.portalContext?.ap_name || props.portalContext?.ssid_name"
          class="mt-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700"
        >
          <p class="font-semibold text-slate-900">Detected network context</p>
          <p class="mt-1">Site: {{ props.portalContext?.site_name || 'Unknown' }}</p>
          <p>Access point: {{ props.portalContext?.ap_name || props.portalContext?.ap_mac || 'Unknown' }}</p>
          <p>SSID: {{ props.portalContext?.ssid_name || 'Unknown' }}</p>
        </div>

        <!-- MAC address section -->
        <div class="mt-4">
          <div v-if="props.portalContext?.mac_address" class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">
            <p class="font-semibold">Device detected automatically</p>
            <p class="mt-1">MAC Address: {{ props.portalContext.mac_address }}</p>
          </div>
          <div v-else>
            <label class="block text-sm font-medium text-slate-700" for="manual_mac_address">MAC Address</label>
            <input
              id="manual_mac_address"
              v-model="manualMacAddress"
              type="text"
              class="mt-1 w-full rounded-md border-slate-300 focus:border-slate-500 focus:ring-slate-500"
              placeholder="AA:BB:CC:DD:EE:FF"
              pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$"
            />
            <p class="mt-2 text-xs text-slate-500">Enter your device MAC address (format: AA:BB:CC:DD:EE:FF)</p>
            <p v-if="props.portalContext?.client_ip" class="mt-1 text-xs text-slate-500">Client IP: {{ props.portalContext.client_ip }}</p>
          </div>
        </div>
      </div>

      <!-- Plans grid -->
      <div v-if="!showRegistrationForm" class="grid gap-4 sm:grid-cols-2">
        <article v-for="plan in props.plans" :key="plan.id" class="rounded-lg bg-white p-5 shadow">
          <h3 class="text-base font-semibold text-slate-900">{{ plan.name }}</h3>
          <p class="mt-1 text-sm text-slate-600">{{ plan.duration_minutes }} minutes</p>
          <p v-if="plan.speed_limit" class="mt-1 text-xs text-slate-500">{{ plan.speed_limit }}</p>
          <p class="mt-4 text-2xl font-bold text-slate-900">₱{{ Number(plan.price).toFixed(2) }}</p>
          <button
            class="mt-4 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="loadingPlanId === plan.id || !canProceedToPayment()"
            @click="payWithGCash(plan.id)"
          >
            {{ loadingPlanId === plan.id ? 'Redirecting...' : 'Pay with GCash' }}
          </button>
        </article>
      </div>

      <!-- Error message -->
      <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ errorMessage }}
      </p>
    </section>
  </MainLayout>
</template>
