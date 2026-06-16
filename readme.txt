=== AI Provider for WebLLM ===
Contributors: jdevalk, progressplanner
Tags: ai, webllm, webgpu, ai-client, llm
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Run a language model in the browser via WebGPU and use it as a client-side AI Provider for the WordPress AI Client. No API key, no cloud.

== Description ==

AI Provider for WebLLM registers [WebLLM](https://github.com/mlc-ai/web-llm) as a **client-side** provider for the WordPress AI Client. The model runs entirely in the visitor's browser using WebGPU, so text generation is private by default and costs nothing per request — there is no server or third-party API in the loop.

**Why use it**

* **No API key, no bill.** Inference happens on the user's GPU. Nothing is sent to a cloud provider.
* **Private by design.** Prompts and completions never leave the browser.
* **A real AI Client provider.** It appears under Settings &rarr; AI (Connectors) like any other provider.
* **Optional server-side bridge.** An open wp-admin tab can act as a worker, letting PHP-initiated generation run in that browser.

The plugin only describes the provider and its models so the AI Client recognises it; the actual inference is driven from the browser runtime, never from PHP.

== Requirements ==

* The WordPress AI Client must be available (provided by WordPress' AI feature or a plugin that bundles it).
* A browser with WebGPU support for inference.

== Installation ==

1. Upload the plugin to `wp-content/plugins/ai-provider-for-webllm`, or install it from your WordPress dashboard.
2. Activate **AI Provider for WebLLM** through the Plugins screen.
3. Make sure a plugin or feature providing the WordPress AI Client is active.
4. Go to **Settings &rarr; WebLLM** and pick a model.

== Frequently Asked Questions ==

= Do I need an API key? =

No. WebLLM runs in the browser, so there is no key to enter and no per-token cost.

= Where does the model run? =

Entirely in the visitor's browser via WebGPU. The model is downloaded once and cached.

= Can the server generate text on its own? =

Only with the optional in-browser worker enabled and a wp-admin tab open with the model loaded. There is no headless (cron/WP-CLI) path, because those have no browser.

= Which models are available? =

The model list is read live from WebLLM's own catalogue in the browser and ordered by size, so it is always current.

== Changelog ==

= 0.3.0 =
* Added the optional browser-worker bridge so PHP-initiated generation can run in a connected wp-admin tab.

= 0.2.0 =
* Added the Settings &rarr; WebLLM admin page for live model selection.

= 0.1.0 =
* Initial release: registers WebLLM as a client-side AI Provider.
