<?php

class Swift_Webhook_Converter_AmazonSesConverterTest extends \PHPUnit\Framework\TestCase
{
    private Swift_Webhook_Converter_AmazonSesConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new Swift_Webhook_Converter_AmazonSesConverter();
    }

    public function testGetProviderName()
    {
        $this->assertSame('amazon-ses', $this->converter->getProviderName());
    }

    public function testConvertBounceNotification()
    {
        $payload = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Bounce',
                'bounce' => [
                    'bounceType' => 'Permanent',
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
            'Type' => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Delivery',
                'delivery' => [
                    'recipients' => ['user@example.com'],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
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
            'Type' => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Complaint',
                'complaint' => [
                    'complainedRecipients' => [
                        ['emailAddress' => 'user@example.com'],
                    ],
                    'timestamp' => '2026-01-15T10:30:00.000Z',
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

    public function testConvertSnsSubscriptionConfirmationIsSkipped()
    {
        $payload = [
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.amazonaws.com/confirm?...',
        ];

        $events = $this->converter->convert($payload, []);

        $this->assertCount(0, $events);
    }

    public function testVerifySkipsForSns()
    {
        // SNS signature verification requires fetching the signing cert.
        // For simplicity, verify() validates the x-amz-sns-message-type header presence.
        $headers = ['x-amz-sns-message-type' => 'Notification'];
        $this->assertTrue($this->converter->verify('{}', $headers, ''));
    }

    public function testVerifyFailsWithoutSnsHeader()
    {
        $this->assertFalse($this->converter->verify('{}', [], ''));
    }
}
