# CaptivePortal

## Project Context

This project is a paid Wi-Fi captive portal for `KennFi Lab`.

The target setup is straightforward:

- Omada EAPs broadcast the guest SSID
- Omada redirects guest clients to this Laravel application
- the client selects a plan and registers or reuses an existing profile
- the app creates a Wi-Fi session and sends the client to checkout
- PayMongo confirms payment through webhook callbacks
- the admin dashboard tracks sessions, payments, access points, and site-level performance

This is not a generic Laravel starter anymore. It is already shaped around Omada-based captive portal operations.

## Features

- Public captive portal landing page
- Plan selection flow for guest Wi-Fi access
- Client registration with name, phone number, PIN, and MAC address binding
- Existing client detection by MAC address
- Session creation with AP, site, SSID, and client IP attribution
- PayMongo checkout session creation
- PayMongo webhook handling and payment status updates
- Admin login and protected admin panel
- Admin dashboard with revenue, sessions, AP inventory, and site summaries
- Controller settings management for Omada integration
- Access point inventory management with manual CRUD and Omada sync
- Plan management for promos, duration, pricing, pause support, and anti-tethering flags
- Session and payment admin views
- Session expiration command for timed access control
- Docker-based local stack for Laravel, MySQL, queue worker, and Omada controller

## What Is Already Setup

The core foundation is already in place:

- Laravel 11 backend on PHP 8.2
- Inertia + Vue 3 frontend
- Tailwind CSS UI layer
- Breeze-based authentication flow for admin access
- Database schema for:
  - plans
  - clients
  - wifi sessions
  - payments
  - sites
  - access points
  - controller settings
- Public routes for:
  - captive portal entry
  - plan selection API
  - payment creation
  - PayMongo webhook
  - payment success and failure pages
- Admin routes for:
  - dashboard
  - controller settings
  - AP inventory
  - plans
  - sessions
  - payments
- Omada service layer for:
  - connection testing
  - AP sync
  - client MAC lookup by IP
- Console commands for:
  - `omada:sync-access-points`
  - `wifi:expire-sessions`
- Seeded sample plans
- Feature tests for key admin and portal flows
- Docker environment template in [docs/local-omada-stack.md](/Users/laptop-130/Projects/CaptivePortal/docs/local-omada-stack.md)

## Docker Setup and How To Run

Use Docker for the lab environment. Do not point production traffic at a half-configured laptop stack and call it done.

### Prerequisites

- Docker Desktop or Docker Engine
- an available LAN IP for the machine running the stack
- a valid `APP_KEY`
- open ports for the Laravel app and Omada controller

### First-Time Setup

1. Copy the Docker environment file.

```bash
cp .env.docker.example .env.docker
```

2. Generate an application key.

```bash
php artisan key:generate --show
```

3. Paste that value into `APP_KEY` inside `.env.docker`.

4. Set `APP_URL` in `.env.docker` to the actual LAN IP and app port of the machine running Docker.

Example:

```env
APP_URL=http://192.168.1.10:8080
APP_PORT=8080
```

5. Set the database passwords and PayMongo keys properly.

6. Start the full stack.

```bash
docker compose --env-file .env.docker up -d --build
```

### Services Exposed by Docker

- Laravel portal: `http://<your-lan-ip>:8080`
- Omada controller HTTPS: `https://<your-lan-ip>:8043`
- Omada controller HTTP: `http://<your-lan-ip>:8088`
- Omada captive portal HTTPS endpoint: `https://<your-lan-ip>:8843`
- MySQL from host: `127.0.0.1:3307`

### Useful Docker Commands

Start the stack:

```bash
docker compose --env-file .env.docker up -d --build
```

Stop the stack:

```bash
docker compose --env-file .env.docker down
```

Check running containers:

```bash
docker compose --env-file .env.docker ps
```

Tail Omada logs:

```bash
docker compose --env-file .env.docker logs -f omada-controller
```

Run Laravel commands inside the app container:

```bash
docker compose --env-file .env.docker exec portal php artisan migrate
docker compose --env-file .env.docker exec portal php artisan test
docker compose --env-file .env.docker exec portal php artisan optimize:clear
```

### Critical Runtime Note

The codebase defines scheduled tasks for AP sync and session expiration, but Docker Compose is not running a dedicated scheduler container. If you want scheduled commands to run continuously, add a cron or `php artisan schedule:work` process. Without that, the schedule definitions exist but nothing is executing them automatically.

## What Are the Things Needed To Do

The project is not production-complete. These are the real gaps:

1. Authorize or release the client on the controller after successful payment.
2. Add a proper scheduler for AP sync and session expiration.
3. Harden the Omada integration for real controller/API variation instead of assuming one response shape will hold forever.
4. Implement pause and resume behavior if `supports_pause` is meant to be a real product feature and not just a database flag.
5. Implement real anti-tethering enforcement if `enforce_no_tethering` is meant to do something beyond UI labeling.
6. Lock down production deployment with HTTPS, real queue workers, real cron, backups, and monitoring.
7. Decide the final payment provider and stop pretending both should be built at the same time.
8. Add operational flows for refunds, failed activations, duplicate payments, and support handling.
9. Finalize the Omada external portal redirect configuration and verify it against the actual controller version in use.
10. Add end-to-end testing against the real captive portal flow, not just internal app tests.

## What Are the Features

From a product perspective, the system currently supports these modules:

### Client-Side

- Guest lands on the captive portal
- MAC address is captured from redirect parameters or inferred from Omada by client IP
- Existing customer data can be reused
- Customer selects a Wi-Fi promo plan
- Session record is created before payment
- Customer is redirected to checkout
- Customer sees success or failed payment status page

### Admin-Side

- Configure Omada controller connection
- Test controller connectivity
- Sync adopted and pending APs into the local inventory
- View AP-level and site-level revenue and usage summaries
- Create, edit, and disable plans
- Review sessions and payments

### Operational

- Track AP/site attribution per Wi-Fi session
- Track claim status and metadata of access points
- Expire sessions when time runs out
- Run locally using Docker for lab validation

## How To Run the Captive Portal

Once Docker is up, the captive portal application is served from:

- `http://<your-lan-ip>:8080`

Base flow:

1. Open the Laravel portal URL directly in a browser to verify the app loads.
2. Confirm the public plan selection page renders.
3. Confirm the Omada controller is reachable.
4. Configure Omada external portal redirection to point to this Laravel app.
5. Join the guest SSID from a client device and confirm the redirect hits the portal.

If the redirect never reaches the app, do not blame Laravel first. Check controller settings, VLANs, gateway rules, DNS reachability, and whether the controller is advertising the right portal URL.

## Admin Access

Admin access is separate from the public captive portal.

- Admin login URL: `/admin/login`
- Admin shortcut URL: `/admin`
- Standard login redirect: `/login` now points to `/admin/login`
- Admin dashboard URL after login: `/admin/dashboard`

If admin login still fails, the route is not the first thing to suspect. Check these instead:

- the admin user actually exists in the `users` table
- the user has `is_admin = true`
- the app is not serving stale config or stale routes
- the session/cookie domain is correct for the host you are using
- you are not already stuck in a bad authenticated state from a previous session

This app does not use the default public Laravel `/login` flow for client users. Authentication is scoped around admin access.

## Components Needed From Me (Already Have EAPs)

Since you already have the Omada EAPs, the remaining required components are:

- Omada Controller
  - Docker on a local machine is fine for lab work
  - a proper always-on host is better for production
- Router/firewall
  - MikroTik is the cleanest fit based on the current local stack notes
- Managed PoE switch
  - needed if you want clean VLAN separation and stable AP uplinks
- Internet uplink
  - obvious, but without this payment callbacks and controller traffic become unreliable fast
- Guest VLAN and network design
  - management VLAN
  - guest VLAN
  - optional staff/admin VLAN
- Domain or stable public URL
  - required if the payment provider webhook must hit the app from the public internet
- SSL/TLS
  - non-negotiable for production payment callbacks and admin access
- Payment account
  - PayMongo if you keep the current integration
  - Xendit only if you deliberately switch providers
- Production hosting
  - VPS, on-prem server, or edge-exposed machine that can run Laravel, queue workers, and the database reliably

## Adoption Tutorial

Use this flow to get the EAPs under control without making a mess of the network:

1. Bring up the Omada Controller and make sure it advertises the correct LAN IP or hostname.
2. Put the controller and the EAPs on reachable management networking.
3. Connect the EAPs to the managed switch and power them properly.
4. Open the Omada Controller, wait for the EAPs to appear, and adopt them.
5. Rename the APs properly. Bad AP naming destroys operations later.
6. Create the guest SSID that will use the captive portal.
7. Bind the guest SSID to the correct guest VLAN.
8. Configure the portal mode to use external portal redirection to this Laravel app.
9. Save the controller connection inside the admin panel and run AP sync.
10. Confirm that each adopted AP appears in the local admin inventory with the right site and claim status.

If adoption is failing, do not debug the application first. Fix layer 2, VLANs, routing, controller IP advertisement, and firewall rules. That is where these setups usually break.

## Captive Portal Redirection

The current app expects the controller to redirect guest users to the public portal root:

- `GET /`

The application can consume common portal context fields such as:

- `clientMac` or `client_mac`
- `apMac` or `ap_mac`
- `apName` or `ap_name`
- `siteName`, `site_name`, or `site`
- `ssidName`, `ssid_name`, or `ssid`
- `clientIp` or `client_ip`

Current redirect flow:

1. Client joins the guest SSID.
2. Omada redirects the client to `http://<your-lan-ip>:8080/` or your production portal URL.
3. The app resolves MAC address and portal context.
4. The client selects a plan and submits registration data if needed.
5. The app creates a pending Wi-Fi session.
6. The app creates a PayMongo checkout session.
7. PayMongo calls the webhook after payment.
8. The app marks the session as paid and active.

Important: the database session becomes active after payment, but controller-side client authorization still needs to be completed as part of production hardening. If that controller release step is missing, this flow is incomplete.

## PayMongo or Xendit

Recommendation: use `PayMongo` for the first production release.

Reason:

- the codebase already has PayMongo service wiring
- checkout session creation is already implemented
- webhook verification is already implemented
- payment records already store `provider = paymongo`
- switching now to Xendit adds work without solving the main network/controller gap

Use `Xendit` only if there is a real business reason such as:

- required payment methods not covered by your PayMongo plan
- settlement or account operations are better on Xendit for your business
- compliance, finance, or ops teams require the change

Do not build both first. That is bad scope control.

If you decide to switch to Xendit, do it properly:

1. Introduce a payment provider interface.
2. Move provider-specific logic behind adapters.
3. Add Xendit checkout creation and webhook validation.
4. Update environment configuration.
5. Rewrite or extend tests.
6. Migrate admin reporting so provider labels stay consistent.

If there is no forcing function, keep PayMongo and ship.
