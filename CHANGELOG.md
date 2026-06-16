# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0]

### Added
- Registers WebLLM as a client-side AI Provider, so it appears in the WordPress AI Client and the Settings → AI Connectors screen, with inference running in the browser via WebGPU.
- **Settings → WebLLM** admin page for selecting the active model. The model list is loaded live from WebLLM's own catalogue in the browser and ordered by size.
- Optional **in-browser worker** bridge: an open wp-admin tab can run the model and serve PHP-initiated generation requests via a REST poll/result loop and a jobs table, with model-scoped jobs, claim-token integrity, and a liveness heartbeat.
- `ai_provider_webllm_worker_capability` and `ai_provider_webllm_timeout` filters to control the worker capability and the PHP wait timeout.

[Unreleased]: https://github.com/ProgressPlanner/ai-provider-for-webllm/compare/0.1.0...HEAD
[0.1.0]: https://github.com/ProgressPlanner/ai-provider-for-webllm/releases/tag/0.1.0
