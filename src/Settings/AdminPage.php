<?php

declare(strict_types=1);

namespace WordPress\WebLlmAiProvider\Settings;

use WordPress\WebLlmAiProvider\Models\ModelCatalog;
use WordPress\WebLlmAiProvider\Worker\WorkerController;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and renders the Settings > WebLLM admin page.
 *
 * Core's Connectors screen handles whether the provider is on; this page handles
 * WebLLM's one real piece of configuration: which model to run. The model list is
 * read live from WebLLM's own `prebuiltAppConfig.model_list` in the browser (see
 * assets/js/model-picker.js), so it is always current and never maintained in PHP.
 * PHP only stores the chosen `model_id` string.
 *
 * @since 0.2.0
 */
class AdminPage
{
    public const OPTION_GROUP   = 'ai_provider_webllm_settings';
    public const PAGE_SLUG      = 'ai-provider-webllm';
    public const SCRIPT_HANDLE  = 'ai-provider-for-webllm-model-picker';

    /**
     * Registers the admin page hooks.
     *
     * @since 0.2.0
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Adds the submenu page under Settings.
     *
     * @since 0.2.0
     *
     * @return void
     */
    public static function addMenuPage(): void
    {
        add_options_page(
            __('WebLLM', 'ai-provider-for-webllm'),
            __('WebLLM', 'ai-provider-for-webllm'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Registers the model setting and its field.
     *
     * @since 0.2.0
     *
     * @return void
     */
    public static function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            ModelCatalog::OPTION,
            [
                'type'              => 'string',
                'default'           => ModelCatalog::DEFAULT_MODEL,
                'sanitize_callback' => [self::class, 'sanitizeModel'],
                // Exposed so the browser runtime can read which model to load.
                'show_in_rest'      => true,
            ]
        );

        add_settings_section(
            'ai_provider_webllm_model_section',
            __('Model', 'ai-provider-for-webllm'),
            [self::class, 'renderSectionDescription'],
            self::PAGE_SLUG
        );

        add_settings_field(
            ModelCatalog::OPTION,
            __('Active model', 'ai-provider-for-webllm'),
            [self::class, 'renderModelField'],
            self::PAGE_SLUG,
            'ai_provider_webllm_model_section'
        );

        register_setting(
            self::OPTION_GROUP,
            WorkerController::OPTION_ENABLED,
            [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => [self::class, 'sanitizeBool'],
                'show_in_rest'      => true,
            ]
        );

        add_settings_field(
            WorkerController::OPTION_ENABLED,
            __('In-browser worker', 'ai-provider-for-webllm'),
            [self::class, 'renderWorkerField'],
            self::PAGE_SLUG,
            'ai_provider_webllm_model_section'
        );
    }

    /**
     * Casts a setting value to a boolean.
     *
     * @since 0.3.0
     *
     * @param mixed $value The submitted value.
     * @return bool The boolean value.
     */
    public static function sanitizeBool($value): bool
    {
        return (bool) $value;
    }

    /**
     * Renders the in-browser worker toggle and its status line.
     *
     * @since 0.3.0
     *
     * @return void
     */
    public static function renderWorkerField(): void
    {
        $enabled = (bool) get_option(WorkerController::OPTION_ENABLED, false);
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr(WorkerController::OPTION_ENABLED); ?>"
                value="1"
                <?php checked($enabled); ?>
            />
            <?php esc_html_e('Run the model in this browser so server-side features can use WebLLM.', 'ai-provider-for-webllm'); ?>
        </label>
        <p class="description">
            <?php esc_html_e(
                'When enabled, an open wp-admin tab downloads the selected model (once, then cached) and '
                . 'answers server-side generation requests. The model stays loaded as you navigate; closing '
                . 'all admin tabs pauses it.',
                'ai-provider-for-webllm'
            ); ?>
        </p>
        <p id="ai-provider-webllm-worker-status" class="description" style="font-weight:600;"></p>
        <?php
    }

    /**
     * Sanitizes the selected model ID.
     *
     * The valid set is WebLLM's `prebuiltAppConfig.model_list`, which only the
     * browser knows, so PHP cannot validate against it. The picker constrains the
     * choice client-side; here we just sanitize the string and keep a non-empty value.
     *
     * @since 0.2.0
     *
     * @param mixed $value The submitted model ID.
     * @return string A sanitized model ID, falling back to the default when empty.
     */
    public static function sanitizeModel($value): string
    {
        $value = is_string($value) ? sanitize_text_field($value) : '';

        return '' !== $value ? $value : ModelCatalog::DEFAULT_MODEL;
    }

    /**
     * Enqueues the model-picker script module on this settings page only.
     *
     * @since 0.2.0
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if ('settings_page_' . self::PAGE_SLUG !== $hook) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url('assets/js/model-picker.js', dirname(__DIR__, 2) . '/plugin.php'),
            ['wp-element', 'wp-components', 'wp-i18n'],
            '0.1.0',
            true
        );

        // The WordPress components stylesheet styles the ComboboxControl.
        wp_enqueue_style('wp-components');

        // Small overrides for the model picker (depends on wp-components so it wins the cascade).
        wp_enqueue_style(
            self::SCRIPT_HANDLE,
            plugins_url('assets/css/model-picker.css', dirname(__DIR__, 2) . '/plugin.php'),
            ['wp-components'],
            '0.1.0'
        );
    }

    /**
     * Renders the section description.
     *
     * @since 0.2.0
     *
     * @return void
     */
    public static function renderSectionDescription(): void
    {
        echo '<p>' . esc_html__(
            'WebLLM runs the selected model entirely in the visitor\'s browser using WebGPU. '
            . 'The model is downloaded once and cached. Larger models are more capable but take '
            . 'longer to download and need more memory.',
            'ai-provider-for-webllm'
        ) . '</p>';
    }

    /**
     * Renders the model dropdown.
     *
     * The select is populated in the browser from WebLLM's model list. The saved
     * value is rendered as the sole option so the form still works without JS.
     *
     * @since 0.2.0
     *
     * @return void
     */
    public static function renderModelField(): void
    {
        $current = ModelCatalog::selectedId();

        // Hidden field carries the value for the standard Settings API form submit;
        // the ComboboxControl (mounted into the div below) keeps it in sync.
        printf(
            '<input type="hidden" name="%1$s" id="ai_provider_webllm_model_input" value="%2$s" />',
            esc_attr(ModelCatalog::OPTION),
            esc_attr($current)
        );

        printf(
            '<div id="ai-provider-webllm-model-picker" data-selected="%s"></div>',
            esc_attr($current)
        );

        echo '<p class="description">';
        esc_html_e(
            'Start typing to filter. The list is loaded live from WebLLM and ordered by size. '
            . 'If it does not load, your browser could not reach the WebLLM catalogue; the saved model still works.',
            'ai-provider-for-webllm'
        );
        echo '</p>';
    }

    /**
     * Renders the settings page.
     *
     * @since 0.2.0
     *
     * @return void
     */
    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';

        settings_errors(ModelCatalog::OPTION);

        echo '<form action="options.php" method="post">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
