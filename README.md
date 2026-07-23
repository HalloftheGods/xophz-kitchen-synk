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
