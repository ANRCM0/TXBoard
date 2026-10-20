<?php

namespace Tests\Unit\Core;

use App\Services\NativeSmtpDiagnostic;
use Illuminate\Mail\MailManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class NativeSmtpDiagnosticTest extends TestCase
{
    public function test_handshake_uses_saved_transport_settings_without_sending_mail_and_closes_connection(): void
    {
        $manager = $this->createMock(MailManager::class);
        $transport = $this->getMockBuilder(EsmtpTransport::class)->setConstructorArgs(['smtp.example.test', 587, false])
            ->onlyMethods(['start', 'stop', 'send', 'setAuthenticators', 'executeCommand'])->getMock();
        $authenticators = [];
        $transport->method('setAuthenticators')->willReturnCallback(function ($next) use (&$authenticators) { $authenticators = $next; });
        $transport->method('executeCommand')->willReturn('235 accepted');
        $transport->expects(self::once())->method('start')->willReturnCallback(function () use (&$authenticators, $transport) { $authenticators[1]->authenticate($transport); });
        $transport->expects(self::once())->method('stop');
        $transport->expects(self::never())->method('send');
        $manager->expects(self::once())->method('createSymfonyTransport')->with(self::callback(fn ($config) =>
            $config['host'] === 'smtp.example.test' && $config['port'] === 587 && $config['username'] === 'private-user'
            && $config['password'] === 'private-password' && $config['require_tls'] === true && $config['scheme'] === 'smtp'))
            ->willReturn($transport);
        $status = (new NativeSmtpDiagnostic($manager))->inspect($this->configuration());
        self::assertSame(['connected' => true, 'authentication_used' => true], $status);
        self::assertSame(10.0, $transport->getStream()->getTimeout());
        self::assertStringNotContainsString('private', json_encode($status));
    }

    public function test_handshake_failure_is_redacted_and_still_closes_the_transport(): void
    {
        $manager = $this->createMock(MailManager::class);
        $transport = $this->getMockBuilder(EsmtpTransport::class)->setConstructorArgs(['smtp.example.test', 587, false])
            ->onlyMethods(['start', 'stop'])->getMock();
        $transport->expects(self::once())->method('start')->willThrowException(new \RuntimeException('private-password'));
        $transport->expects(self::once())->method('stop');
        $manager->method('createSymfonyTransport')->willReturn($transport);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SMTP_DIAGNOSTIC_FAILED');
        (new NativeSmtpDiagnostic($manager))->inspect($this->configuration());
    }

    public function test_missing_or_invalid_saved_configuration_never_attempts_a_connection(): void
    {
        $manager = $this->createMock(MailManager::class);
        $manager->expects(self::never())->method('createSymfonyTransport');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SMTP_CONFIGURATION_INVALID');
        (new NativeSmtpDiagnostic($manager))->inspect(array_replace($this->configuration(), ['email_port' => '65536']));
    }

    public function test_server_without_auth_does_not_claim_saved_credentials_were_accepted(): void
    {
        $manager = $this->createMock(MailManager::class);
        $transport = $this->getMockBuilder(EsmtpTransport::class)->setConstructorArgs(['smtp.example.test', 587, false])
            ->onlyMethods(['start', 'stop'])->getMock();
        $transport->method('start');
        $transport->expects(self::once())->method('stop');
        $manager->method('createSymfonyTransport')->willReturn($transport);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SMTP_DIAGNOSTIC_FAILED');
        (new NativeSmtpDiagnostic($manager))->inspect($this->configuration());
    }

    private function configuration(): array
    {
        return ['email_host' => 'smtp.example.test', 'email_port' => '587', 'email_username' => 'private-user',
            'email_password' => 'private-password', 'email_encryption' => 'tls'];
    }
}
