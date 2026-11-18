<?php

/**
 * Tests for the selected-model option helper and its sanitizer.
 *
 * @package WordPress\WebLlmAiProvider
 */

declare(strict_types=1);

use WordPress\WebLlmAiProvider\Models\ModelCatalog;
use WordPress\WebLlmAiProvider\Settings\AdminPage;
use WordPress\WebLlmAiProvider\Worker\WorkerController;

class Test_Model_Catalog extends WP_UnitTestCase
{
    public function test_selected_id_falls_back_to_default(): void
    {
        delete_option(ModelCatalog::OPTION);

        $this->assertSame(ModelCatalog::DEFAULT_MODEL, ModelCatalog::selectedId());
    }

    public function test_selected_id_falls_back_to_default_for_empty_option(): void
    {
        update_option(ModelCatalog::OPTION, '');

        $this->assertSame(ModelCatalog::DEFAULT_MODEL, ModelCatalog::selectedId());
    }

    public function test_selected_id_returns_stored_model(): void
    {
        update_option(ModelCatalog::OPTION, 'Llama-3.2-1B-Instruct-q4f16_1-MLC');

        $this->assertSame('Llama-3.2-1B-Instruct-q4f16_1-MLC', ModelCatalog::selectedId());
    }

    public function test_sanitize_model_strips_markup(): void
    {
        $this->assertSame('Some-Model-MLC', AdminPage::sanitizeModel(" <b>Some-Model-MLC</b>\n"));
    }

    public function test_sanitize_model_falls_back_to_default(): void
    {
        $this->assertSame(ModelCatalog::DEFAULT_MODEL, AdminPage::sanitizeModel(''));
        $this->assertSame(ModelCatalog::DEFAULT_MODEL, AdminPage::sanitizeModel(['not', 'a', 'string']));
    }

    public function test_sanitize_bool(): void
    {
        $this->assertTrue(AdminPage::sanitizeBool('1'));
        $this->assertFalse(AdminPage::sanitizeBool(''));
        $this->assertFalse(AdminPage::sanitizeBool(null));
    }

    public function test_settings_are_registered(): void
    {
        AdminPage::registerSettings();

        $settings = get_registered_settings();

        $this->assertArrayHasKey(ModelCatalog::OPTION, $settings);
        $this->assertArrayHasKey(WorkerController::OPTION_ENABLED, $settings);
    }
}
