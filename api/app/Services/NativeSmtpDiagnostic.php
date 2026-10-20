<?php

namespace App\Services;

use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\Smtp\Auth;

/** Uses the native mailer's SMTP transport, without sending a message. */
final class NativeSmtpDiagnostic
{
    public function __construct(private readonly MailManager $mailers) {}

    public function inspect(array $configuration): array
    {
        $host = $configuration['email_host'];
        $port = (string) $configuration['email_port'];
        if ($host === '' || preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) !== 1 || (int) $port > 65535) {
            throw new \DomainException('SMTP_CONFIGURATION_INVALID');
        }
        $mode = $configuration['email_encryption'];
        try { $transport = $this->mailers->createSymfonyTransport([
            'transport' => 'smtp', 'host' => $host, 'port' => (int) $port,
            'username' => $configuration['email_username'], 'password' => $configuration['email_password'],
            'scheme' => $mode === 'ssl' ? 'smtps' : 'smtp', 'auto_tls' => $mode !== '',
            'require_tls' => $mode === 'tls', 'timeout' => 10,
        ]); } catch (\Throwable) { throw new \DomainException('SMTP_DIAGNOSTIC_FAILED'); }
        if (!$transport instanceof EsmtpTransport) throw new \DomainException('SMTP_TRANSPORT_UNSUPPORTED');
        $stream = $transport->getStream();
        if (!$stream instanceof SocketStream) throw new \DomainException('SMTP_TRANSPORT_UNSUPPORTED');
        $stream->setTimeout(10);
        $authenticated = false;
        $accepted = static function () use (&$authenticated): void { $authenticated = true; };
        $transport->setAuthenticators(array_map(static fn (Auth\AuthenticatorInterface $authenticator) =>
            new class($authenticator, $accepted) implements Auth\AuthenticatorInterface {
                public function __construct(private readonly Auth\AuthenticatorInterface $delegate, private readonly \Closure $accepted) {}
                public function getAuthKeyword(): string { return $this->delegate->getAuthKeyword(); }
                public function authenticate(EsmtpTransport $client): void
                {
                    $this->delegate->authenticate($client);
                    ($this->accepted)();
                }
            }, [new Auth\CramMd5Authenticator, new Auth\LoginAuthenticator, new Auth\PlainAuthenticator, new Auth\XOAuth2Authenticator]));
        try {
            $transport->start();
            if ($configuration['email_username'] !== '' && !$authenticated) throw new \DomainException('SMTP_AUTHENTICATION_UNCONFIRMED');
            return ['connected' => true, 'authentication_used' => $authenticated];
        } catch (\Throwable) {
            throw new \DomainException('SMTP_DIAGNOSTIC_FAILED');
        } finally {
            try { $transport->stop(); } catch (\Throwable) {}
        }
    }
}
