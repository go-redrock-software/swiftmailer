<?php

/**
 * Testable subclass that overrides certificate fetching for unit tests.
 */
class TestableAmazonSesConverter extends Swift_Webhook_Converter_AmazonSesConverter
{
    private string $certPem;

    public function __construct(string $certPem)
    {
        $this->certPem = $certPem;
    }

    #[Override]
    protected function fetchSigningCertificate(string $url): string
    {
        return $this->certPem;
    }
}

class Swift_Webhook_Converter_AmazonSesConverterTest extends PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_AmazonSesConverter $converter;

    private string $certPem;

    private string $privateKeyPem;

    private string $topicArn = 'arn:aws:sns:us-east-1:123456789012:ses-notifications';

    protected function setUp(): void
    {
        $config     = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $privateKey = \openssl_pkey_new($config);
        $csr        = \openssl_csr_new(['CN' => 'sns.us-east-1.amazonaws.com'], $privateKey);
        $cert       = \openssl_csr_sign($csr, null, $privateKey, 365);

        \openssl_x509_export($cert, $certPem);
        \openssl_pkey_export($privateKey, $privateKeyPem);

        $this->certPem       = $certPem;
        $this->privateKeyPem = $privateKeyPem;
        $this->converter     = new TestableAmazonSesConverter($this->certPem);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function buildSnsNotification(string $topicArn, string $privateKeyPem, string $signatureVersion = '2'): array
    {
        $message = [
            'Type'      => 'Notification',
            'MessageId' => 'test-msg-id-123',
            'TopicArn'  => $topicArn,
            'Message'   => \json_encode([
                'notificationType' => 'Delivery',
                'delivery'         => [
                    'recipients' => ['user@example.com'],
                    'timestamp'  => '2024-01-01T00:00:00.000Z',
                ],
                'mail' => ['messageId' => 'ses-msg-100'],
            ]),
            'Timestamp'        => '2024-01-01T00:00:00.000Z',
            'SignatureVersion' => $signatureVersion,
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-xxxxx.pem',
        ];

        $stringToSign = "Message\n{$message['Message']}\nMessageId\n{$message['MessageId']}\n"
            ."Timestamp\n{$message['Timestamp']}\nTopicArn\n{$message['TopicArn']}\n"
            ."Type\n{$message['Type']}\n";

        $algo = '1' === $signatureVersion ? \OPENSSL_ALGO_SHA1 : \OPENSSL_ALGO_SHA256;
        \openssl_sign($stringToSign, $signature, $privateKeyPem, $algo);
        $message['Signature'] = \base64_encode($signature);

        return $message;
    }

    private function buildSnsSubscriptionConfirmation(string $topicArn, string $privateKeyPem, string $signatureVersion = '2'): array
    {
        $message = [
            'Type'             => 'SubscriptionConfirmation',
            'MessageId'        => 'test-sub-id-456',
            'TopicArn'         => $topicArn,
            'Message'          => 'You have chosen to subscribe to the topic...',
            'SubscribeURL'     => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn:aws:sns:us-east-1:123456789012:ses-notifications&Token=abc123',
            'Timestamp'        => '2024-01-01T00:00:00.000Z',
            'Token'            => 'abc123',
            'SignatureVersion' => $signatureVersion,
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-xxxxx.pem',
        ];

        $stringToSign = "Message\n{$message['Message']}\nMessageId\n{$message['MessageId']}\n"
            ."SubscribeURL\n{$message['SubscribeURL']}\nTimestamp\n{$message['Timestamp']}\n"
            ."Token\n{$message['Token']}\nTopicArn\n{$message['TopicArn']}\n"
            ."Type\n{$message['Type']}\n";

        $algo = '1' === $signatureVersion ? \OPENSSL_ALGO_SHA1 : \OPENSSL_ALGO_SHA256;
        \openssl_sign($stringToSign, $signature, $privateKeyPem, $algo);
        $message['Signature'] = \base64_encode($signature);

        return $message;
    }

    // -----------------------------------------------------------------------
    // Verify tests
    // -----------------------------------------------------------------------

    public function testVerifyReturnsTrueWithValidSnsSignature()
    {
        $payload = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $headers = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertTrue($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyReturnsFalseWithTamperedSignature()
    {
        $payload              = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $payload['Signature'] = \base64_encode('tampered-signature-data');
        $headers              = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyReturnsFalseWithTamperedBody()
    {
        $payload            = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $payload['Message'] = '{"notificationType":"Bounce"}'; // changed after signing
        $headers            = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyReturnsFalseWithInvalidCertUrl()
    {
        $payload                   = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $payload['SigningCertURL'] = 'https://evil.example.com/cert.pem';
        $headers                   = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyReturnsFalseWithHttpCertUrl()
    {
        $payload                   = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $payload['SigningCertURL'] = 'http://sns.us-east-1.amazonaws.com/SimpleNotificationService-xxxxx.pem';
        $headers                   = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyReturnsFalseWithWrongTopicArn()
    {
        $payload = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $headers = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($this->converter->verify(\json_encode($payload), $headers, 'arn:aws:sns:us-east-1:000000000000:wrong-topic'));
    }

    public function testVerifyReturnsFalseWithMissingSnsHeader()
    {
        $payload = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);

        $this->assertFalse($this->converter->verify(\json_encode($payload), [], $this->topicArn));
    }

    public function testVerifyReturnsTrueWithSubscriptionConfirmation()
    {
        $payload = $this->buildSnsSubscriptionConfirmation($this->topicArn, $this->privateKeyPem);
        $headers = ['x-amz-sns-message-type' => 'SubscriptionConfirmation'];

        $this->assertTrue($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyWithSignatureVersion1UsesSha1()
    {
        $payload = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem, '1');
        $headers = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertTrue($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    // -----------------------------------------------------------------------
    // Convert tests
    // -----------------------------------------------------------------------

    public function testGetProviderName()
    {
        $this->assertSame('amazon-ses', $this->converter->getProviderName());
    }

    public function testConvertBounceNotification()
    {
        $payload = [
            'Type'    => 'Notification',
            'Message' => \json_encode([
                'notificationType' => 'Bounce',
                'bounce'           => [
                    'bounceType'        => 'Permanent',
                    'bouncedRecipients' => [
                        ['emailAddress' => 'user@example.com'],
                        ['emailAddress' => 'other@example.com'],
                    ],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
                ],
                'mail' => [
                    'messageId' => 'ses-msg-300',
                ],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(2, $events);
        $this->assertSame('delivery', $events[0]->getType());
        $this->assertSame('bounced', $events[0]->getName());
        $this->assertSame('ses-msg-300', $events[0]->getMessageId());
        $this->assertSame('user@example.com', $events[0]->getRecipient());
        $this->assertSame('other@example.com', $events[1]->getRecipient());
    }

    public function testConvertDeliveryNotification()
    {
        $payload = [
            'Type'    => 'Notification',
            'Message' => \json_encode([
                'notificationType' => 'Delivery',
                'delivery'         => [
                    'recipients' => ['user@example.com'],
                    'timestamp'  => '2026-01-15T10:30:00.000Z',
                ],
                'mail' => [
                    'messageId' => 'ses-msg-301',
                ],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertComplaintNotification()
    {
        $payload = [
            'Type'    => 'Notification',
            'Message' => \json_encode([
                'notificationType' => 'Complaint',
                'complaint'        => [
                    'complainedRecipients' => [
                        ['emailAddress' => 'user@example.com'],
                    ],
                    'timestamp'             => '2026-01-15T10:30:00.000Z',
                    'complaintFeedbackType' => 'abuse',
                ],
                'mail' => [
                    'messageId' => 'ses-msg-302',
                ],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('engagement', $events[0]->getType());
        $this->assertSame('complained', $events[0]->getName());
    }

    public function testConvertSubscriptionConfirmationReturnsEmpty()
    {
        $payload = [
            'Type'         => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.amazonaws.com/confirm?...',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(0, $events);
    }

    public function testExtractTimestamp()
    {
        $converter = new TestableAmazonSesConverter('');

        $rawBody = \json_encode(['Timestamp' => '2024-01-01T00:00:00.000Z']);
        $this->assertSame(\strtotime('2024-01-01T00:00:00.000Z'), $converter->extractTimestamp($rawBody, []));

        $this->assertNull($converter->extractTimestamp('{}', []));
        $this->assertNull($converter->extractTimestamp('invalid-json', []));
    }

    public function testVerifyReturnsFalseWithInvalidJson()
    {
        $headers = ['x-amz-sns-message-type' => 'Notification'];
        $this->assertFalse($this->converter->verify('not-json', $headers, $this->topicArn));
    }

    public function testVerifyReturnsFalseWithEmptyCertificate()
    {
        // Use a converter that returns empty cert
        $converter = new TestableAmazonSesConverter('');
        $payload = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $headers = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyReturnsFalseWithInvalidSignatureBase64()
    {
        $payload = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $payload['Signature'] = '!!!invalid-base64!!!';
        $headers = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testConvertNotificationWithSubject()
    {
        $payload = [
            'Type'    => 'Notification',
            'Message' => \json_encode([
                'notificationType' => 'Delivery',
                'delivery'         => [
                    'recipients' => ['user@example.com'],
                    'timestamp'  => '2026-01-15T10:30:00.000Z',
                ],
                'mail' => ['messageId' => 'ses-subj-100'],
            ]),
            'Subject' => 'Test Subject',
        ];

        // This exercises the Subject branch in buildStringToSign
        $events = $this->converter->convert($payload, []);
        $this->assertCount(1, $events);
        $this->assertSame('delivered', $events[0]->getName());
    }

    public function testConvertUnsubscribeConfirmationReturnsEmpty()
    {
        $payload = [
            'Type'         => 'UnsubscribeConfirmation',
            'SubscribeURL' => 'https://sns.amazonaws.com/unsubscribe?...',
        ];

        $this->assertCount(0, $this->converter->convert($payload, []));
    }

    public function testConvertUnknownNotificationTypeReturnsEmpty()
    {
        $payload = [
            'Type'    => 'Notification',
            'Message' => \json_encode([
                'notificationType' => 'UnknownType',
                'mail' => ['messageId' => 'ses-unknown'],
            ]),
        ];

        $this->assertCount(0, $this->converter->convert($payload, []));
    }

    public function testConvertTransientBounceAsDeferredEvent()
    {
        $payload = [
            'Type'    => 'Notification',
            'Message' => \json_encode([
                'notificationType' => 'Bounce',
                'bounce'           => [
                    'bounceType'        => 'Transient',
                    'bouncedRecipients' => [
                        ['emailAddress' => 'user@example.com', 'diagnosticCode' => '450 Try again later'],
                    ],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
                ],
                'mail' => ['messageId' => 'ses-transient'],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('deferred', $events[0]->getName());
        $this->assertSame('Transient', $events[0]->getMetadata()['bounce_type']);
        $this->assertSame('450 Try again later', $events[0]->getMetadata()['reason']);
    }

    public function testConvertComplaintWithoutFeedbackType()
    {
        $payload = [
            'Type'    => 'Notification',
            'Message' => \json_encode([
                'notificationType' => 'Complaint',
                'complaint'        => [
                    'complainedRecipients' => [
                        ['emailAddress' => 'user@example.com'],
                    ],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
                ],
                'mail' => ['messageId' => 'ses-no-feedback'],
            ]),
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(1, $events);
        $this->assertSame('complained', $events[0]->getName());
        $this->assertArrayNotHasKey('feedback_type', $events[0]->getMetadata());
    }

    public function testVerifyReturnsFalseWithInvalidCertPem()
    {
        // Covers line 81: openssl_pkey_get_public returns false for garbage cert
        $converter = new TestableAmazonSesConverter('not-a-valid-certificate-pem');
        $payload   = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);
        $headers   = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertFalse($converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }

    public function testVerifyWithNotificationContainingSubject()
    {
        // Covers line 168: Subject branch in buildStringToSign
        $payload = $this->buildSnsNotification($this->topicArn, $this->privateKeyPem);

        // Re-sign with Subject included in the string-to-sign
        $payload['Subject'] = 'Test Subject Line';

        $stringToSign = "Message\n{$payload['Message']}\nMessageId\n{$payload['MessageId']}\n"
            ."Subject\n{$payload['Subject']}\n"
            ."Timestamp\n{$payload['Timestamp']}\nTopicArn\n{$payload['TopicArn']}\n"
            ."Type\n{$payload['Type']}\n";

        $algo = ('1' === ($payload['SignatureVersion'] ?? '1'))
            ? \OPENSSL_ALGO_SHA1
            : \OPENSSL_ALGO_SHA256;
        \openssl_sign($stringToSign, $signature, $this->privateKeyPem, $algo);
        $payload['Signature'] = \base64_encode($signature);

        $headers = ['x-amz-sns-message-type' => 'Notification'];

        $this->assertTrue($this->converter->verify(\json_encode($payload), $headers, $this->topicArn));
    }
}
