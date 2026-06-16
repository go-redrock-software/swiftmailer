<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Validates each transport's serialized request payload against the provider's
 * PUBLISHED API schema -- not against our own implementation.
 *
 * Why: the per-provider unit tests assert the request matches what our
 * getPayload() emits, so a wrong/misspelled field name is baked into both the
 * code and the test and still passes green. These tests instead pin every
 * emitted field against an allow-list taken from each provider's official
 * request reference, so an unknown, misspelled, or mis-nested field fails loud.
 *
 * The allow-lists are a DATED SNAPSHOT of the provider specs (source + fetch
 * date cited per provider). They are an independent source (the provider's own
 * documentation), but offline: refresh them when a provider changes its API.
 * They are not a substitute for a live or recorded request against the real
 * endpoint -- they catch incorrect request SHAPE, not delivery.
 *
 * Pilot scope: SendGrid, Brevo, Postmark (JSON-body APIs). Multipart providers
 * (Mailgun, InfoBip) need a separate form-field contract, not covered here.
 */
class Swift_Transport_Api_PayloadContractTest extends TestCase
{
    // SendGrid v3 -- POST /v3/mail/send
    // Source: https://www.twilio.com/docs/sendgrid/api-reference/mail-send/mail-send (fetched 2026-06-16)
    private const array SENDGRID_TOP = ['personalizations', 'from', 'reply_to', 'reply_to_list', 'subject', 'content', 'attachments', 'categories', 'custom_args', 'template_id', 'headers', 'sections', 'batch_id', 'asm', 'ip_pool_name', 'mail_settings', 'tracking_settings', 'send_at'];

    private const array SENDGRID_PERSONALIZATION = ['to', 'cc', 'bcc', 'from', 'subject', 'headers', 'substitutions', 'dynamic_template_data', 'custom_args', 'send_at'];

    private const array SENDGRID_ATTACHMENT = ['content', 'type', 'filename', 'disposition', 'content_id'];

    // Brevo -- POST /v3/smtp/email
    // Source: https://developers.brevo.com/reference/sendtransacemail (fetched 2026-06-16)
    private const array BREVO_TOP = ['sender', 'to', 'cc', 'bcc', 'replyTo', 'htmlContent', 'textContent', 'subject', 'attachment', 'headers', 'messageVersions', 'params', 'tags', 'templateId', 'scheduledAt', 'batchId'];

    private const array BREVO_ATTACHMENT = ['url', 'content', 'name'];

    // Postmark -- POST /email
    // Source: https://postmarkapp.com/developer/api/email-api (fetched 2026-06-16)
    private const array POSTMARK_TOP = ['From', 'To', 'Cc', 'Bcc', 'Subject', 'Tag', 'HtmlBody', 'TextBody', 'ReplyTo', 'Headers', 'TrackOpens', 'TrackLinks', 'Metadata', 'Attachments', 'MessageStream', 'TemplateId', 'TemplateAlias', 'TemplateModel', 'InlineCss'];

    private const array POSTMARK_ATTACHMENT = ['Name', 'Content', 'ContentType', 'ContentID'];

    public function testSendgridPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('sendgrid');

        $this->assertOnlyAllowedKeys($payload, self::SENDGRID_TOP, 'SendGrid top-level');
        $this->assertRequiredKeys($payload, ['personalizations', 'from', 'subject', 'content'], 'SendGrid');

        $this->assertOnlyAllowedKeys($payload['personalizations'][0], self::SENDGRID_PERSONALIZATION, 'SendGrid personalization');
        $this->assertArrayHasKey('email', $payload['from'], 'SendGrid from requires email');

        foreach ($payload['content'] as $part) {
            $this->assertSame(['type', 'value'], \array_keys($part), 'SendGrid content item shape');
        }
        foreach ($payload['attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::SENDGRID_ATTACHMENT, 'SendGrid attachment');
        }
    }

    public function testBrevoPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('brevo');

        $this->assertOnlyAllowedKeys($payload, self::BREVO_TOP, 'Brevo top-level');
        $this->assertRequiredKeys($payload, ['sender', 'to', 'subject'], 'Brevo');

        $this->assertArrayHasKey('email', $payload['sender'], 'Brevo sender requires email');
        foreach ($payload['to'] as $recipient) {
            $this->assertArrayHasKey('email', $recipient, 'Brevo to item requires email');
        }
        foreach ($payload['attachment'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::BREVO_ATTACHMENT, 'Brevo attachment');
        }
    }

    public function testPostmarkPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('postmark');

        $this->assertOnlyAllowedKeys($payload, self::POSTMARK_TOP, 'Postmark top-level');
        $this->assertRequiredKeys($payload, ['From', 'To', 'Subject'], 'Postmark');
        $this->assertTrue(
            isset($payload['HtmlBody']) || isset($payload['TextBody']),
            'Postmark requires HtmlBody or TextBody',
        );

        foreach ($payload['Attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::POSTMARK_ATTACHMENT, 'Postmark attachment');
        }
    }

    private function assertOnlyAllowedKeys(array $payload, array $allowed, string $context): void
    {
        $unknown = \array_values(\array_diff(\array_keys($payload), $allowed));
        $this->assertSame(
            [],
            $unknown,
            \sprintf('%s: field(s) not in the provider schema: %s', $context, \implode(', ', $unknown)),
        );
    }

    private function assertRequiredKeys(array $payload, array $required, string $context): void
    {
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $payload, \sprintf('%s: required field "%s" missing', $context, $key));
        }
    }

    /**
     * Build the transport with a payload-capturing HTTP client, send a rich
     * message, and return the decoded outgoing request body.
     */
    private function capturePayload(string $provider): array
    {
        $captured = null;

        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use (&$captured, $provider): Response {
                $captured = $options['json'] ?? \json_decode($options['body'], true);

                return $this->successResponse($provider);
            },
        );

        $this->makeTransport($provider, $client)->send($this->richMessage());

        $this->assertIsArray($captured, $provider.' produced no JSON request body');

        return $captured;
    }

    private function makeTransport(string $provider, ClientInterface $client): Swift_Transport_AbstractHttpApiTransport
    {
        return match ($provider) {
            'sendgrid' => new Swift_Transport_Api_SendgridTransport('api-key', $client),
            'brevo'    => new Swift_Transport_Api_BrevoTransport('api-key', $client),
            'postmark' => new Swift_Transport_Api_PostMarkTransport('api-key', $client),
            default    => throw new InvalidArgumentException($provider),
        };
    }

    private function successResponse(string $provider): Response
    {
        return match ($provider) {
            'sendgrid' => new Response(202),
            'brevo'    => new Response(201, [], '{"messageId":"<test>"}'),
            'postmark' => new Response(200, [], '{"ErrorCode":0,"MessageID":"id","Message":"OK"}'),
            default    => throw new InvalidArgumentException($provider),
        };
    }

    private function richMessage(): Swift_Message
    {
        $message = new Swift_Message('Subject line');
        $message->setFrom(['from@example.com' => 'From Name']);
        $message->setTo(['to@example.com' => 'To Name']);
        $message->setCc(['cc@example.com' => 'Cc Name']);
        $message->setBcc(['bcc@example.com' => 'Bcc Name']);
        $message->setReplyTo(['reply@example.com' => 'Reply Name']);
        $message->setBody('<p>HTML body</p>', 'text/html');
        $message->addPart('Plain text body', 'text/plain');
        $message->attach(new Swift_Attachment('file contents', 'document.txt', 'text/plain'));
        $message->embed(new Swift_Image('image-bytes', 'logo.png', 'image/png'));

        $headers = $message->getHeaders();
        $headers->addTextHeader('X-Mailer-Tag', 'campaign-1');
        $headers->addTextHeader('X-Mailer-Tag', 'campaign-2');
        $headers->addTextHeader('X-Mailer-Metadata-user_id', '123');
        $headers->addTextHeader('X-Mailer-Metadata-env', 'prod');

        return $message;
    }
}
