<?php

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
        try {
            // Perform a lightweight request to check if the connection is working
            $this->sesClient->listIdentities();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        try {
            $tags      = $this->extractSesTagsFromMessage($message);
            $request   = $this->getRequest($message, $tags);
            $messageId = $this->sesClient->sendEmail($request)->getMessageId();
            $message->getHeaders()->addTextHeader('X-SES-Message-ID', $messageId);

            return $this->getRecipientCount($message);
        } catch (Exception $e) {
            if (null !== $failedRecipients) {
                $failedRecipients = \array_merge($failedRecipients, $this->getFailedRecipients($message));
            }
            throw new Swift_TransportException('Failed to send email', 0, $e);
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

        $bodyPart = ['Data' => $message->getBody(), 'Charset' => 'UTF-8'];
        if ('text/html' === $message->getBodyContentType()) {
            $request['Content']['Simple']['Body']['Html'] = $bodyPart;
        } else {
            $request['Content']['Simple']['Body']['Text'] = $bodyPart;
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

    private function getFailedRecipients(Swift_Mime_SimpleMessage $message): array
    {
        $failedRecipients = [];

        foreach (['To', 'Cc', 'Bcc'] as $type) {
            foreach ($message->{'get'.$type}() ?? [] as $address => $name) { // I hate this
                $failedRecipients[] = $address;
            }
        }

        return $failedRecipients;
    }
}
