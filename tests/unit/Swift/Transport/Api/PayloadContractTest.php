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

    // Resend -- POST /emails
    // Source: https://resend.com/docs/api-reference/emails/send-email (fetched 2026-06-16)
    private const array RESEND_TOP = ['from', 'to', 'cc', 'bcc', 'reply_to', 'subject', 'html', 'text', 'headers', 'attachments', 'tags', 'scheduled_at'];

    private const array RESEND_ATTACHMENT = ['filename', 'content', 'path', 'contentType', 'contentId'];

    // MailerSend -- POST /v1/email
    // Source: https://developers.mailersend.com/api/v1/email.html (fetched 2026-06-16)
    private const array MAILERSEND_TOP = ['from', 'to', 'cc', 'bcc', 'reply_to', 'subject', 'text', 'html', 'attachments', 'tags', 'personalization', 'template_id', 'headers'];

    private const array MAILERSEND_ATTACHMENT = ['content', 'disposition', 'filename', 'id'];

    // MailPace -- POST /api/v1/send
    // Source: https://docs.mailpace.com/reference/send (fetched 2026-06-16)
    private const array MAILPACE_TOP = ['from', 'to', 'htmlbody', 'textbody', 'cc', 'bcc', 'subject', 'replyto', 'inreplyto', 'references', 'list_unsubscribe', 'attachments', 'tags'];

    private const array MAILPACE_ATTACHMENT = ['name', 'content', 'content_type', 'cid'];

    // Scaleway TEM -- POST /transactional-email/v1alpha1/regions/{region}/emails
    // Source: https://www.scaleway.com/en/developers/api/transactional-email/ (fetched 2026-06-16)
    private const array SCALEWAY_TOP = ['from', 'to', 'cc', 'bcc', 'subject', 'text', 'html', 'project_id', 'attachments', 'additional_headers', 'send_before'];

    private const array SCALEWAY_ATTACHMENT = ['name', 'type', 'content'];

    // Postal -- POST /api/v1/send/message
    // Source: https://docs.postalserver.io/developer/api (fetched 2026-06-16)
    private const array POSTAL_TOP = ['to', 'cc', 'bcc', 'from', 'sender', 'subject', 'tag', 'reply_to', 'plain_body', 'html_body', 'attachments', 'headers', 'bounce'];

    private const array POSTAL_ATTACHMENT = ['name', 'content_type', 'data'];

    // Mailtrap -- POST /api/send
    // Source: https://mailtrap.io/blog/api-send-email/ (fetched 2026-06-16)
    private const array MAILTRAP_TOP = ['from', 'to', 'cc', 'bcc', 'subject', 'text', 'html', 'category', 'custom_variables', 'attachments', 'headers', 'reply_to'];

    private const array MAILTRAP_ATTACHMENT = ['content', 'filename', 'type', 'disposition', 'content_id'];

    // AhaSend v1 -- POST /v1/email/send (subject/bodies/attachments nest under content)
    // Source: https://ahasend.com/docs/api-reference/v1 (fetched 2026-06-16)
    private const array AHASEND_TOP = ['from', 'recipients', 'content'];

    private const array AHASEND_CONTENT = ['subject', 'text_body', 'html_body', 'attachments', 'headers'];

    private const array AHASEND_ATTACHMENT = ['data', 'content_type', 'file_name', 'base64', 'content_id'];

    // Mailjet -- POST /v3.1/send (per-message objects inside Messages[])
    // Source: https://dev.mailjet.com/email/reference/send-emails/ (fetched 2026-06-16)
    private const array MAILJET_MESSAGE = ['From', 'To', 'Cc', 'Bcc', 'ReplyTo', 'Subject', 'TextPart', 'HTMLPart', 'Attachments', 'InlinedAttachments', 'Headers', 'CustomCampaign', 'CustomID', 'EventPayload', 'TemplateID', 'TemplateLanguage', 'Variables', 'MonitoringCategory', 'DeduplicateCampaign', 'TrackOpens', 'TrackClicks', 'Priority', 'URLTags'];

    private const array MAILJET_ATTACHMENT = ['ContentType', 'Filename', 'Base64Content', 'ContentID'];

    // Mandrill (Mailchimp Transactional) -- POST /messages/send
    // Source: https://mailchimp.com/developer/transactional/api/messages/send-new-message/ (fetched 2026-06-16)
    private const array MAILCHIMP_TOP = ['key', 'message', 'async', 'ip_pool', 'send_at'];

    private const array MAILCHIMP_MESSAGE = ['html', 'text', 'subject', 'from_email', 'from_name', 'to', 'headers', 'important', 'track_opens', 'track_clicks', 'auto_text', 'auto_html', 'inline_css', 'attachments', 'images', 'tags', 'metadata', 'merge_vars', 'subaccount'];

    private const array MAILCHIMP_ATTACHMENT = ['type', 'name', 'content'];

    // Azure Communication Services -- POST /emails:send
    // Source: https://learn.microsoft.com/en-us/rest/api/communication/email/email/send (fetched 2026-06-16)
    private const array AZURE_TOP = ['headers', 'senderAddress', 'content', 'recipients', 'attachments', 'replyTo', 'userEngagementTrackingDisabled'];

    private const array AZURE_CONTENT = ['subject', 'plainText', 'html'];

    private const array AZURE_RECIPIENTS = ['to', 'cc', 'bcc'];

    private const array AZURE_ATTACHMENT = ['name', 'contentType', 'contentInBase64', 'contentId'];

    // Mailomat -- POST /message
    // Source: https://api.mailomat.swiss/docs (fetched 2026-06-16)
    private const array MAILOMAT_TOP = ['from', 'to', 'cc', 'bcc', 'replyTo', 'subject', 'text', 'html', 'attachments'];

    private const array MAILOMAT_ATTACHMENT = ['filename', 'contentBase64', 'contentType', 'contentId'];

    // Sweego -- POST /send
    // Source: https://www.sweego.io/channel/email/integrate-sweegos-api-to-send-transactional-emails (fetched 2026-06-16)
    private const array SWEEGO_TOP = ['channel', 'provider', 'campaign-type', 'recipients', 'from', 'subject', 'message-txt', 'message-html', 'attachments', 'headers'];

    private const array SWEEGO_ATTACHMENT = ['content', 'filename', 'disposition', 'content_id'];

    // Infobip -- POST /email/3/send (multipart/form-data; field names)
    // Source: https://www.infobip.com/docs/api/channels/email/send-email-v3 (fetched 2026-06-16)
    private const array INFOBIP_FIELDS = ['from', 'to', 'cc', 'bcc', 'subject', 'text', 'html', 'replyTo', 'attachment', 'inlineImage', 'messageId', 'templateId', 'intermediateReport', 'notifyUrl', 'track'];

    // Mailgun -- POST /v3/{domain}/messages (multipart/form-data; field names)
    // Source: https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/messages (fetched 2026-06-16)
    // Plus prefixed fields: h:* (headers), v:* (variables), o:* (options).
    private const array MAILGUN_FIELDS = ['from', 'to', 'cc', 'bcc', 'subject', 'text', 'html', 'attachment', 'inline', 'template'];

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

    public function testResendPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('resend');

        $this->assertOnlyAllowedKeys($payload, self::RESEND_TOP, 'Resend top-level');
        $this->assertRequiredKeys($payload, ['from', 'to', 'subject'], 'Resend');

        foreach ($payload['attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::RESEND_ATTACHMENT, 'Resend attachment');
        }
        foreach ($payload['tags'] as $tag) {
            $this->assertOnlyAllowedKeys($tag, ['name', 'value'], 'Resend tag');
        }
    }

    public function testMailerSendPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('mailersend');

        $this->assertOnlyAllowedKeys($payload, self::MAILERSEND_TOP, 'MailerSend top-level');
        $this->assertRequiredKeys($payload, ['from', 'to', 'subject'], 'MailerSend');

        $this->assertArrayHasKey('email', $payload['from'], 'MailerSend from requires email');
        foreach ($payload['attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::MAILERSEND_ATTACHMENT, 'MailerSend attachment');
        }
    }

    public function testMailPacePayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('mailpace');

        $this->assertOnlyAllowedKeys($payload, self::MAILPACE_TOP, 'MailPace top-level');
        $this->assertRequiredKeys($payload, ['from', 'to'], 'MailPace');
        $this->assertTrue(
            isset($payload['htmlbody']) || isset($payload['textbody']),
            'MailPace requires htmlbody or textbody',
        );

        foreach ($payload['attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::MAILPACE_ATTACHMENT, 'MailPace attachment');
        }
    }

    public function testScalewayPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('scaleway');

        $this->assertOnlyAllowedKeys($payload, self::SCALEWAY_TOP, 'Scaleway top-level');
        $this->assertRequiredKeys($payload, ['from', 'to', 'subject', 'project_id'], 'Scaleway');

        foreach ($payload['attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::SCALEWAY_ATTACHMENT, 'Scaleway attachment');
        }
        foreach ($payload['additional_headers'] as $header) {
            $this->assertOnlyAllowedKeys($header, ['key', 'value'], 'Scaleway additional_headers');
        }
    }

    public function testPostalPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('postal');

        $this->assertOnlyAllowedKeys($payload, self::POSTAL_TOP, 'Postal top-level');
        $this->assertRequiredKeys($payload, ['from', 'to', 'subject'], 'Postal');

        foreach ($payload['attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::POSTAL_ATTACHMENT, 'Postal attachment');
        }
    }

    public function testMailtrapPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('mailtrap');

        $this->assertOnlyAllowedKeys($payload, self::MAILTRAP_TOP, 'Mailtrap top-level');
        $this->assertRequiredKeys($payload, ['from', 'to', 'subject'], 'Mailtrap');

        foreach ($payload['attachments'] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::MAILTRAP_ATTACHMENT, 'Mailtrap attachment');
        }
    }

    public function testAhaSendPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('ahasend');

        $this->assertOnlyAllowedKeys($payload, self::AHASEND_TOP, 'AhaSend top-level');
        $this->assertRequiredKeys($payload, ['from', 'recipients', 'content'], 'AhaSend');

        $this->assertOnlyAllowedKeys($payload['content'], self::AHASEND_CONTENT, 'AhaSend content');
        $this->assertArrayHasKey('subject', $payload['content'], 'AhaSend requires content.subject');

        foreach ($payload['content']['attachments'] ?? [] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::AHASEND_ATTACHMENT, 'AhaSend attachment');
        }
    }

    public function testMailJetPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('mailjet');

        $this->assertOnlyAllowedKeys($payload, ['Messages'], 'MailJet top-level');
        $this->assertArrayHasKey('Messages', $payload);

        $message = $payload['Messages'][0];
        $this->assertOnlyAllowedKeys($message, self::MAILJET_MESSAGE, 'MailJet message');
        $this->assertRequiredKeys($message, ['From', 'To', 'Subject'], 'MailJet message');

        foreach ($message['Attachments'] ?? [] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::MAILJET_ATTACHMENT, 'MailJet attachment');
        }
    }

    public function testMailChimpPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('mailchimp');

        $this->assertOnlyAllowedKeys($payload, self::MAILCHIMP_TOP, 'MailChimp top-level');
        $this->assertArrayHasKey('message', $payload);

        $message = $payload['message'];
        $this->assertOnlyAllowedKeys($message, self::MAILCHIMP_MESSAGE, 'MailChimp message');
        $this->assertRequiredKeys($message, ['from_email', 'subject', 'to'], 'MailChimp message');

        foreach ($message['attachments'] ?? [] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::MAILCHIMP_ATTACHMENT, 'MailChimp attachment');
        }
        foreach ($message['images'] ?? [] as $image) {
            $this->assertOnlyAllowedKeys($image, self::MAILCHIMP_ATTACHMENT, 'MailChimp image');
        }
    }

    public function testAzurePayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('azure');

        $this->assertOnlyAllowedKeys($payload, self::AZURE_TOP, 'Azure top-level');
        $this->assertRequiredKeys($payload, ['senderAddress', 'content', 'recipients'], 'Azure');

        $this->assertOnlyAllowedKeys($payload['content'], self::AZURE_CONTENT, 'Azure content');
        $this->assertArrayHasKey('subject', $payload['content'], 'Azure requires content.subject');
        $this->assertOnlyAllowedKeys($payload['recipients'], self::AZURE_RECIPIENTS, 'Azure recipients');

        foreach ($payload['attachments'] ?? [] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::AZURE_ATTACHMENT, 'Azure attachment');
        }
    }

    public function testMailomatPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('mailomat');

        $this->assertOnlyAllowedKeys($payload, self::MAILOMAT_TOP, 'Mailomat top-level');
        $this->assertRequiredKeys($payload, ['from', 'subject'], 'Mailomat');

        foreach ($payload['attachments'] ?? [] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::MAILOMAT_ATTACHMENT, 'Mailomat attachment');
        }
    }

    public function testSweegoPayloadConformsToPublishedSchema(): void
    {
        $payload = $this->capturePayload('sweego');

        $this->assertOnlyAllowedKeys($payload, self::SWEEGO_TOP, 'Sweego top-level');
        $this->assertRequiredKeys($payload, ['channel', 'recipients', 'from', 'subject'], 'Sweego');
        // Documented campaign-type value (the code previously sent the undocumented 'transac').
        $this->assertSame('transactional', $payload['campaign-type'], 'Sweego campaign-type must match the documented value');

        foreach ($payload['attachments'] ?? [] as $attachment) {
            $this->assertOnlyAllowedKeys($attachment, self::SWEEGO_ATTACHMENT, 'Sweego attachment');
        }
        foreach ($payload['recipients'] as $recipient) {
            $this->assertOnlyAllowedKeys($recipient, ['email', 'name'], 'Sweego recipient');
        }
    }

    public function testInfoBipFormFieldsConformToPublishedSchema(): void
    {
        foreach ($this->captureMultipartFields('infobip') as $field) {
            $this->assertContains(
                $field,
                self::INFOBIP_FIELDS,
                \sprintf('Infobip multipart field "%s" is not in the provider schema.', $field),
            );
        }
    }

    public function testMailGunFormFieldsConformToPublishedSchema(): void
    {
        foreach ($this->captureMultipartFields('mailgun') as $field) {
            $valid = \in_array($field, self::MAILGUN_FIELDS, true)
                || \str_starts_with($field, 'h:')   // custom headers
                || \str_starts_with($field, 'v:')   // custom variables
                || \str_starts_with($field, 'o:');  // options (e.g. o:tag)

            $this->assertTrue($valid, \sprintf('Mailgun multipart field "%s" is not in the provider schema.', $field));
        }
    }

    /**
     * Amazon SES uses the async-aws SDK, not a Guzzle client, so validate the
     * request our code builds by round-tripping it through async-aws's real
     * SESv2 input model -- the model the AWS API is generated from. A v1-shaped
     * key (Source/Message/Tags) would be silently dropped, leaving these getters
     * empty (Content is required).
     */
    public function testAmazonSesHttpRequestConformsToAsyncAwsSesV2(): void
    {
        $client = new class {
            public mixed $request = null;

            public function sendEmail($request): object
            {
                $this->request = $request;

                return new class {
                    public function getMessageId(): string
                    {
                        return 'ses-id';
                    }
                };
            }

            public function listIdentities(): array
            {
                return [];
            }
        };

        (new Swift_Transport_Api_AmazonSesHttpTransport($client))->send($this->richMessage());

        $this->assertIsArray($client->request, 'SES http produced no request');

        $request = AsyncAws\Ses\Input\SendEmailRequest::create($client->request);

        $this->assertSame('from@example.com', $request->getFromEmailAddress(), 'SES FromEmailAddress (not v1 Source) must be populated');
        $this->assertNotNull($request->getContent(), 'SES Content (required, not v1 Message) must be populated');
        $this->assertNotNull($request->getContent()->getSimple(), 'SES Content.Simple must be populated');
        $this->assertSame('Subject line', $request->getContent()->getSimple()->getSubject()->getData());
        $this->assertSame(['to@example.com'], $request->getDestination()->getToAddresses());
        $this->assertSame(['reply@example.com'], $request->getReplyToAddresses());
        $this->assertNotEmpty($request->getEmailTags(), 'SES EmailTags (not v1 Tags) must be populated');
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

    /**
     * Build the transport with a capturing client, send a rich message, and
     * return the list of multipart/form-data field names it emitted.
     *
     * @return string[]
     */
    private function captureMultipartFields(string $provider): array
    {
        $captured = [];

        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use (&$captured, $provider): Response {
                $captured = \array_map(static fn (array $part): string => $part['name'], $options['multipart']);

                return $this->successResponse($provider);
            },
        );

        $this->makeTransport($provider, $client)->send($this->richMessage());

        $this->assertNotEmpty($captured, $provider.' produced no multipart fields');

        return $captured;
    }

    private function makeTransport(string $provider, ClientInterface $client): Swift_Transport_AbstractHttpApiTransport
    {
        return match ($provider) {
            'sendgrid'   => new Swift_Transport_Api_SendgridTransport('api-key', $client),
            'brevo'      => new Swift_Transport_Api_BrevoTransport('api-key', $client),
            'postmark'   => new Swift_Transport_Api_PostMarkTransport('api-key', $client),
            'resend'     => new Swift_Transport_Api_ResendTransport('api-key', $client),
            'mailersend' => new Swift_Transport_Api_MailerSendTransport('api-key', $client),
            'mailpace'   => new Swift_Transport_Api_MailPaceTransport('api-key', $client),
            'scaleway'   => new Swift_Transport_Api_ScalewayTransport('api-key', 'project-id', 'fr-par', $client),
            'postal'     => new Swift_Transport_Api_PostalTransport('api-key', 'postal.example.com', $client),
            'mailtrap'   => new Swift_Transport_Api_MailtrapTransport('api-key', false, null, $client),
            'ahasend'    => new Swift_Transport_Api_AhaSendTransport('api-key', $client),
            'mailjet'    => new Swift_Transport_Api_MailJetTransport('public-key', 'private-key', $client),
            'mailchimp'  => new Swift_Transport_Api_MailChimpTransport('api-key', $client),
            'azure'      => new Swift_Transport_Api_AzureTransport('endpoint=https://test.communication.azure.com/;accesskey='.\base64_encode('secret'), $client),
            'mailomat'   => new Swift_Transport_Api_MailomatTransport('api-key', $client),
            'sweego'     => new Swift_Transport_Api_SweegoTransport('api-key', $client),
            'infobip'    => new Swift_Transport_Api_InfoBipTransport('api-key', 'xyz.api.infobip.com', $client),
            'mailgun'    => new Swift_Transport_Api_MailGunTransport('api-key', 'mail.example.com', 'https://api.mailgun.net', $client),
            default      => throw new InvalidArgumentException($provider),
        };
    }

    private function successResponse(string $provider): Response
    {
        return match ($provider) {
            'sendgrid'   => new Response(202),
            'brevo'      => new Response(201, [], '{"messageId":"<test>"}'),
            'postmark'   => new Response(200, [], '{"ErrorCode":0,"MessageID":"id","Message":"OK"}'),
            'resend'     => new Response(200, [], '{"id":"resend-id"}'),
            'mailersend' => new Response(202),
            'mailpace'   => new Response(200, [], '{"id":"mp-id","status":"queued"}'),
            'scaleway'   => new Response(200, [], '{"emails":[{"message_id":"sc-id"}]}'),
            'postal'     => new Response(200, [], '{"status":"success","data":{"message_id":"po-id"}}'),
            'mailtrap'   => new Response(200, [], '{"success":true,"message_ids":["mt-id"]}'),
            'ahasend'    => new Response(200, [], '{"object":"list","data":[{"object":"message","id":"aha-id","status":"queued"}]}'),
            'mailjet'    => new Response(200, [], '{"Messages":[{"Status":"success"}]}'),
            'mailchimp'  => new Response(200, [], '[{"email":"to@example.com","status":"queued","_id":"abc123"}]'),
            'azure'      => new Response(202, [], '{"id":"op-id","status":"NotStarted"}'),
            'mailomat'   => new Response(200, [], '{"id":"mo-id","status":"queued"}'),
            'sweego'     => new Response(200, [], '{"transaction_id":"sw-id"}'),
            'infobip'    => new Response(200, [], '{"messages":[{"messageId":"ib-id","status":{"groupName":"PENDING"}}]}'),
            'mailgun'    => new Response(200, [], '{"id":"mg-id","message":"Queued. Thank you."}'),
            default      => throw new InvalidArgumentException($provider),
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
