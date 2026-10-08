<?php

namespace Tests\Feature\Mail;

use App\Models\MailLog;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailLogRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_logs_and_response_never_include_smtp_credentials(): void
    {
        Config::set('mail.host', 'smtp.example.test');
        Config::set('mail.port', 2525);
        Config::set('mail.username', 'private-smtp-username');
        Config::set('mail.password', 'private-smtp-password');
        Mail::fake();

        $result = MailService::sendEmail([
            'email' => 'recipient@example.test',
            'subject' => 'SMTP privacy regression',
            'template_name' => 'notify',
            'template_value' => [
                'name' => 'TXBoard',
                'content' => 'Test message',
                'url' => 'https://example.test',
            ],
        ]);

        $this->assertNull($result['error']);
        $this->assertSame('smtp.example.test', json_decode($result['config'], true)['host']);

        $stored = MailLog::query()->latest('id')->firstOrFail();
        foreach (['private-smtp-username', 'private-smtp-password'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result));
            $this->assertStringNotContainsString($secret, json_encode($stored->toArray()));
        }
    }
}
