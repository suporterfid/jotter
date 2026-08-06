<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AttachmentControllerLocaleTest extends TestCase
{
    public function test_invalid_upload_message_is_translated_to_portuguese(): void
    {
        app()->setLocale('pt-BR');

        $this->assertSame('Envio de arquivo inválido.', __('messages.invalid_file_upload'));
    }

    public function test_invalid_upload_message_defaults_to_english(): void
    {
        app()->setLocale('en');

        $this->assertSame('Invalid file upload.', __('messages.invalid_file_upload'));
    }
}
