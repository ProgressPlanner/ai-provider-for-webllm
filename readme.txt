=== AI Provider for WebLLM ===
Contributors: jdevalk
Tags: ai, webllm, webgpu, ai-client, llm
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Registers an in-browser WebLLM model as an AI Provider for the WordPress AI Client. Inference runs in the browser via WebGPU — no API keys, no cost, nothing leaves the device.

== Description ==

WebLLM runs a large language model entirely in the visitor's browser using WebGPU. This plugin exposes it as a provider for the WordPress 7.0 AI Client, so any feature built on the AI Client can use a local, private, no-cost model.

Pick a model under **Settings → WebLLM**. The model list is read live from WebLLM's own catalogue; the chosen model is downloaded once and cached in the browser.

= How it is used =

A PHP call (e.g. `wp_ai_client_prompt()`) is bridged to a connected browser "worker" tab that runs the model and returns the result. Enable **Settings → WebLLM → In-browser worker** for this.

== Known limitations ==

These are inherent to running a model in the browser; read them before relying on server-initiated generation.

* **A browser worker tab must be open and loaded.** Server-initiated (PHP) generation only works while an admin tab with the in-browser worker enabled is open and the selected model has finished loading. Close every admin tab and PHP-initiated generation fails with "no worker connected."
* **No headless path.** WP-CLI and cron have no browser, so they cannot use this provider. Use a cloud/server provider for headless work.
* **PHP-initiated generation blocks a PHP process while it waits** for the browser to answer (up to the `ai_provider_webllm_timeout` seconds, default 120). On hosts with a small PHP worker pool, many concurrent server-initiated calls can exhaust the pool. Prefer browser-initiated use where possible, and keep the timeout sane.
* **One model at a time.** The worker serves the single model selected in settings. Changing the model while a worker tab is open requires reloading that tab to apply.
* **WebGPU + a secure context are required.** The browser must support WebGPU (recent Chrome is the most reliable) and the site must be served over HTTPS or `localhost`; plain-HTTP custom domains are not a secure context and the browser disables WebGPU there.

== Filters ==

* `ai_provider_webllm_timeout` — seconds PHP waits for a worker result. Default 120.
* `ai_provider_webllm_worker_capability` — capability required to operate the worker. Default `manage_options`.

== Changelog ==

= 0.1.0 =
* Initial release.
* Registers WebLLM as a client-side AI Provider for the WordPress AI Client.
* Settings → WebLLM model picker, loaded live from WebLLM's catalogue.
* Server-initiated generation via a browser-worker bridge (jobs table + REST endpoints), with model-scoped jobs, claim-token integrity, and a liveness heartbeat.
