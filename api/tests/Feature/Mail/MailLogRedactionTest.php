<?php

namespace Tests\Feature\Mail;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Services\MailService;
use App\Support\Setting;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class MailLogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '739261';
    private const SMTP_USER = 'private-smtp-username';
    private const SMTP_PASSWORD = 'private-smtp-password';
    private const RECIPIENT = 'recipient@example.test';
    private mixed $originalMailManager;
    private string|false $originalIgnoreArgs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMailManager = Mail::getFacadeRoot();
        $this->originalIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $this->assertSame('0', ini_get('zend.exception_ignore_args'));
        config(['cache.default' => 'array', 'cache.setting_store' => 'array']);
        Cache::flush();
        $this->app->forgetInstance(Setting::class);
        admin_setting(['email_host' => '']);
        Config::set('mail.host', 'smtp.example.test');
        Config::set('mail.port', 2525);
        Config::set('mail.encryption', 'tls');
        Config::set('mail.username', self::SMTP_USER);
        Config::set('mail.password', self::SMTP_PASSWORD);
    }

    protected function tearDown(): void
    {
        Mail::swap($this->originalMailManager);
        if ($this->originalIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->originalIgnoreArgs);
        }
        parent::tearDown();
    }

    public function test_mail_logs_and_response_never_include_smtp_credentials(): void
    {
        $transport = new ArrayTransport();
        $this->useTransport($transport);
        Log::shouldReceive('error')->never();

        $result = MailService::sendEmail($this->params());

        $this->assertNull($result['error']);
        $this->assertSame(['host' => 'smtp.example.test', 'port' => 2525, 'encryption' => 'tls'],
            json_decode($result['config'], true));
        $this->assertSame('mail.default.verify', $result['template_name']);
        $stored = MailLog::query()->sole();
        foreach ([self::SMTP_USER, self::SMTP_PASSWORD, self::CODE] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result));
            $this->assertStringNotContainsString($secret, json_encode($stored->toArray()));
        }
        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $this->assertSame(self::RECIPIENT, $message->getTo()[0]->getAddress());
        $this->assertSame('SMTP privacy regression', $message->getSubject());
        $this->assertStringContainsString(self::CODE, $message->getHtmlBody());
        $this->assertStringContainsString('TXBoard fixture', $message->getHtmlBody());
    }

    public function test_caught_exception_message_previous_and_arguments_only_produce_fixed_diagnostics(): void
    {
        $transport = $this->throwingTransport();
        $this->useTransport($transport);
        // Exactly one argument: neither an exception nor an empty/secret context
        // can be passed to the logger through this failure branch.
        Log::shouldReceive('error')->once()->with('MAIL_SEND_FAILED');

        $result = MailService::sendEmail($this->params());

        $this->assertSame(['email', 'subject', 'template_name', 'error', 'config'], array_keys($result));
        $this->assertSame(self::RECIPIENT, $result['email']);
        $this->assertSame('MAIL_SEND_FAILED', $result['error']);
        $this->assertSame('MAIL_SEND_FAILED', MailLog::query()->sole()->error);
        $this->assertInstanceOf(\RuntimeException::class, $transport->failure);
        $this->assertInstanceOf(\RuntimeException::class, $transport->failure->getPrevious());
        $this->assertSame(1, $transport->calls);
        $this->assertStringContainsString(self::CODE, $transport->renderedBody);
        $trace = json_encode($transport->failure->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR);
        foreach ($this->secrets() as $secret) {
            $this->assertStringContainsString($secret, $transport->failure->getMessage());
            $this->assertStringContainsString($secret, $transport->failure->getPrevious()->getMessage());
            $this->assertStringContainsString($secret, $trace);
            $this->assertStringNotContainsString($secret, $result['error']);
            $this->assertStringNotContainsString($secret, MailLog::query()->sole()->error);
        }
        // Recipient remains existing private mail metadata; only error fields
        // are redacted. The other fixtures are absent from the complete log.
        foreach ([self::CODE, self::SMTP_USER, self::SMTP_PASSWORD] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result));
            $this->assertStringNotContainsString($secret, json_encode(MailLog::query()->sole()->toArray()));
        }
    }

    public function test_db_template_success_still_renders_safe_placeholders_in_actual_message(): void
    {
        MailTemplate::create(['name' => 'verify', 'subject' => 'Verify {{code}}',
            'content' => '<p>{{name}}</p><strong>{{code}}</strong>{{optional|default value}}']);
        $transport = new ArrayTransport();
        $this->useTransport($transport);
        Log::shouldReceive('error')->never();
        $params = $this->params();
        $params['template_value']['name'] = '<fixture-name>';

        $result = MailService::sendEmail($params);

        $this->assertNull($result['error']);
        $this->assertSame('db:verify', $result['template_name']);
        $this->assertSame('db:verify', MailLog::query()->sole()->template_name);
        $this->assertNull(MailLog::query()->sole()->error);
        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->sole()->getOriginalMessage();
        $this->assertSame('Verify '.self::CODE, $message->getSubject());
        $this->assertSame('<p>&lt;fixture-name&gt;</p><strong>'.self::CODE.'</strong>default value', $message->getHtmlBody());
    }

    public function test_send_email_job_releases_existing_queue_job_for_fixed_nonempty_failure(): void
    {
        $transport = $this->throwingTransport();
        $this->useTransport($transport);
        Log::shouldReceive('error')->once()->with('MAIL_SEND_FAILED');
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('release')->once()->with(0);
        $job = new SendEmailJob($this->params());
        $job->setJob($queueJob);

        $job->handle();

        $this->assertSame('MAIL_SEND_FAILED', MailLog::query()->sole()->error);
        $this->assertSame(1, $transport->calls);
        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->timeout);
        $this->assertSame('send_email', $job->queue);
    }

    public function test_successful_send_email_job_keeps_null_error_and_does_not_release(): void
    {
        $transport = new ArrayTransport();
        $this->useTransport($transport);
        Log::shouldReceive('error')->never();
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('release');
        $job = new SendEmailJob($this->params(), 'fixture-mail-queue');
        $job->setJob($queueJob);

        $job->handle();

        $this->assertNull(MailLog::query()->sole()->error);
        $this->assertCount(1, $transport->messages());
        $this->assertSame('fixture-mail-queue', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->timeout);
    }

    public function test_non_exception_throwable_keeps_original_uncaught_scope(): void
    {
        $transport = $this->throwingTransport(throwError: true);
        $this->useTransport($transport);
        Log::shouldReceive('error')->never();

        try {
            MailService::sendEmail($this->params());
            $this->fail('A non-Exception throwable must retain the original catch boundary.');
        } catch (\Error $error) {
            $this->assertSame('fixture-uncaught-mail-error', $error->getMessage());
        }
        $this->assertSame(1, $transport->calls);
        $this->assertSame(0, MailLog::query()->count());
    }

    private function useTransport(TransportInterface $transport): void
    {
        $mailer = new Mailer('mail-diagnostics-fixture', $this->app->make('view'), $transport,
            $this->app->make('events'));
        $mailer->alwaysFrom('sender@example.test', 'Local fixture');
        Mail::swap($mailer);
        $this->assertSame($transport, Mail::getFacadeRoot()->getSymfonyTransport());
    }

    private function throwingTransport(bool $throwError = false): MailDiagnosticThrowingTransport
    {
        return new MailDiagnosticThrowingTransport($this->secrets(), $throwError);
    }

    private function secrets(): array
    {
        return [self::CODE, self::SMTP_USER, self::SMTP_PASSWORD, self::RECIPIENT];
    }

    private function params(): array
    {
        return ['email' => self::RECIPIENT, 'subject' => 'SMTP privacy regression',
            'template_name' => 'verify', 'template_value' => [
                'name' => 'TXBoard fixture', 'code' => self::CODE, 'url' => 'https://example.test']];
    }
}

// A real Laravel Mailer renders the message and invokes this local transport.
// No SMTP connection, queue worker, fake mail facade or external I/O is used.
final class MailDiagnosticThrowingTransport implements TransportInterface
{
    public ?\RuntimeException $failure = null;
    public int $calls = 0;
    public string $renderedBody = '';

    public function __construct(private array $secrets, private bool $throwError = false) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->calls++;
        $this->renderedBody = $message instanceof Email ? ($message->getHtmlBody() ?? '') : $message->toString();
        if ($this->throwError) {
            throw new \Error('fixture-uncaught-mail-error');
        }
        return $this->failWithSecretArguments(...$this->secrets);
    }

    private function failWithSecretArguments(string $code, string $username, string $password, string $recipient): never
    {
        $secretMessage = implode(' ', [$code, $username, $password, $recipient]);
        $previous = new \RuntimeException('fixture previous '.$secretMessage);
        $this->failure = new \RuntimeException('fixture SMTP failure '.$secretMessage, 0, $previous);
        throw $this->failure;
    }

    public function __toString(): string { return 'mail-diagnostics-local-fixture'; }
}
