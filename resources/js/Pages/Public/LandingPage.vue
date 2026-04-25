<script setup>
import { Head } from '@inertiajs/vue3';
import SvgIcon from '@/Components/SvgIcon.vue';

const props = defineProps({
  initialPlans: {
    type: Array,
    default: () => [],
  },
  plansPrefetched: {
    type: Boolean,
    default: false,
  },
  initialPortalContext: {
    type: Object,
    required: true,
  },
  adminLoginUrl: {
    type: String,
    required: true,
  },
  reconnectMessage: {
    type: String,
    required: true,
  },
});

const services = [
  {
    title: 'Captive Portal Wi-Fi System',
    description: 'A complete paid Wi-Fi access system where users connect to your guest network, select a promo, pay through supported payment channels, and receive timed internet access.',
    features: [
      'Guest Wi-Fi landing page',
      'Plan selection and timed sessions',
      'Client registration with phone and PIN',
      'MAC address-based device access',
      'PayMongo payment integration',
      'Admin dashboard for sessions and payments',
      'Omada controller integration',
      'Access point and site monitoring',
    ],
    icon: 'router',
    cta: 'Learn About Captive Portal',
  },
  {
    title: 'Wi-Fi Access Management',
    description: 'Control who can connect, how long they can stay online, and which access point or site they are using.',
    features: [
      'Session expiration tracking',
      'Device-based access control',
      'AP and site-level reports',
      'Usage and revenue visibility',
    ],
    icon: 'settings_input_component',
  },
  {
    title: 'Network Operations Support',
    description: 'We help operators prepare their Wi-Fi setup for real-world use, from Omada configuration to deployment checks.',
    features: [
      'Omada external portal setup',
      'Access point inventory sync',
      'Guest SSID configuration guidance',
      'Basic deployment and testing support',
    ],
    icon: 'sync',
  },
  {
    title: 'Admin & Operator Dashboard',
    description: 'Give admins a clear view of active sessions, payments, plans, access points, and site performance.',
    features: [
      'Revenue summaries',
      'Session records',
      'Payment tracking',
      'Plan management',
      'Site and AP management',
    ],
    icon: 'dashboard',
  },
];

const steps = [
  {
    title: 'Guest Connects to Wi-Fi',
    description: 'The user joins your guest SSID and is redirected to the captive portal.',
  },
  {
    title: 'User Selects a Plan',
    description: 'They choose the Wi-Fi promo that fits their need.',
  },
  {
    title: 'Payment Is Confirmed',
    description: 'The system creates a payment checkout and listens for payment confirmation.',
  },
  {
    title: 'Access Is Activated',
    description: 'Once paid, the system starts the user\'s timed Wi-Fi session and tracks it in the admin dashboard.',
  },
  {
    title: 'Session Expires Automatically',
    description: 'When the time runs out, the session is marked expired and the system can release the user from network access.',
  },
];

const portalFeatures = [
  { title: 'Client Registration', description: 'Collect customer name, phone number, PIN, and device information.', icon: 'person' },
  { title: 'MAC-Based Access', description: 'Bind access to the customer\'s actual device to reduce sharing and misuse.', icon: 'lock_person' },
  { title: 'Payment Tracking', description: 'Track payment status, checkout sessions, and successful transactions.', icon: 'payments' },
  { title: 'Session Management', description: 'Monitor active, pending, expired, and failed Wi-Fi sessions.', icon: 'monitoring' },
  { title: 'Omada Integration', description: 'Connect with Omada controller workflows for AP sync and client access operations.', icon: 'hub' },
  { title: 'Admin Dashboard', description: 'View plans, sessions, payments, access points, revenue, and site performance.', icon: 'dashboard' },
];

const useCases = [
  {
    title: 'Community Wi-Fi Vendo',
    description: 'Offer affordable prepaid Wi-Fi access to neighborhoods, boarding houses, and small communities.',
  },
  {
    title: 'Cafés & Small Businesses',
    description: 'Provide guest Wi-Fi without sharing passwords manually or losing control of access.',
  },
  {
    title: 'Schools & Study Areas',
    description: 'Give students timed internet access for research, online classes, and study sessions.',
  },
];

</script>

<template>
  <Head title="Brucke — Managed Wi-Fi Captive Portal" />

  <div class="min-h-screen bg-[#f3f5f8] text-slate-950">
    <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur">
      <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-950 text-sm font-black text-white">B</div>
          <div>
            <p class="text-sm font-semibold tracking-[0.2em] text-slate-500">BRUCKE</p>
            <p class="text-xs text-slate-400">Modern captive access landing</p>
          </div>
        </div>

        <nav class="hidden items-center gap-6 text-sm text-slate-500 md:flex">
          <a href="#" class="transition hover:text-slate-950">Home</a>
          <a href="#services" class="transition hover:text-slate-950">Services</a>
          <a href="#how-it-works" class="transition hover:text-slate-950">How It Works</a>
          <a href="#portal-features" class="transition hover:text-slate-950">Portal Features</a>
          <a href="#contact" class="transition hover:text-slate-950">Contact</a>
        </nav>

        <div class="flex items-center gap-3">
          <a :href="adminLoginUrl" class="hidden text-sm text-slate-500 transition hover:text-slate-950 sm:inline-flex">Login</a>
          <a href="#contact" class="inline-flex items-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Request Demo</a>
        </div>
      </div>
    </header>

    <main>
      <section class="border-b border-slate-200/80 bg-white">
        <div class="mx-auto grid max-w-7xl gap-12 px-4 py-10 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:py-16">
          <div class="flex flex-col justify-center">
            <div class="inline-flex w-fit items-center gap-2 rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-sky-700">
              <span class="h-2 w-2 rounded-full bg-sky-500"></span>
              Smart WiFi Access for Local Communities
            </div>

            <h1 class="mt-6 max-w-xl text-5xl font-black leading-none tracking-[-0.06em] text-slate-950 sm:text-6xl lg:text-7xl">
              Turn Shared Wi-Fi Into Managed, Paid Internet Access
            </h1>

            <p class="mt-6 max-w-xl text-base leading-7 text-slate-600 sm:text-lg">
              Brucke helps small communities, apartments, cafés, schools, and local operators provide controlled Wi-Fi access through a simple captive portal. Guests connect, choose a plan, pay online, and get timed internet access automatically.
            </p>

            <p class="mt-4 max-w-xl rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              {{ reconnectMessage }}
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
              <a href="#contact" class="inline-flex items-center rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                Request a Demo
              </a>
              <a href="#how-it-works" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-950">
                View How It Works
              </a>
            </div>
          </div>

          <div class="relative">
            <div class="overflow-hidden rounded-[2rem] border border-slate-200 bg-slate-950 p-3 shadow-2xl shadow-slate-950/10">
              <div class="relative min-h-[26rem] rounded-[1.4rem] bg-[radial-gradient(circle_at_top,_rgba(56,189,248,0.35),_transparent_42%),linear-gradient(135deg,_#020617_0%,_#0f172a_45%,_#1e293b_100%)]">
                <div class="absolute inset-x-6 top-6 rounded-2xl border border-white/10 bg-white/5 px-5 py-4 backdrop-blur">
                  <p class="text-xs font-semibold uppercase tracking-[0.28em] text-sky-200">Captive Access Flow</p>
                  <p class="mt-2 text-2xl font-black tracking-[-0.05em] text-white">Connect. Pay. Go Online.</p>
                </div>

                <div class="absolute left-6 right-6 top-28 grid gap-4 sm:grid-cols-3">
                  <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-300">1</p>
                    <p class="mt-3 text-lg font-semibold text-white">Join Guest Wi-Fi</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">Users connect and hit the captive portal immediately.</p>
                  </div>
                  <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-300">2</p>
                    <p class="mt-3 text-lg font-semibold text-white">Choose a Promo</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">Plans, pricing, and session limits stay clear and predictable.</p>
                  </div>
                  <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-300">3</p>
                    <p class="mt-3 text-lg font-semibold text-white">Authorize Device</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">The session turns into timed access on the actual device MAC.</p>
                  </div>
                </div>

                <div class="absolute bottom-6 left-6 right-6 rounded-[1.6rem] border border-emerald-400/20 bg-emerald-400/10 p-5">
                  <div class="flex items-center justify-between gap-4">
                    <div>
                      <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-200">Operational View</p>
                      <p class="mt-2 text-lg font-semibold text-white">Revenue, plans, clients, AP health, and session state in one dashboard.</p>
                    </div>
                    <div class="grid min-w-[8rem] gap-2 text-right">
                      <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white">Timed Access</span>
                      <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white">QR Payments</span>
                      <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white">Omada Sync</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="absolute -bottom-6 left-4 max-w-xs rounded-2xl border border-white/70 bg-white/90 p-4 shadow-2xl shadow-slate-950/10 backdrop-blur">
              <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                  <SvgIcon name="verified" class="h-5 w-5" />
                </div>
                <div>
                  <p class="text-sm font-semibold text-slate-950">Powered by Captive Portal</p>
                  <p class="text-xs text-slate-500">Guest access, timed sessions, payments, and admin monitoring in one system.</p>
                </div>
              </div>
              <p class="mt-3 text-xs italic text-slate-500">Built to help communities stay connected — one verified device at a time.</p>
            </div>
          </div>
        </div>
      </section>

      <section id="about" class="border-b border-slate-200/80 bg-[#eef1f5]">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 py-14 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8 lg:py-20">
          <div>
            <h2 class="max-w-md text-3xl font-black leading-tight tracking-[-0.05em] text-slate-950 sm:text-4xl">Built From a Simple Idea: Better Local Connectivity</h2>
            <p class="mt-5 text-base leading-7 text-slate-600">
              Brucke started with a simple goal: make internet access easier to manage in places where connection matters most. Instead of manually sharing passwords, tracking payments, or guessing who is connected, Brucke gives operators a smarter way to control Wi-Fi access.
            </p>
            <p class="mt-4 text-base leading-7 text-slate-600">
              Our system bridges the gap between people who need affordable internet and operators who need a reliable way to manage users, payments, access points, and session time.
            </p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <article class="rounded-[2rem] bg-white p-6 shadow-sm">
              <p class="text-5xl font-thin tracking-[-0.06em] text-slate-500">99.9%</p>
              <p class="mt-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">System-focused design for reliable Wi-Fi access management</p>
            </article>

            <article class="flex flex-col justify-between rounded-[2rem] bg-slate-950 p-6 text-white">
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-400/15 text-emerald-300">
                <SvgIcon name="verified" class="h-5 w-5" />
              </div>
              <div class="mt-10">
                <p class="text-xl font-semibold">Made for Local Operators</p>
                <p class="mt-2 text-sm leading-6 text-slate-300">Designed for barangays, dormitories, cafés, apartments, small businesses, and community Wi-Fi networks.</p>
              </div>
            </article>

            <div class="overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-sm sm:col-span-2">
              <div class="grid h-full min-h-64 gap-px bg-slate-200 sm:grid-cols-[1.1fr_0.9fr]">
                <div class="bg-[linear-gradient(135deg,_#e2e8f0_0%,_#ffffff_35%,_#bae6fd_100%)] p-8">
                  <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Built For Real Deployments</p>
                  <h3 class="mt-4 max-w-sm text-3xl font-black tracking-[-0.05em] text-slate-950">Stop handing out passwords. Start managing access properly.</h3>
                  <p class="mt-4 max-w-md text-sm leading-6 text-slate-600">
                    The public entry page should explain the system fast, stay usable on captive browsers, and avoid brittle third-party dependencies.
                  </p>
                </div>
                <div class="bg-[radial-gradient(circle_at_20%_20%,_rgba(14,165,233,0.2),_transparent_20%),radial-gradient(circle_at_80%_25%,_rgba(15,23,42,0.12),_transparent_18%),linear-gradient(160deg,_#0f172a_0%,_#1e293b_55%,_#334155_100%)] p-8 text-white">
                  <div class="grid h-full gap-4">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <p class="text-xs uppercase tracking-[0.24em] text-slate-300">Guest Experience</p>
                      <p class="mt-2 text-lg font-semibold">Fast first paint with a clear reconnect message.</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                      <p class="text-xs uppercase tracking-[0.24em] text-slate-300">Operator Control</p>
                      <p class="mt-2 text-lg font-semibold">Consistent sessions, payment tracking, and device authorization.</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section id="services" class="border-b border-slate-200/80 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
          <div class="text-center">
            <h2 class="text-3xl font-black tracking-[-0.05em] text-slate-950 sm:text-4xl">Services We Provide</h2>
            <p class="mx-auto mt-3 max-w-2xl text-base text-slate-500">
              Start with captive portal access today, then expand into smarter network and business automation as your community grows.
            </p>
          </div>

          <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            <article
              v-for="(service, index) in services"
              :key="service.title"
              class="flex flex-col rounded-[2rem] border p-6 transition"
              :class="index === 0 ? 'border-slate-950 bg-slate-950 text-white shadow-2xl shadow-slate-950/10' : 'border-slate-200 bg-white text-slate-950'"
            >
              <div class="flex h-10 w-10 items-center justify-center rounded-xl"
                :class="index === 0 ? 'bg-emerald-400/15 text-emerald-300' : 'bg-slate-100 text-slate-600'">
                <SvgIcon :name="service.icon" class="h-5 w-5" />
              </div>
              <h3 class="mt-5 text-lg font-semibold" :class="index === 0 ? 'text-white' : 'text-slate-950'">{{ service.title }}</h3>
              <p class="mt-2 text-sm leading-6" :class="index === 0 ? 'text-slate-300' : 'text-slate-600'">{{ service.description }}</p>
              <ul class="mt-4 space-y-2 text-sm" :class="index === 0 ? 'text-slate-300' : 'text-slate-600'">
                <li v-for="feature in service.features" :key="feature" class="flex items-start gap-2">
                  <span class="mt-1 block h-1.5 w-1.5 shrink-0 rounded-full" :class="index === 0 ? 'bg-emerald-400' : 'bg-slate-400'"></span>
                  {{ feature }}
                </li>
              </ul>
              <a
                v-if="service.cta"
                href="#contact"
                class="mt-6 inline-flex w-full items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition"
                :class="index === 0 ? 'bg-emerald-400 text-slate-950 hover:bg-emerald-300' : 'border border-slate-200 bg-white text-slate-950 hover:border-slate-300'"
              >
                {{ service.cta }}
              </a>
            </article>
          </div>
        </div>
      </section>

      <section id="how-it-works" class="border-b border-slate-200/80 bg-[#eef1f5]">
        <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
          <div class="text-center">
            <h2 class="text-3xl font-black tracking-[-0.05em] text-slate-950 sm:text-4xl">How Brucke Captive Portal Works</h2>
          </div>

          <div class="mt-10 grid gap-6 md:grid-cols-5">
            <article
              v-for="(step, index) in steps"
              :key="step.title"
              class="relative rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm"
            >
              <div class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-950 text-sm font-bold text-white">{{ index + 1 }}</div>
              <h3 class="mt-4 text-base font-semibold text-slate-950">{{ step.title }}</h3>
              <p class="mt-2 text-sm leading-6 text-slate-600">{{ step.description }}</p>
            </article>
          </div>
        </div>
      </section>

      <section id="pricing" class="border-b border-slate-200/80 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-14 text-center sm:px-6 lg:px-8 lg:py-20">
          <h2 class="text-3xl font-black tracking-[-0.05em] text-slate-950 sm:text-4xl">Flexible Wi-Fi Access Plans</h2>
          <p class="mt-3 text-base text-slate-500">Create prepaid internet promos based on your location, pricing, and customer needs.</p>

          <div class="mt-10 grid gap-6 md:grid-cols-3">
            <article class="rounded-[2rem] border border-slate-200 bg-white p-8 text-left transition">
              <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Short Access</p>
              <h3 class="mt-4 text-xl font-semibold text-slate-950">Short Access</h3>
              <p class="mt-2 text-sm leading-6 text-slate-600">For quick browsing, messaging, and temporary guests.</p>
              <ul class="mt-6 space-y-2 text-sm text-slate-600">
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> 30 minutes
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> 1 hour
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> 3 hours
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> Best for passersby and short visits
                </li>
              </ul>
              <a href="#contact" class="mt-8 inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-950 transition hover:border-slate-300">Create Short Promo</a>
            </article>

            <article class="rounded-[2rem] border border-slate-950 bg-slate-950 p-8 text-left text-white shadow-2xl shadow-slate-950/10 transition">
              <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-300">Daily Access</p>
              <h3 class="mt-4 text-xl font-semibold text-white">Daily Access</h3>
              <p class="mt-2 text-sm leading-6 text-slate-300">For students, workers, renters, and regular customers who need reliable access throughout the day.</p>
              <ul class="mt-6 space-y-2 text-sm text-slate-200">
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400"></span> 6 hours
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400"></span> 12 hours
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400"></span> 24 hours
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400"></span> Best for community Wi-Fi and daily users
                </li>
              </ul>
              <a href="#contact" class="mt-8 inline-flex w-full items-center justify-center rounded-xl bg-emerald-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300">Create Daily Promo</a>
            </article>

            <article class="rounded-[2rem] border border-slate-200 bg-white p-8 text-left transition">
              <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Custom Plans</p>
              <h3 class="mt-4 text-xl font-semibold text-slate-950">Custom Plans</h3>
              <p class="mt-2 text-sm leading-6 text-slate-600">For operators who want special pricing, longer access, or location-specific promos.</p>
              <ul class="mt-6 space-y-2 text-sm text-slate-600">
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> Weekly access
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> Site-specific pricing
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> Promo-based packages
                </li>
                <li class="flex items-center gap-2">
                  <span class="block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span> Custom speed or duration rules
                </li>
              </ul>
              <a href="#contact" class="mt-8 inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-950 transition hover:border-slate-300">Request Setup</a>
            </article>
          </div>
        </div>
      </section>

      <section id="portal-features" class="border-b border-slate-200/80 bg-[#eef1f5]">
        <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
          <div class="text-center">
            <h2 class="text-3xl font-black tracking-[-0.05em] text-slate-950 sm:text-4xl">Everything Needed to Operate Paid Wi-Fi Access</h2>
          </div>

          <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <article
              v-for="feature in portalFeatures"
              :key="feature.title"
              class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md"
            >
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                <SvgIcon :name="feature.icon" class="h-5 w-5" />
              </div>
              <h3 class="mt-5 text-lg font-semibold text-slate-950">{{ feature.title }}</h3>
              <p class="mt-2 text-sm leading-6 text-slate-600">{{ feature.description }}</p>
            </article>
          </div>
        </div>
      </section>

      <section id="use-cases" class="border-b border-slate-200/80 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
          <div class="text-center">
            <h2 class="text-3xl font-black tracking-[-0.05em] text-slate-950 sm:text-4xl">Use Cases</h2>
          </div>

          <div class="mt-10 grid gap-6 md:grid-cols-3">
            <article
              v-for="useCase in useCases"
              :key="useCase.title"
              class="rounded-[2rem] border border-slate-200 bg-[#f7f8fa] p-6 shadow-sm"
            >
              <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-950 text-white">
                <SvgIcon name="wifi_tethering" class="h-5 w-5" />
              </div>
              <h3 class="mt-5 text-lg font-semibold text-slate-950">{{ useCase.title }}</h3>
              <p class="mt-2 text-sm leading-6 text-slate-600">{{ useCase.description }}</p>
            </article>
          </div>
        </div>
      </section>

      <section id="contact" class="bg-white">
        <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
          <div class="rounded-[2rem] border border-slate-200 bg-[#f7f8fa] p-6 shadow-sm lg:p-8">
            <div class="grid gap-8 lg:grid-cols-[0.85fr_1.15fr]">
              <div>
                <h2 class="text-3xl font-black tracking-[-0.05em] text-slate-950">Ready to Launch Managed Wi-Fi Access?</h2>
                <p class="mt-4 max-w-md text-base leading-7 text-slate-600">
                  Tell us about your location, number of access points, expected users, and preferred payment flow. We'll help you plan a captive portal setup that fits your network.
                </p>

                <div class="mt-8 space-y-4 text-sm text-slate-600">
                  <div>
                    <p class="font-semibold text-slate-950">Service Area</p>
                    <p>Local community Wi-Fi, small business networks, apartments, cafés, and school access setups.</p>
                  </div>
                  <div>
                    <p class="font-semibold text-slate-950">Email</p>
                    <p>support@brucke.studio</p>
                  </div>
                  <div>
                    <p class="font-semibold text-slate-950">Current context</p>
                    <p>Site: {{ initialPortalContext.site_name || 'Direct visit' }}</p>
                    <p>SSID: {{ initialPortalContext.ssid_name || 'Unavailable until captive sign-in opens' }}</p>
                  </div>
                </div>
              </div>

              <div class="grid gap-4 sm:grid-cols-2">
                <label class="sm:col-span-1">
                  <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Full Name</span>
                  <input type="text" placeholder="Your full name" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition focus:border-slate-400" />
                </label>
                <label class="sm:col-span-1">
                  <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Email Address</span>
                  <input type="email" placeholder="you@example.com" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition focus:border-slate-400" />
                </label>
                <label class="sm:col-span-2">
                  <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Business or Location Type</span>
                  <input type="text" placeholder="e.g. apartment, café, school, community" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition focus:border-slate-400" />
                </label>
                <label class="sm:col-span-2">
                  <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Estimated Number of Users</span>
                  <input type="text" placeholder="e.g. 20-50" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition focus:border-slate-400" />
                </label>
                <label class="sm:col-span-2">
                  <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Message</span>
                  <textarea rows="5" placeholder="Tell us about your setup, number of access points, preferred payment flow, and any questions." class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-950 outline-none transition focus:border-slate-400"></textarea>
                </label>
                <div class="sm:col-span-2">
                  <button type="button" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                    Request Captive Portal Setup
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>

    <footer class="border-t border-slate-200/80 bg-white">
      <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="flex flex-col items-start justify-between gap-8 sm:flex-row sm:items-center">
          <div>
            <div class="flex items-center gap-3">
              <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-950 text-sm font-black text-white">B</div>
              <p class="text-sm font-semibold tracking-[0.2em] text-slate-500">BRUCKE</p>
            </div>
            <p class="mt-2 max-w-sm text-sm text-slate-500">
              Managed Wi-Fi access, captive portal systems, and digital tools for connected communities.
            </p>
          </div>
          <nav class="flex flex-wrap gap-6 text-sm text-slate-500">
            <a href="#" class="transition hover:text-slate-950">Privacy Policy</a>
            <a href="#" class="transition hover:text-slate-950">Terms of Service</a>
            <a href="#" class="transition hover:text-slate-950">Network Status</a>
            <a href="#" class="transition hover:text-slate-950">Support Center</a>
          </nav>
        </div>
        <p class="mt-8 text-xs text-slate-400">
          &copy; {{ new Date().getFullYear() }} Brucke. All rights reserved.
        </p>
      </div>
    </footer>
  </div>
</template>
