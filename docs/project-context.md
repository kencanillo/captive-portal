# Project Context

## Verdict

This project is a Laravel-based captive portal integrated with an Omada controller for client authorization and PayMongo for QRPH payments.

Core app flow is mostly working:

- guest lands on captive portal
- user registers
- user selects a plan
- app creates a PayMongo QRPH Checkout Session
- PayMongo webhook marks payment as paid
- app activates the WiFi session
- app calls Omada to authorize the client

The main blocker right now is not payment. The blocker is Omada pre-auth access to the public portal domain.

## Current Architecture

### Laravel App

- Framework: Laravel + Inertia + Vue
- Public portal entrypoint: `/`
- Admin panel: `/admin/*`
- Payment webhook: `/api/paymongo/webhook`

### Omada

- Controller URL used by the app: `https://192.168.1.9:8043/`
- Guest SSID uses `External Portal Server`
- Omada passes portal context to Laravel:
  - `clientMac`
  - `apMac`
  - `ssidName`
  - `radioId`
  - `site`

### PayMongo

- Integration mode: real Checkout API, not mock
- Payment method currently intended: `qrph` only
- Webhook event used: `checkout_session.payment.paid`

### Public Portal Host

- Current public test domain: `https://corrosive-depletion-doorbell.ngrok-free.dev`
- This is being used because `localhost` and LAN IPs are not suitable for guest captive portal access

## What Has Been Implemented

### Portal and Session Flow

- Captive portal root route accepts Omada query parameters
- Registration-first flow was restored so new users register before plan selection
- Plan selection creates or resumes a WiFi session with captured portal context
- Session records include AP/site/client context needed for later authorization

### Omada Authorization

- After successful payment, the app does not just flip local DB flags
- It calls Omada to authorize the client through the external portal flow
- Session expiry also attempts to deauthorize the client in Omada instead of only expiring locally

### PayMongo Integration

- Mock payment flow was removed for the main payment path
- App now creates real PayMongo Checkout Sessions
- Checkout is configured for `qrph`
- Webhook handler marks session as paid and activates WiFi session

### Admin Visibility

- Sessions page now shows client information and time remaining
- Payments are being logged against the session and selected plan

## Important Code Paths

### Captive Portal Entry

- [routes/web.php](/Users/laptop-130/Projects/CaptivePortal/routes/web.php)
- [app/Http/Controllers/Public/CaptivePortalController.php](/Users/laptop-130/Projects/CaptivePortal/app/Http/Controllers/Public/CaptivePortalController.php)

### Plan Selection and Session Creation

- [app/Http/Controllers/Public/PlanSelectionApiController.php](/Users/laptop-130/Projects/CaptivePortal/app/Http/Controllers/Public/PlanSelectionApiController.php)
- [app/Services/WifiSessionService.php](/Users/laptop-130/Projects/CaptivePortal/app/Services/WifiSessionService.php)

### Payment Creation and Webhook Handling

- [app/Http/Controllers/Public/PaymentController.php](/Users/laptop-130/Projects/CaptivePortal/app/Http/Controllers/Public/PaymentController.php)
- [app/Services/PayMongoService.php](/Users/laptop-130/Projects/CaptivePortal/app/Services/PayMongoService.php)
- [routes/api.php](/Users/laptop-130/Projects/CaptivePortal/routes/api.php)

### Omada Authorization and Deauthorization

- [app/Services/OmadaService.php](/Users/laptop-130/Projects/CaptivePortal/app/Services/OmadaService.php)
- [app/Services/WifiSessionService.php](/Users/laptop-130/Projects/CaptivePortal/app/Services/WifiSessionService.php)

## What Has Been Verified So Far

### Working

- Laravel app is reachable on the ngrok public HTTPS domain
- App serves correct HTTPS assets through ngrok
- PayMongo webhook is configured
- PayMongo checkout page opens from the app
- QRPH checkout page is reachable
- Omada controller credentials and hotspot operator path are wired into the app
- Guest device can reach the ngrok domain when the phone is added as an `Authentication-Free Client`

### Not Reliably Working

- Normal guest clients cannot reach the ngrok portal domain through Omada pre-auth URL allow rules
- That means the captive portal cannot be reached by unauthenticated guests without MAC bypass

## Current Problem

### Real Blocker

Omada walled garden / pre-auth access is not reliably allowing normal guest users to reach the public portal domain:

- `corrosive-depletion-doorbell.ngrok-free.dev`

This is the problem. Not Laravel. Not PayMongo. Not the basic ngrok tunnel itself.

### Evidence

- From another network, the ngrok domain is reachable
- From the host machine, the ngrok domain returns `200 OK`
- From guest WiFi, the domain becomes reachable only when the phone MAC is added as `Authentication-Free Client`
- That proves the failure is in Omada pre-auth handling, not in the app

## Why This Is Happening

Most likely causes:

- Omada URL-based pre-auth access is brittle or buggy for this controller/build
- free ngrok is a bad fit for captive portal walled-garden access
- guest pre-auth domain handling does not behave reliably with this tunnel/domain path

This is exactly the kind of fragile setup that burns time. It is good enough for isolated testing, not for reliable portal access by arbitrary guest devices.

## Current Recommendation

Stop treating free ngrok as the final deployment target.

Use one of these instead:

1. Best: deploy Laravel on an always-on on-site machine with a real public domain and HTTPS
2. Second-best: deploy Laravel publicly and connect it back to the site LAN using WireGuard or Tailscale so it can still reach Omada
3. Testing-only workaround: keep your phone in `Authentication-Free Client` while validating the payment and authorization code paths

## Immediate Next Steps

### For Development Testing

- Keep phone in `Authentication-Free Client`
- Complete one cheap real QRPH payment
- Verify:
  - payment record is created
  - session becomes `paid`
  - session becomes active
  - Omada client status changes to authorized

### For Real Guest Access

- Move away from free ngrok
- Deploy the portal on a stable public HTTPS domain
- Reconfigure Omada external portal URL to that stable domain
- Keep Laravel able to reach Omada controller on the LAN or over VPN

## Environment Values That Matter

### Laravel

- `APP_URL=https://corrosive-depletion-doorbell.ngrok-free.dev`
- `PAYMONGO_SECRET_KEY=...`
- `PAYMONGO_WEBHOOK_SECRET=...`

### App Admin Controller Settings

- `Controller URL`: `https://192.168.1.9:8043/`
- `Portal base URL`: `https://corrosive-depletion-doorbell.ngrok-free.dev`
- `Controller username`: Omada local/controller user
- `Hotspot operator username`: Omada hotspot operator user

### Omada Portal

- `Authentication Type`: `External Portal Server`
- `URL`: `https://corrosive-depletion-doorbell.ngrok-free.dev/`

## Bottom Line

The app is no longer the main problem.

The current deployment target is the problem.

Payment integration is far enough along to keep testing.
Reliable guest access is blocked by Omada pre-auth behavior against a free ngrok domain.
