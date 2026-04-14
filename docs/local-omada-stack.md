# Local Stack: MikroTik + Omada + Laravel

This setup is for a **test lab**, not production. It is good enough to validate:

- MikroTik routing, DHCP, firewall, and VLANs
- Omada AP adoption and captive portal redirection
- Your Laravel portal, payments, sessions, and admin dashboard

It is not good enough for production uptime. Your laptop is still a laptop.

## Topology

```text
ISP/ONT
  |
MikroTik router
  |
Indoor managed PoE switch
  |-- Local machine running Docker
  |-- Indoor Omada APs
  |-- Outdoor Omada APs
```

Recommended roles:

- `MikroTik`: routing, NAT, DHCP, firewall, VLAN trunk/access ports
- `Omada Controller`: AP inventory, SSIDs, portal policy, client tracking
- `Laravel app`: portal UI, plans, payments, session logging, dashboard

Do not move captive portal ownership to MikroTik if you want clean per-AP attribution later. That is the wrong split.

## What this repository now provides

- Dockerized Laravel app on `http://<your-laptop-ip>:8080`
- Dockerized MySQL on host port `3307`
- Dockerized queue worker
- Dockerized Omada controller on:
  - `https://<your-laptop-ip>:8043`
  - `http://<your-laptop-ip>:8088`
  - `https://<your-laptop-ip>:8843` for portal traffic

The Omada image is **community-maintained** (`mbentley/omada-controller`), not an official TP-Link image.

## Files added

- `docker-compose.yml`
- `Dockerfile`
- `.env.docker.example`
- `docker/entrypoint.sh`
- `docker/apache-vhost.conf`
- `docker/php.ini`

## First boot

1. Copy the Docker environment file.

```bash
cp .env.docker.example .env.docker
```

2. Generate a Laravel app key and paste it into `APP_KEY` in `.env.docker`.

```bash
php artisan key:generate --show
```

3. Set `APP_URL` in `.env.docker` to your laptop's **LAN IP**, not `localhost`.

Example:

```env
APP_URL=http://192.168.1.10:8080
APP_PORT=8080
```

4. Start the stack.

```bash
docker compose --env-file .env.docker up -d --build
```

5. Check status.

```bash
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs -f omada-controller
```

## Default access points

- Laravel portal: `http://<your-laptop-ip>:8080`
- Omada controller: `https://<your-laptop-ip>:8043`
- MySQL from host: `127.0.0.1:3307`

## Omada controller notes

### Important

The Omada controller must advertise your **laptop LAN IP** as its hostname/IP. If it advertises `localhost`, a Docker bridge address, or some garbage VM address, adoption will fail.

After the Omada UI is up:

1. Log in to `https://<your-laptop-ip>:8043`
2. Finish the initial controller wizard
3. Set the controller hostname/IP to your laptop LAN IP
4. Create your site and SSIDs
5. Adopt the Omada switch/APs

### Port requirements

The Omada controller needs these exposed:

- `8043/TCP` management HTTPS
- `8088/TCP` management HTTP
- `8843/TCP` captive portal HTTPS
- `27001/UDP` app discovery
- `27002/TCP` app-related service traffic
- `29810/UDP` device discovery
- `29811-29817/TCP` device management and adoption

## macOS warning

If you are running Docker Desktop on macOS, Omada discovery can be flaky because Docker networking on Mac is not the same as a real Linux host.

If AP discovery or adoption fails:

1. Make sure your Mac firewall is not blocking the published ports.
2. Upgrade Docker Desktop. Host networking on Docker Desktop requires version `4.34+`.
3. If Docker networking still causes discovery issues, run Omada natively on the host and keep only the portal/database in Docker.

Do not waste hours pretending a bad Docker Desktop networking setup is fine. It is not.

## MikroTik baseline config

Start with this network split:

- `VLAN 10`: management
- `VLAN 20`: guest captive portal
- `VLAN 30`: staff/admin

Baseline behavior:

- trunk VLANs from MikroTik to the indoor managed switch
- put Omada AP management on `VLAN 10`
- bind guest SSID to `VLAN 20`
- keep your laptop reachable from the management network
- allow guest VLAN traffic to reach only what is required before login

You still need to configure DHCP scopes, firewall rules, and trunk/access ports correctly on MikroTik. Bad VLAN work will break adoption and portal testing immediately.

## Captive portal integration status

This Docker stack gives you the infrastructure. The app now includes:

- `sites` table
- `access_points` table
- AP/site attribution fields on `wifi_sessions`
- dashboard summaries for AP and site revenue/activity
- session/payment admin views with AP and site context

What it still does **not** do:

- Omada API sync jobs
- automated controller reconciliation for AP online/offline state
- full vendor adapter support beyond the captured portal context
- external portal callback normalization beyond the query/context fields already accepted

So the data model is no longer the blocker. The remaining blocker is controller integration depth.

## Suggested next implementation step

Build these pieces next:

1. Omada API sync command/job
2. controller-driven AP inventory refresh
3. active client polling from controller data
4. automated online/offline reconciliation for APs
5. richer AP inventory fields like vendor, model, and management IP

## Useful commands

Rebuild after Dockerfile changes:

```bash
docker compose --env-file .env.docker up -d --build
```

Run Laravel commands in the container:

```bash
docker compose --env-file .env.docker exec portal php artisan migrate
docker compose --env-file .env.docker exec portal php artisan test
docker compose --env-file .env.docker exec portal php artisan optimize:clear
```

Tail controller logs:

```bash
docker compose --env-file .env.docker logs -f omada-controller
```
