<?php

namespace Shaffe\MailLogChannel\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Monolog\Formatters\HtmlFormatter;
use Shaffe\MailLogChannel\Monolog\Processors\ContextProcessor;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RequestPayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Bind an HTTP (non-console) application with the given request.
     */
    protected function bindHttpApp(Request $request): void
    {
        $app = \Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('runningInConsole')->andReturn(false);
        $app->shouldReceive('version')->andReturn('11.0.0');

        $config = new ConfigRepository([
            'app' => ['env' => 'testing'],
        ]);

        $app->instance('config', $config);
        $app->instance('app', $app);
        $app->instance('request', $request);
        $app->singleton(\Illuminate\Contracts\Config\Repository::class, fn () => $config);

        Container::setInstance($app);
    }

    protected function makeRecord(array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'mailable',
            level: Level::Error,
            message: 'Test message',
            context: $context,
        );
    }

    // ── Opt-in behavior ──────────────────────────────────────

    public function test_payload_is_null_when_disabled_by_default(): void
    {
        $this->bindHttpApp(Request::create('/checkout', 'POST', ['email' => 'a@b.com']));

        $processor = new ContextProcessor();
        $result = $processor($this->makeRecord());

        $this->assertNull($result->extra['request_payload']);
    }

    public function test_payload_is_collected_when_enabled(): void
    {
        $this->bindHttpApp(Request::create('/checkout', 'POST', [
            'email' => 'customer@example.com',
            'quantity' => '2',
        ]));

        $processor = new ContextProcessor(null, true);
        $result = $processor($this->makeRecord());

        $this->assertNotNull($result->extra['request_payload']);
        $this->assertEquals('customer@example.com', $result->extra['request_payload']['data']['email']);
        $this->assertEquals('2', $result->extra['request_payload']['data']['quantity']);
    }

    public function test_payload_is_null_on_console(): void
    {
        $app = \Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('runningInConsole')->andReturn(true);
        $config = new ConfigRepository(['app' => ['env' => 'testing']]);
        $app->instance('config', $config);
        $app->instance('app', $app);
        $app->instance('request', Request::create('/x', 'POST', ['a' => 'b']));
        $app->singleton(\Illuminate\Contracts\Config\Repository::class, fn () => $config);
        Container::setInstance($app);

        $processor = new ContextProcessor(null, true);
        $result = $processor($this->makeRecord());

        $this->assertNull($result->extra['request_payload']);
    }

    // ── Redaction ────────────────────────────────────────────

    public function test_default_payment_fields_are_redacted(): void
    {
        $this->bindHttpApp(Request::create('/pay', 'POST', [
            'amount' => '5000',
            'card_number' => '4242424242424242',
            'cvc' => '123',
            'exp_month' => '12',
            'exp_year' => '2030',
            'stripe_token' => 'tok_visa',
        ]));

        $processor = new ContextProcessor(null, true);
        $data = $processor($this->makeRecord())->extra['request_payload']['data'];

        $this->assertEquals('5000', $data['amount']);
        $this->assertEquals('[REDACTED]', $data['card_number']);
        $this->assertEquals('[REDACTED]', $data['cvc']);
        $this->assertEquals('[REDACTED]', $data['exp_month']);
        $this->assertEquals('[REDACTED]', $data['exp_year']);
        $this->assertEquals('[REDACTED]', $data['stripe_token']);
    }

    public function test_password_is_redacted(): void
    {
        $this->bindHttpApp(Request::create('/login', 'POST', [
            'email' => 'a@b.com',
            'password' => 'super-secret',
        ]));

        $processor = new ContextProcessor(null, true);
        $data = $processor($this->makeRecord())->extra['request_payload']['data'];

        $this->assertEquals('a@b.com', $data['email']);
        $this->assertEquals('[REDACTED]', $data['password']);
    }

    public function test_custom_redact_keys_are_additive(): void
    {
        $this->bindHttpApp(Request::create('/x', 'POST', [
            'ssn' => '123-45-6789',
            'password' => 'secret',
            'keep' => 'visible',
        ]));

        // Custom key 'ssn' should be redacted AND the default 'password' too.
        $processor = new ContextProcessor(null, true, ['ssn']);
        $data = $processor($this->makeRecord())->extra['request_payload']['data'];

        $this->assertEquals('[REDACTED]', $data['ssn']);
        $this->assertEquals('[REDACTED]', $data['password']);
        $this->assertEquals('visible', $data['keep']);
    }

    public function test_nested_sensitive_keys_are_redacted(): void
    {
        $this->bindHttpApp(Request::create('/x', 'POST', [
            'user' => [
                'name' => 'Alice',
                'password' => 'secret',
                'payment' => [
                    'cvv' => '999',
                    'last4' => '4242',
                ],
            ],
        ]));

        $processor = new ContextProcessor(null, true);
        $data = $processor($this->makeRecord())->extra['request_payload']['data'];

        $this->assertEquals('Alice', $data['user']['name']);
        $this->assertEquals('[REDACTED]', $data['user']['password']);
        $this->assertEquals('[REDACTED]', $data['user']['payment']['cvv']);
        $this->assertEquals('4242', $data['user']['payment']['last4']);
    }

    // ── Truncation & bounding ────────────────────────────────

    public function test_long_values_are_truncated_with_size_annotation(): void
    {
        $long = str_repeat('x', 1200);
        $this->bindHttpApp(Request::create('/x', 'POST', ['blob' => $long]));

        $processor = new ContextProcessor(null, true, [], 100);
        $data = $processor($this->makeRecord())->extra['request_payload']['data'];

        $this->assertStringContainsString('1200 chars total', $data['blob']);
        $this->assertLessThan(strlen($long), strlen($data['blob']));
    }

    public function test_number_of_keys_is_bounded(): void
    {
        $fields = [];
        for ($i = 0; $i < 30; $i++) {
            $fields['field_'.$i] = 'value';
        }
        $this->bindHttpApp(Request::create('/x', 'POST', $fields));

        $processor = new ContextProcessor(null, true, [], 500, 10);
        $payload = $processor($this->makeRecord())->extra['request_payload'];

        $this->assertCount(10, $payload['data']);
        $this->assertEquals(20, $payload['truncated_keys']);
    }

    public function test_redacted_keys_do_not_consume_max_keys_budget(): void
    {
        $fields = ['password' => 'secret', 'token' => 'abc'];
        for ($i = 0; $i < 5; $i++) {
            $fields['field_'.$i] = 'value';
        }
        $this->bindHttpApp(Request::create('/x', 'POST', $fields));

        // maxKeys = 5 — only non-redacted keys count toward the limit.
        // We have 5 regular fields + 2 redacted. All 7 should appear.
        $processor = new ContextProcessor(null, true, [], 500, 5);
        $payload = $processor($this->makeRecord())->extra['request_payload'];

        // 5 regular + 2 redacted = 7 total, no truncation
        $this->assertCount(7, $payload['data']);
        $this->assertEquals('[REDACTED]', $payload['data']['password']);
        $this->assertEquals('[REDACTED]', $payload['data']['token']);
        $this->assertArrayNotHasKey('truncated_keys', $payload);
    }

    public function test_nested_redacted_keys_do_not_consume_budget(): void
    {
        $fields = [
            'form' => array_merge(
                ['password' => 'x', 'cvv' => '123'],
                array_combine(
                    array_map(fn ($i) => 'f_'.$i, range(0, 4)),
                    array_fill(0, 5, 'val')
                )
            ),
        ];
        $this->bindHttpApp(Request::create('/x', 'POST', $fields));

        // maxKeys = 5: inside 'form', the 2 redacted + 5 regular.
        // Only 5 regular should count, no truncation.
        $processor = new ContextProcessor(null, true, [], 500, 5);
        $payload = $processor($this->makeRecord())->extra['request_payload'];

        $form = $payload['data']['form'];
        // 5 regular fields + 2 redacted = 7 keys total
        $this->assertCount(7, $form);
        $this->assertEquals('[REDACTED]', $form['password']);
        $this->assertEquals('[REDACTED]', $form['cvv']);
        $this->assertArrayNotHasKey('truncated_keys', $payload);
    }

    // ── Uploaded files ───────────────────────────────────────

    public function test_uploaded_files_are_described_not_dumped(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, 'dummy content');

        $file = new UploadedFile($tmp, 'invoice.pdf', 'application/pdf', null, true);

        $request = Request::create('/upload', 'POST', ['note' => 'hello'], [], ['document' => $file]);
        $this->bindHttpApp($request);

        $processor = new ContextProcessor(null, true);
        $payload = $processor($this->makeRecord())->extra['request_payload'];

        $this->assertNotEmpty($payload['files']);
        $this->assertEquals('invoice.pdf', $payload['files'][0]['name']);
        $this->assertEquals('application/pdf', $payload['files'][0]['type']);
        $this->assertIsInt($payload['files'][0]['size']);

        @unlink($tmp);
    }

    // ── HTML rendering ───────────────────────────────────────

    public function test_formatter_renders_payload_section(): void
    {
        $formatter = new HtmlFormatter('/tmp/test');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'mailable',
            level: Level::Error,
            message: 'Test',
            context: [],
            extra: [
                'request_payload' => [
                    'data' => ['email' => 'a@b.com', 'quantity' => '2'],
                    'files' => [
                        ['name' => 'invoice.pdf', 'type' => 'application/pdf', 'size' => 2048],
                    ],
                ],
            ],
        );

        $html = $formatter->format($record);

        $this->assertStringContainsString('Request Payload', $html);
        $this->assertStringContainsString('a@b.com', $html);
        $this->assertStringContainsString('Uploaded Files', $html);
        $this->assertStringContainsString('invoice.pdf', $html);
    }

    public function test_formatter_omits_payload_section_when_absent(): void
    {
        $formatter = new HtmlFormatter('/tmp/test');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'mailable',
            level: Level::Error,
            message: 'Test',
            context: [],
            extra: [],
        );

        $html = $formatter->format($record);

        $this->assertStringNotContainsString('Request Payload', $html);
    }
}
