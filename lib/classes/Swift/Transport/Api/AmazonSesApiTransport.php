<?php

use AsyncAws\Ses\Input\SendEmailRequest;
use AsyncAws\Ses\SesClient;

class Swift_Transport_Api_AmazonSesApiTransport extends Swift_Transport_AbstractApiTransport
{
    private $sesClient;

    public function __construct(SesClient $sesClient, ?Swift_Events_EventDispatcher $eventDispatcher = null)
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
        // async-aws's SesClient has no getAccountSendingEnabled(). Probe the
        // SESv2 suppression endpoint instead: a reachable account returns either
        // the suppressed-destination record or a NotFoundException -- both prove
        // the API answered. Only a transport/credential failure is unhealthy.
        try {
            $this->sesClient->getSuppressedDestination(['EmailAddress' => 'ping@swiftmailer.invalid'])->resolve();

            return true;
        } catch (AsyncAws\Ses\Exception\NotFoundException $e) {
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null, ?Swift_Envelope $envelope = null): int
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        try {
            $tags    = $this->extractSesTagsFromMessage($message);
            $request = $this->getRequest($message, $tags);

            $result = $this->sesClient->sendEmail($request);

            return $result->getMessageId() ? 1 : 0;
        } catch (Exception $e) {
            $this->throwException(new Swift_TransportException('Unable to send email: '.$e->getMessage(), 0, $e));
        }

        return 0;
    }

    protected function getApiConnection(): mixed
    {
        return $this->sesClient;
    }

    protected function getRequest(Swift_Mime_SimpleMessage $message, array $tags = []): SendEmailRequest
    {
        // Amazon SES v2 (async-aws) SendEmail request. The v2 schema uses
        // FromEmailAddress / Content / EmailTags -- NOT the v1 Source / Message /
        // Tags keys. async-aws maps the plain nested arrays below into its value
        // objects via SendEmailRequest::__construct, so no Content/Destination
        // value objects are needed here.
        $from      = $message->getFrom() ?? [];
        $fromEmail = (string) \array_key_first($from);

        $request = [
            'FromEmailAddress' => $this->stringifyAddress($fromEmail, $from[$fromEmail] ?? null),
            'Destination'      => [
                'ToAddresses' => $this->stringifyAddresses($message->getTo() ?? []),
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data'    => $message->getSubject(),
                        'Charset' => 'utf-8',
                    ],
                    'Body' => [],
                ],
            ],
        ];

        if ($cc = $message->getCc()) {
            $request['Destination']['CcAddresses'] = $this->stringifyAddresses($cc);
        }
        if ($bcc = $message->getBcc()) {
            $request['Destination']['BccAddresses'] = $this->stringifyAddresses($bcc);
        }

        // SES Simple bodies are mutually exclusive: HTML or Text, never both.
        $bodyPart = ['Data' => $message->getBody(), 'Charset' => 'utf-8'];
        if ('text/html' === $message->getBodyContentType()) {
            $request['Content']['Simple']['Body']['Html'] = $bodyPart;
        } else {
            $request['Content']['Simple']['Body']['Text'] = $bodyPart;
        }

        if ($replyTo = $message->getReplyTo()) {
            $request['ReplyToAddresses'] = $this->stringifyAddresses($replyTo);
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

        if ($returnPath = $message->getReturnPath()) {
            $request['FeedbackForwardingEmailAddress'] = $returnPath;
        }

        // Tags from X-Mailer-Tag headers → EmailTags (array of {Name, Value})
        foreach ($tags as $tag) {
            $request['EmailTags'][] = ['Name' => 'tag', 'Value' => $tag];
        }

        return new SendEmailRequest($request);
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

    /**
     * Stringify a Swift [email => name] address map for the SES request.
     *
     * @param array<string, ?string> $addresses
     *
     * @return string[]
     */
    protected function stringifyAddresses(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $email => $name) {
            $result[] = $this->stringifyAddress((string) $email, $name);
        }

        return $result;
    }

    protected function stringifyAddress(string $email, ?string $name): string
    {
        if (null === $name || '' === $name) {
            return $email;
        }

        // AWS does not accept UTF-8 in addresses: B-encode a non-ASCII display name.
        if (\preg_match('~[\x00-\x08\x10-\x19\x7F-\xFF\r\n]~', $name)) {
            return \sprintf('=?UTF-8?B?%s?= <%s>', \base64_encode($name), $email);
        }

        return \sprintf('%s <%s>', $name, $email);
    }
}
