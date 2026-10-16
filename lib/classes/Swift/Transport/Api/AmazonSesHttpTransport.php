<?php

/**
 * Amazon SES v2 API transport.
 *
 * Sends email through the Amazon SES v2 SendEmail API using an injected SES
 * client and a plain v2-schema array request (FromEmailAddress / Content /
 * EmailTags). The returned SES message id is added to the message as an
 * X-SES-Message-ID header.
 */
class Swift_Transport_Api_AmazonSesHttpTransport extends Swift_Transport_AbstractApiTransport
{
    private $sesClient;

    public function __construct($sesClient, ?Swift_Events_EventDispatcher $eventDispatcher = null)
    {
        $this->sesClient       = $sesClient;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function ping(): bool
    {
        // Older supported SES packages expose only sendEmail, with no safe health operation.
        if (!$this->sesClient instanceof AsyncAws\Ses\SesClient || !(new ReflectionObject($this->sesClient))->hasMethod('getSuppressedDestination')) {
            return false;
        }

        try {
            // Resolve the real SES v2 operation; listIdentities belongs to SES v1.
            Swift_Transport_Api_SesRequestPolicy::run($this->sesClient, static fn (AsyncAws\Ses\SesClient $client) => $client->getSuppressedDestination(['EmailAddress' => 'ping@swiftmailer.invalid'])->resolve());

            return true;
        } catch (AsyncAws\Ses\Exception\NotFoundException $e) {
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        return $this->sendWithEvents($message, $failedRecipients, $envelope, fn (?Swift_Envelope $effective): int => $this->sendMessage($message, $effective));
    }

    private function sendMessage(Swift_Mime_SimpleMessage $message, ?Swift_Envelope $envelope): int
    {
        try {
            $tags    = $this->extractSesTagsFromMessage($message);
            $request = $this->getRequest($message, $tags);
            if (null !== $envelope) {
                $request['Destination']                    = ['ToAddresses' => \array_values(\array_unique($envelope->getRecipients()))];
                $request['FeedbackForwardingEmailAddress'] = $envelope->getSender();
            }
            if (null !== $envelope && !isset($request['Content']['Raw'])) {
                $request['Content'] = ['Raw' => ['Data' => $this->getRawMessageWithoutBcc($message)]];
            }
            $messageId = Swift_Transport_Api_SesRequestPolicy::run($this->sesClient, static fn (AsyncAws\Ses\SesClient $client) => $client->sendEmail($request)->getMessageId());
            if (null === $messageId || '' === $messageId) {
                return 0;
            }
            $message->getHeaders()->addTextHeader('X-SES-Message-ID', $messageId);

            return null !== $envelope ? \count(\array_unique($envelope->getRecipients())) : $this->getRecipientCount($message);
        } catch (Throwable $e) {
            throw Swift_Transport_Api_SesRequestPolicy::detachedFailure($e, 'Failed to send email');
        }
    }

    protected function getApiConnection(): mixed
    {
        return $this->sesClient;
    }

    private function getRequest(Swift_Mime_SimpleMessage $message, array $tags = []): array
    {
        // Amazon SES v2 (async-aws) SendEmail request. The v2 schema uses
        // FromEmailAddress / Content / EmailTags -- NOT the v1 Source / Message /
        // Tags keys, which async-aws silently discards, leaving the required
        // Content empty and producing an invalid request.
        $request = [
            'FromEmailAddress' => \array_key_first($message->getFrom() ?? []),
            'Destination'      => [
                'ToAddresses'  => \array_keys($message->getTo() ?? []),
                'CcAddresses'  => \array_keys($message->getCc() ?? []),
                'BccAddresses' => \array_keys($message->getBcc() ?? []),
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data'    => $message->getSubject(),
                        'Charset' => 'UTF-8',
                    ],
                    'Body' => [],
                ],
            ],
        ];

        if ($message->getChildren()) {
            $request['Content'] = ['Raw' => ['Data' => $this->getRawMessageWithoutBcc($message)]];
        } else {
            $bodyPart = ['Data' => $message->getBody(), 'Charset' => 'UTF-8'];
            if ('text/html' === $message->getBodyContentType()) {
                $request['Content']['Simple']['Body']['Html'] = $bodyPart;
            } else {
                $request['Content']['Simple']['Body']['Text'] = $bodyPart;
            }
        }

        if ($replyTo = $message->getReplyTo()) {
            $request['ReplyToAddresses'] = \array_keys($replyTo);
        }

        if ($returnPath = $message->getReturnPath()) {
            $request['FeedbackForwardingEmailAddress'] = $returnPath;
        }

        foreach ($message->getHeaders()->getAll() as $header) {
            if (!$header instanceof Swift_Mime_Headers_UnstructuredHeader) {
                continue;
            }

            switch ($header->getFieldName()) {
                case 'X-SES-CONFIGURATION-SET':
                    $request['ConfigurationSetName'] = $header->getValue();
                    break;
                case 'X-SES-SOURCE-ARN':
                    $request['FromEmailAddressIdentityArn'] = $header->getValue();
                    break;
                case 'X-SES-LIST-MANAGEMENT-OPTIONS':
                    if (\preg_match(
                        "/^(contactListName=)*(?<ContactListName>[^;]+)(;\s?topicName=(?<TopicName>.+))?$/ix",
                        $header->getValue(),
                        $listManagementOptions,
                    )) {
                        $request['ListManagementOptions'] = \array_filter(
                            $listManagementOptions,
                            static fn ($e) => \in_array($e, ['ContactListName', 'TopicName'], true),
                            \ARRAY_FILTER_USE_KEY,
                        );
                    }
                    break;
            }
        }

        // Tags from X-Mailer-Tag headers → EmailTags (array of {Name, Value})
        foreach ($tags as $tag) {
            $request['EmailTags'][] = ['Name' => 'tag', 'Value' => $tag];
        }

        return $request;
    }

    /**
     * Extract X-Mailer-Tag headers from message and remove them.
     *
     * @return string[]
     */
    private function extractSesTagsFromMessage(Swift_Mime_SimpleMessage $message): array
    {
        $tags    = [];
        $headers = $message->getHeaders();

        foreach ($headers->getAll('X-Mailer-Tag') as $header) {
            $tags[] = $header->getFieldBody();
        }

        if ($tags) {
            $headers->removeAll('X-Mailer-Tag');
        }

        return $tags;
    }

    private function getRecipientCount(Swift_Mime_SimpleMessage $message): int
    {
        return \count($message->getTo() ?? []) + \count($message->getCc() ?? []) + \count($message->getBcc() ?? []);
    }
}
