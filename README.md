# AI Provider for WebLLM

> Run a large language model **inside the browser** — no API key, no cloud, no per-token cost — and use it as a first-class AI Provider for the WordPress AI Client.

[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.3.0-green.svg)](CHANGELOG.md)
[![Requires PHP](https://img.shields.io/badge/PHP-7.4%2B-8892bf.svg)](plugin.php)
[![Requires WordPress](https://img.shields.io/badge/WordPress-6.8%2B-21759b.svg)](plugin.php)

AI Provider for WebLLM registers [WebLLM](https://github.com/mlc-ai/web-llm) as a **client-side** provider for the [WordPress AI Client](https://github.com/WordPress/php-ai-client). The model runs in the visitor's browser via WebGPU, so text generation is private by default and costs nothing per request — there is no server or third-party API in the loop.

## Why use it

- **No API key, no bill.** Inference happens on the user's GPU. Nothing is sent to a cloud provider.
- **Private by design.** Prompts and completions never leave the browser.
- **A real AI Client provider.** It shows up in **Settings → AI** (Connectors) like any other provider, so existing AI Client code can target it.
- **Optional server-side bridge.** An open wp-admin tab can act as a worker, letting PHP-initiated generation run in that browser.

## Requirements

- WordPress 6.8 or newer
- PHP 7.4 or newer
- The **WordPress AI Client** (`WordPress\AiClient`) must be available — it is provided by WordPress' AI feature / a plugin that bundles the AI Client. Without it the provider registers nothing and stays dormant.
- A browser with **WebGPU** support (recent Chrome, Edge, or Safari Technology Preview) for the actual inference.

## Installation

1. Download or clone this repository into `wp-content/plugins/ai-provider-for-webllm`.
2. Activate **AI Provider for WebLLM** from the Plugins screen.
3. Make sure a plugin or feature providing the WordPress AI Client is also active.

```bash
cd wp-content/plugins
git clone https://github.com/ProgressPlanner/ai-provider-for-webllm.git
```

## Quick start

1. Go to **Settings → WebLLM**.
2. Pick a model. The list loads live from WebLLM's own catalogue, ordered by size — smaller models download faster and use less memory.
3. Save. The first generation downloads the model once and caches it in the browser.

That's it. Anywhere the AI Client is used in the browser, WebLLM is now a selectable provider.

## How it works

WebLLM is a **client-side** provider, which makes it different from cloud providers:

- The PHP classes here only **describe** the provider and its models so the AI Client and the **Settings → AI** Connectors screen recognise it. They never call an API.
- Actual inference runs in the browser through WebLLM's WebGPU runtime (see `assets/js`).
- The provider declares an `api_key` auth method only because core's Connectors screen surfaces credential-based connectors. The key is hardcoded to a sentinel (`not-needed`), so users are never asked for one.

<details>
<summary><strong>Optional: server-side generation via the browser bridge</strong></summary>

PHP cannot push work to a browser, so the plugin ships an opt-in bridge for PHP-initiated generation:

1. Enable **In-browser worker** on **Settings → WebLLM**.
2. Keep a wp-admin tab open. It downloads the selected model (once, then cached) and polls for jobs.
3. When PHP requests a generation, the job is queued in a database table; the worker tab claims it, runs the model, and posts the result back over REST.

This only works while a worker tab with the model loaded is connected. There is **no headless path** — cron and WP-CLI have no browser, so server-initiated generation fails loudly when no worker is available.

The worker capability defaults to `edit_posts` and can be filtered:

```php
add_filter( 'ai_provider_webllm_worker_capability', fn() => 'manage_options' );
```

</details>

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, coding standards, and the pull request process. By participating you agree to our [Code of Conduct](CODE_OF_CONDUCT.md).

## Support

For help, see [SUPPORT.md](SUPPORT.md). To report a security issue, follow [SECURITY.md](SECURITY.md) — please do not open a public issue for vulnerabilities.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
