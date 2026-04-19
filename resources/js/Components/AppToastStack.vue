<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const toasts = ref([]);
const seenFlashMessages = new Set();
let nextToastId = 1;

const flashWatcher = () => {
  pushFlashToast(page.props.flash?.success, 'success');
  pushFlashToast(page.props.flash?.error, 'error');
  pushFlashToast(page.props.flash?.status, 'info');
};

const pushFlashToast = (message, tone) => {
  const normalized = String(message || '').trim();

  if (!normalized) {
    return;
  }

  const fingerprint = `${tone}:${normalized}`;

  if (seenFlashMessages.has(fingerprint)) {
    return;
  }

  seenFlashMessages.add(fingerprint);
  pushToast(normalized, tone);
};

const pushToast = (message, tone = 'info') => {
  const id = nextToastId++;
  const toast = {
    id,
    message,
    tone,
  };

  toasts.value.push(toast);

  window.setTimeout(() => {
    dismissToast(id);
  }, 4200);
};

const dismissToast = (id) => {
  toasts.value = toasts.value.filter((toast) => toast.id !== id);
};

const handleToastEvent = (event) => {
  const detail = event?.detail || {};
  const message = String(detail.message || '').trim();

  if (!message) {
    return;
  }

  pushToast(message, detail.tone || 'info');
};

watch(
  () => [page.props.flash?.success, page.props.flash?.error, page.props.flash?.status],
  flashWatcher,
  { immediate: true },
);

onMounted(() => {
  window.addEventListener('app:toast', handleToastEvent);
});

onBeforeUnmount(() => {
  window.removeEventListener('app:toast', handleToastEvent);
});
</script>

<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed inset-x-0 top-4 z-[120] flex justify-center px-4 sm:justify-end sm:px-6 lg:px-8">
      <div class="flex w-full max-w-sm flex-col gap-3">
        <transition-group name="toast">
          <article
            v-for="toast in toasts"
            :key="toast.id"
            class="pointer-events-auto rounded-[22px] border px-5 py-4 shadow-[0_24px_60px_-38px_rgba(15,23,42,0.5)] backdrop-blur-xl"
            :class="{
              'border-emerald-200/80 bg-emerald-50/95 text-emerald-900': toast.tone === 'success',
              'border-rose-200/80 bg-rose-50/95 text-rose-900': toast.tone === 'error',
              'border-sky-200/80 bg-sky-50/95 text-sky-900': toast.tone === 'info',
            }"
          >
            <div class="flex items-start gap-3">
              <span class="material-symbols-outlined mt-0.5 text-[18px]">
                {{ toast.tone === 'success' ? 'check_circle' : toast.tone === 'error' ? 'error' : 'info' }}
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-xs font-bold uppercase tracking-[0.18em]">
                  {{ toast.tone === 'success' ? 'Success' : toast.tone === 'error' ? 'Action failed' : 'Notice' }}
                </p>
                <p class="mt-1 text-sm leading-6">{{ toast.message }}</p>
              </div>
              <button
                type="button"
                class="rounded-full p-1 transition hover:bg-black/5"
                @click="dismissToast(toast.id)"
              >
                <span class="material-symbols-outlined text-[18px]">close</span>
              </button>
            </div>
          </article>
        </transition-group>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-move,
.toast-enter-active,
.toast-leave-active {
  transition: all 0.25s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-12px);
}
</style>
