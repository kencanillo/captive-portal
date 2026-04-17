<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
  payment: {
    type: Object,
    required: true,
  },
  session: {
    type: Object,
    required: true,
  },
  plan: {
    type: Object,
    required: true,
  },
  statusEndpoint: {
    type: String,
    required: true,
  },
  recheckEndpoint: {
    type: String,
    required: true,
  },
  createPaymentEndpoint: {
    type: String,
    required: true,
  },
  backToPlansUrl: {
    type: String,
    required: true,
  },
});

const currentPaymentStatus = ref(props.payment.payment_status);
const currentSessionStatus = ref(props.session.session_status);
const currentReleaseFailureReason = ref(props.session.release_failure_reason);
const paidAt = ref(props.payment.paid_at);
const qrExpiresAt = ref(props.payment.qr_expires_at);
const humanMessage = ref('You may leave this page to complete payment. This page will update once payment is confirmed.');
const uiState = ref('loading');
const requestInFlight = ref(false);
const generatingNewQr = ref(false);
const errorMessage = ref('');
const remainingSeconds = ref(0);

let pollTimer = null;
let countdownTimer = null;

const pollIntervalMs = 7000;

const formattedAmount = computed(() => {
  const amount = Number(props.payment.amount ?? 0);

  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: props.payment.currency || 'PHP',
  }).format(amount);
});

const expiresInLabel = computed(() => {
  if (!qrExpiresAt.value || remainingSeconds.value <= 0) {
    return 'Expired';
  }

  const minutes = Math.floor(remainingSeconds.value / 60);
  const seconds = remainingSeconds.value % 60;

  return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const statusLabel = computed(() => {
  return {
    loading: 'Loading payment status',
    awaiting_payment: 'Waiting for payment',
    checking_status: 'Checking payment status',
    paid: 'Payment received',
    expired: 'QR expired',
    failed: 'Payment not confirmed',
    enabling_access: 'Enabling internet access',
    access_enabled: 'Internet access enabled',
    access_failed: 'Internet access failed',
  }[uiState.value] ?? 'Payment status';
});

const showQr = computed(() => {
  return ['awaiting_payment', 'checking_status'].includes(uiState.value);
});

const canGenerateNewQr = computed(() => {
  return ['expired', 'failed', 'access_failed'].includes(uiState.value);
});

const showCheckButton = computed(() => {
  return ['awaiting_payment', 'checking_status', 'enabling_access'].includes(uiState.value);
});

function resolveUiState(paymentStatus, sessionStatus) {
  if (paymentStatus === 'paid') {
    if (sessionStatus === 'active') {
      return 'access_enabled';
    }

    if (sessionStatus === 'release_failed') {
      return 'access_failed';
    }

    return 'enabling_access';
  }

  if (paymentStatus === 'expired') {
    return 'expired';
  }

  if (paymentStatus === 'failed' || paymentStatus === 'canceled') {
    return 'failed';
  }

  if (paymentStatus === 'pending' || paymentStatus === 'awaiting_payment') {
    return 'awaiting_payment';
  }

  return 'loading';
}

function applyStatusPayload(payload) {
  currentPaymentStatus.value = payload.payment_status;
  currentSessionStatus.value = payload.wifi_session_status;
  currentReleaseFailureReason.value = payload.release_failure_reason || null;
  paidAt.value = payload.paid_at;
  qrExpiresAt.value = payload.qr_expires_at;
  humanMessage.value = payload.human_message;
  uiState.value = resolveUiState(payload.payment_status, payload.wifi_session_status);

  if (uiState.value === 'enabling_access' && payload.next_step === 'show_success') {
    uiState.value = 'paid';
  }

  if (!payload.should_continue_polling) {
    stopPolling();
  } else {
    startPolling();
  }

  syncCountdown();
}

function syncCountdown() {
  if (!qrExpiresAt.value) {
    remainingSeconds.value = 0;
    return;
  }

  const expiresAtMs = new Date(qrExpiresAt.value).getTime();
  const seconds = Math.max(0, Math.floor((expiresAtMs - Date.now()) / 1000));
  remainingSeconds.value = seconds;
}

async function refreshStatus(useCheckingState = true) {
  if (requestInFlight.value) {
    return;
  }

  requestInFlight.value = true;
  errorMessage.value = '';

  if (useCheckingState && ['awaiting_payment', 'checking_status'].includes(uiState.value)) {
    uiState.value = 'checking_status';
  }

  try {
    const response = await window.axios.get(props.statusEndpoint);
    applyStatusPayload(response.data.data);
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'Unable to refresh payment status right now.';
  } finally {
    requestInFlight.value = false;
  }
}

async function manualRecheck() {
  if (requestInFlight.value) {
    return;
  }

  requestInFlight.value = true;
  errorMessage.value = '';
  uiState.value = 'checking_status';

  try {
    const response = await window.axios.post(props.recheckEndpoint);
    currentPaymentStatus.value = response?.data?.data?.payment_status || currentPaymentStatus.value;
    currentSessionStatus.value = response?.data?.data?.wifi_session_status || currentSessionStatus.value;
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'Manual payment recheck failed.';
  } finally {
    requestInFlight.value = false;
  }

  await refreshStatus(false);
}

async function generateNewQr() {
  if (generatingNewQr.value) {
    return;
  }

  generatingNewQr.value = true;
  errorMessage.value = '';

  try {
    const response = await window.axios.post(props.createPaymentEndpoint, {
      session_id: props.session.id,
    });

    const paymentUrl = response?.data?.data?.payment_url;

    if (!paymentUrl) {
      throw new Error('Payment page URL not returned.');
    }

    window.location.href = paymentUrl;
  } catch (error) {
    generatingNewQr.value = false;
    errorMessage.value = error?.response?.data?.message || error?.message || 'Unable to generate a new QR right now.';
  }
}

function startPolling() {
  if (pollTimer || !['awaiting_payment', 'checking_status', 'enabling_access'].includes(uiState.value)) {
    return;
  }

  pollTimer = window.setInterval(() => {
    refreshStatus(false);
  }, pollIntervalMs);
}

function stopPolling() {
  if (!pollTimer) {
    return;
  }

  window.clearInterval(pollTimer);
  pollTimer = null;
}

function startCountdown() {
  syncCountdown();

  countdownTimer = window.setInterval(() => {
    syncCountdown();

    if (remainingSeconds.value === 0 && ['awaiting_payment', 'checking_status'].includes(uiState.value)) {
      refreshStatus(false);
    }
  }, 1000);
}

onMounted(async () => {
  uiState.value = resolveUiState(currentPaymentStatus.value, currentSessionStatus.value);
  syncCountdown();
  startCountdown();
  await refreshStatus(false);
});

onBeforeUnmount(() => {
  stopPolling();

  if (countdownTimer) {
    window.clearInterval(countdownTimer);
    countdownTimer = null;
  }
});
</script>

<template>
  <Head title="QR Payment" />

  <MainLayout title="KennFi Lab Payment">
    <section class="mx-auto max-w-xl space-y-4">
      <div class="rounded-xl bg-white p-6 shadow">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">QRPh Payment</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ plan.name }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ formattedAmount }}</p>
          </div>

          <div class="rounded-lg bg-slate-100 px-3 py-2 text-right text-sm text-slate-700">
            <p class="font-medium">Expires in</p>
            <p class="mt-1 text-lg font-semibold text-slate-900">{{ expiresInLabel }}</p>
          </div>
        </div>

        <div v-if="showQr" class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-5 text-center">
          <img
            :src="payment.qr_image_url"
            alt="PayMongo QRPh code"
            class="mx-auto h-72 w-72 max-w-full rounded-lg bg-white object-contain p-3 shadow-sm"
          />
        </div>

        <div class="mt-6 rounded-lg border border-slate-200 p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current status</p>
          <p class="mt-2 text-lg font-semibold text-slate-900">{{ statusLabel }}</p>
          <p class="mt-2 text-sm text-slate-600">{{ humanMessage }}</p>

          <p
            v-if="currentReleaseFailureReason && uiState === 'access_failed'"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"
          >
            {{ currentReleaseFailureReason }}
          </p>

          <p
            v-if="errorMessage"
            class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"
          >
            {{ errorMessage }}
          </p>
        </div>

        <div class="mt-6 space-y-2 text-sm text-slate-600">
          <p>Scan this QR using GCash, Maya, or a supported banking/e-wallet app.</p>
          <p>You may leave this page to complete payment. This page will update once payment is confirmed.</p>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
          <button
            v-if="showCheckButton"
            type="button"
            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="requestInFlight"
            @click="manualRecheck"
          >
            {{ requestInFlight ? 'Checking...' : 'Check Payment Status' }}
          </button>

          <button
            v-if="canGenerateNewQr"
            type="button"
            class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="generatingNewQr"
            @click="generateNewQr"
          >
            {{ generatingNewQr ? 'Generating...' : 'Generate New QR' }}
          </button>

          <Link
            :href="backToPlansUrl"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            Back to Plans
          </Link>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
