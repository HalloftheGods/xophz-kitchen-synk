# Xophz Kitchen Synk (WordPress Plugin)

**`xophz-kitchen-synk`** is the standalone WordPress plugin and proxy router for the **Kitchen Synk** web application within the **Xophz-COMPASS** suite.

It bridges the frontend application (`apps/kitchen-synk`) with the WordPress backend, allowing the full Kitchen Synk app to be served from a configurable URL slug, as the main site homepage (like Event Horizon), or on a targeted WordPress page.

---

## 🌟 Key Features

* **Flexible Deployment Modes**:
  * **Custom Slug Mode**: Customizable URL endpoint (default: `/kitchen-synk`).
  * **Homepage Mode**: Serves Kitchen Synk directly on your site's root front page (`/`).
  * **Target Page Mode**: Replaces content on a chosen WordPress page.
* **WordPress Admin Bar Integration**: Adds a quick access menu in the WP admin toolbar with instant navigation to Inventory, AI Recipes, Barcode Scanner, Grocery List, and WP Settings.
* **Dual-Mode Proxy Routing**:
  * **Development Mode (`WP_DEBUG` or `WP_ENV=development`)**: Proxies dynamic Vite development server requests (`http://compass:3005`) with live Hot Module Replacement (HMR) asset rewriting.
  * **Production Mode**: Serves pre-compiled static assets from `public/dist/` directly through WordPress with optimized MIME headers and caching.
* **WordPress REST API Injection**: Automatically injects global `window.wpApiSettings` (`root`, `nonce`, `pluginUrl`, `version`, `userId`, `loadMode`, `baseUrl`, `slug`) into the HTML payload for authenticated API communication.
* **Asset & Canonical Rewrite Handling**: Disables standard WordPress canonical trailing slash redirects for bundle assets and static media paths.

---

## ⚙️ Administration & Settings

1. Navigate to **WordPress Admin > Settings > Kitchen Synk**.
2. Select your desired **Load Mode**:
   * **Custom Slug**: e.g., `/kitchen-synk`
   * **Homepage Mode**: Replace home page (`/`)
   * **Target Page**: Select a WordPress page from the dropdown.
3. Toggle the **Admin Bar Menu** checkbox for quick navigation.
4. Saving settings automatically registers WordPress rewrite rules and flushes the rewrite cache.

### Credentials

Kitchen Synk keeps no keys of its own. Every secret is read server-side through the WordPress Connectors API (`includes/class-kitchen-synk-connectors.php`) and never reaches the browser, a URL, a REST response or a log:

| Use | Connector (Settings > Connectors) |
| :-- | :-- |
| Gemini (recipes, meal plans, scans, images) | `google`, sent in the `x-goog-api-key` header |
| Stripe checkout and verification | `stripe_secret_key` |
| Stripe webhook signature | `kitchen_synk_stripe_webhook_secret` (registered by this plugin) |

Each connector resolves in core's order: its environment variable, then its PHP constant, then its saved setting.

### Who can call what (`kitchen-synk/v1`)

| Access | Routes |
| :-- | :-- |
| Logged-in only | `/gemini/*`, `/generate-recipes`, `/generate-meal-plan`, `/save-recipe`, `/regenerate-recipe-image` (also capped at 20 Gemini calls per user per hour), `/quota`, `/profile`, `/update-username`, `/license`, `/referral` |
| Public on purpose, rate limited per IP (`REMOTE_ADDR`) and, where it names one, per target | `/login` (30 failures per IP and 10 per login in 15 minutes), `/register` and `/register-and-checkout` (20 per IP per hour, shared), `/send-otp` (15 per IP and 5 per email per hour), `/barcode-lookup` (120 per IP per 10 minutes), `/checkout/session` (20 per IP per hour), `/checkout/verify` (30 per IP per hour) |
| Public, no limit needed | `/me` (reports a guest), `/logout`, `/connectors/google-tag` (the public tag id) |
| Admin (`manage_options`) | `/connectors/stripe`, saving the Google tag |
| Signed by Stripe | `/stripe/webhook` |

Notes:

* `/quota` returns the caller's own daily AI quota. Only an admin may pass `user_id` to read someone else's, and reading never writes. A quota rolls over to a new day when its owner next uses AI.
* A sign-up code is good for five wrong guesses. The plan on a new account is always `starter`; a paid plan comes from a paid Stripe checkout or a referral reward, never from a request field (`/register`, `/profile`).
* `/checkout/session` checks the price with Stripe. If the Stripe price has `user_type` in its metadata, that plan is used, so tag each price with its plan (`individual`, `family`, `lifetime`) to stop a cheap price being bought under a dearer plan name.
* `/checkout/verify` only accepts a paid session less than a day old, and never signs a payer in to an account that already existed.
* Images sent to the Gemini scan routes are capped at 5 MB decoded (413 above that), and the non-REST `/api/gemini/*` paths read at most 7 MB of request body.
* The non-REST `/api/*` paths answer CORS only for this site's own origin (home and site URL). To allow another app origin, add it with the `xophz_kitchen_synk_allowed_origins` filter. Cookies are never allowed cross-origin.
* Counters live in transients and key on `REMOTE_ADDR`. Behind a proxy, make the web server set `REMOTE_ADDR` from the proxy (or map the visitor address with the `xophz_kitchen_synk_client_ip` filter), or every visitor shares one counter.

---

## 🚀 Development & Production Workflow

### Development Mode

1. Ensure WordPress `WP_DEBUG` or `WP_ENV` is set to `development`.
2. Start the Kitchen Synk dev server from the monorepo root:
   ```bash
   pnpm dev:kitchen-synk
   ```
3. Navigate to your configured endpoint (e.g., `http://localhost/kitchen-synk` or `http://localhost/`).

### Production Deployment

1. Build the standalone bundle in `apps/kitchen-synk`:
   ```bash
   pnpm build:kitchen-synk
   ```
2. Copy or symlink the build output into `public/dist/`.
3. The plugin will automatically serve static files from `public/dist/index.html`.

---

## 📁 Repository Structure

```
wp-content/plugins/xophz-kitchen-synk/
├── README.md                # Plugin documentation
├── class-kitchen-synk-api.php # REST API endpoints for AI recipes & saving
├── xophz-kitchen-synk.php   # Main WordPress plugin bootstrap & settings UI
└── public/
    └── dist/                # Production static export directory
```
