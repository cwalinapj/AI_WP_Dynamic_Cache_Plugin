# WP Admin Plugin Suite

Part of the **AI_WP_Plugin_Family**:
- Family hub: https://github.com/

Repository for multiple WordPress plugins and a Cloudflare control plane.

This repo is now organized as a multi-product suite (not web3-only):
- `apps/webadmin-edge-agent`: production WordPress edge agent plugin.
- `apps/control-plane-worker`: Cloudflare Worker control plane.
- `plugins/ai-webadmin`: legacy all-in-one AI WebAdmin plugin.
- `plugins/tolldns`: TollDNS helper plugin.

## Repository layout

```text
repo/
  apps/
    webadmin-edge-agent/
    control-plane-worker/
  plugins/
    ai-webadmin/
    tolldns/
  docs/
  scripts/
  update-feed/
```

## Local development

### WP-Env
- Requires Node 20+ and Docker.
- Plugin mounted from `./apps/webadmin-edge-agent`.

```bash
npm install -g @wordpress/env
wp-env start
```

### Docker Compose
- WordPress plugin mounted from `./apps/webadmin-edge-agent`.

```bash
docker compose up -d
```

### Worker

```bash
cd apps/control-plane-worker
npm install
npm run dev
```

### Analytics OAuth + Deploy setup

Set worker secrets:

```bash
cd apps/control-plane-worker
wrangler secret put GOOGLE_CLIENT_ID
wrangler secret put GOOGLE_CLIENT_SECRET
wrangler secret put GOOGLE_OAUTH_REDIRECT_URI
wrangler secret put CAP_TOKEN_ANALYTICS_WRITE
```

Worker callback:
- `GET /oauth/google/callback`

WP Admin flow:
1. Open `WebAdmin Edge Agent -> Analytics & Reporting`.
2. Click `Generate Analytics API Key` and copy the one-time value.
3. Set the same value in worker secret `CAP_TOKEN_ANALYTICS_WRITE`.
4. Save GA4/GTM IDs, click `Connect Google Account`, then `Deploy GTM + GA4 Conversions`.

## Wallet login verification

`plugins/ai-webadmin` now supports wallet-signature login for:
- Ethereum (`personal_sign`)
- Solana (`signMessage`)

Worker endpoint:
- `POST /plugin/wp/auth/wallet/verify`

The worker verifies signed requests and validates wallet signatures for both networks.

## Sandbox Scheduler (Agent Voting)

Control-plane worker now supports sandbox queue orchestration with agent voting:
- `POST /plugin/wp/sandbox/request`
- `POST /plugin/wp/sandbox/vote`
- `POST /plugin/wp/sandbox/claim`
- `POST /plugin/wp/sandbox/release`
- `POST /plugin/wp/sandbox/conflicts/report`
- `POST /plugin/wp/sandbox/conflicts/list`
- `POST /plugin/wp/sandbox/conflicts/resolve`

Selection order is weighted by:
- request priority
- summed agent votes
- queue age (anti-starvation boost)

Claims are serialized with a Durable Object lock so multiple agents do not claim the same sandbox slot.
Agents can also share a conflict pool for blocked work, read active conflicts, and resolve/dismiss them after remediation.
All sandbox routes use signed plugin auth and require capability token `CAP_TOKEN_SANDBOX_WRITE`.

## Tests

### Worker tests (includes Ethereum + Solana wallet verify tests)

```bash
cd apps/control-plane-worker
npm test
```

### Edge agent PHP tests

```bash
cd apps/webadmin-edge-agent
composer install
composer test
```

## Build installable edge-agent zip

```bash
./scripts/build-webadmin-edge-agent-zip.sh
```

Artifact:
- `apps/webadmin-edge-agent/dist/webadmin-edge-agent.zip`

## CI

GitHub Actions workflow:
- Runs worker tests (including Ethereum/Solana wallet-login verification path).
- Runs WordPress edge-agent PHPUnit suite.
- Runs PHP lint for `plugins/ai-webadmin` and `plugins/tolldns`.

## GitHub repo rename

If you want the GitHub repository slug renamed from a web3-specific name, do it in:
- GitHub -> Repository -> `Settings` -> `General` -> `Repository name`

Suggested neutral name:
- `wp-admin-plugin-suite`
