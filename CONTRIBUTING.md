# Contributing

Thanks for your interest in improving **AI Provider for WebLLM**. This document covers how to get set up, the standards we follow, and how to propose changes.

## Getting started

1. Fork the repository and clone your fork into a WordPress install at `wp-content/plugins/ai-provider-for-webllm`.
2. Activate the plugin, plus a plugin or feature that provides the WordPress AI Client (`WordPress\AiClient`).
3. Install the development tooling:

   ```bash
   composer install
   ```

There is no build step for the plugin itself — it uses a hand-rolled PSR-4 autoloader (`src/autoload.php`) and ships its JavaScript and CSS as static assets under `assets/`.

## Coding standards

This project follows the [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards). Before opening a pull request:

```bash
composer lint        # check with PHPCS
composer lint:fix    # auto-fix what can be fixed (PHPCBF)
```

Please also keep these conventions in mind:

- PHP files use `declare(strict_types=1);` and the `WordPress\WebLlmAiProvider\` namespace.
- Escape on output, sanitize on input, and check capabilities and nonces on every request.
- Add a `@since` tag to new public methods and document the version.
- Keep all model knowledge in the browser — there is deliberately no maintained model list in PHP.

## Testing your changes

- Verify the provider appears under **Settings → AI** (Connectors) and that **Settings → WebLLM** loads the live model list.
- If you touch the worker bridge, enable **In-browser worker**, keep a wp-admin tab open, and confirm a queued job runs and returns.
- Test in a WebGPU-capable browser (recent Chrome or Edge).

## Pull requests

1. Create a branch off `main`.
2. Make focused commits with clear messages.
3. Reference any related issue with `Closes #123`.
4. Fill out the pull request template, including how you tested.
5. Ensure `composer lint` passes.

## Reporting bugs and requesting features

Use the [issue templates](https://github.com/ProgressPlanner/ai-provider-for-webllm/issues/new/choose). For security issues, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

By contributing, you agree that your contributions are licensed under the project's [GPL-2.0-or-later](LICENSE) license and that you will abide by our [Code of Conduct](CODE_OF_CONDUCT.md).
