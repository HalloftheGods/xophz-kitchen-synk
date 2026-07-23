# Xophz Kitchen Synk (WordPress Plugin)

**`xophz-kitchen-synk`** is the standalone WordPress plugin and proxy router for the **Kitchen Synk** Next.js application within the **Xophz-COMPASS** suite.

It bridges the Next.js frontend application (`apps/kitchen-synk`) with the WordPress backend, allowing the full Kitchen Synk web app to be served directly from a configurable WordPress URL slug (e.g. `/kitchen-synk`).

---

## 🌟 Key Features

* **Configurable Routing Slug**: Customizable URL endpoint (default: `/kitchen-synk`) managed via **Settings > Kitchen Synk** in the WordPress Admin dashboard.
* **Dual-Mode Proxy Routing**:
  * **Development Mode (`WP_DEBUG` or `WP_ENV=development`)**: Proxies dynamic Next.js development server requests (`http://compass:3005`) with live Hot Module Replacement (HMR) asset rewriting.
  * **Production Mode**: Serves pre-compiled static assets from `public/dist/` directly through WordPress with optimized MIME headers and caching.
* **WordPress REST API Injection**: Automatically injects global `window.wpApiSettings` (`root`, `nonce`, `pluginUrl`, `version`, `userId`) into the HTML payload for authenticated API communication.
* **Asset & Canonical Rewrite Handling**: Disables standard WordPress canonical trailing slash redirects for `_next/` bundle assets and static media paths.

---

## ⚙️ Administration & Settings

1. Navigate to **WordPress Admin > Settings > Kitchen Synk**.
2. Set the **Deployment Slug** (default: `kitchen-synk`).
3. Saving settings automatically registers WordPress rewrite rules and flushes the rewrite cache.

---

## 🚀 Development & Production Workflow

### Development Mode

1. Ensure WordPress `WP_DEBUG` or `WP_ENV` is set to `development`.
2. Start the Kitchen Synk Next.js dev server from the monorepo root:
   ```bash
   pnpm dev:kitchen-synk
   ```
3. Navigate to `http://localhost/kitchen-synk`.

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
├── xophz-kitchen-synk.php   # Main WordPress plugin bootstrap & proxy router
└── public/
    └── dist/                # Production static export directory
```
