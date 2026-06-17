<?php

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
        try {
            $this->sesClient->getAccountSendingEnabled();

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
            $email   = $this->convertMessage($message);
            $request = $this->getRequest($email, $tags);

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

    private function convertMessage(Swift_Mime_SimpleMessage $message): Email
    {
        $email = new Email();

        $email->subject($message->getSubject());
        $email->from(new Address($message->getFrom()));
        $email->to(...$this->convertAddresses($message->getTo()));

        if ($message->getCc()) {
            $email->cc(...$this->convertAddresses($message->getCc()));
        }

        if ($message->getBcc()) {
            $email->bcc(...$this->convertAddresses($message->getBcc()));
        }

        $email->text($message->getBody());

        if ('text/html' === $message->getContentType()) {
            $email->html($message->getBody());
        }

        return $email;
    }

    private function convertAddresses(array $addresses): array
    {
        return \array_map(function ($address, $name) {
            return new Address($address, $name);
        }, \array_keys($addresses), $addresses);
    }

    protected function getRequest(Email $email, array $tags = []): SendEmailRequest
    {
        $request = [
            'FromEmailAddress' => $this->stringifyAddress($email->getFrom()[0]),
            'Destination'      => [
                'ToAddresses' => $this->stringifyAddresses($email->getTo()),
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => [
                        'Data'    => $email->getSubject(),
                        'Charset' => 'utf-8',
                    ],
                    'Body' => [],
                ],
            ],
        ];

        if ($emails = $email->getCc()) {
            $request['Destination']['CcAddresses'] = $this->stringifyAddresses($emails);
        }
        if ($emails = $email->getBcc()) {
            $request['Destination']['BccAddresses'] = $this->stringifyAddresses($emails);
        }
        if ($email->getTextBody()) {
            $request['Content']['Simple']['Body']['Text'] = new Content([
                'Data'    => $email->getTextBody(),
                'Charset' => 'utf-8',
            ]);
        }
        if ($email->getHtmlBody()) {
            $request['Content']['Simple']['Body']['Html'] = new Content([
                'Data'    => $email->getHtmlBody(),
                'Charset' => 'utf-8',
            ]);
        }
        if ($emails = $email->getReplyTo()) {
            $request['ReplyToAddresses'] = $this->stringifyAddresses($emails);
        }
        if ($header = $email->getHeaders()->get('X-SES-CONFIGURATION-SET')) {
            $request['ConfigurationSetName'] = $header->getBodyAsString();
        }
        if ($header = $email->getHeaders()->get('X-SES-SOURCE-ARN')) {
            $request['FromEmailAddressIdentityArn'] = $header->getBodyAsString();
        }
        if ($header = $email->getHeaders()->get('X-SES-LIST-MANAGEMENT-OPTIONS')) {
            if (\preg_match("/^(contactListName=)*(?<ContactListName>[^;]+)(;\s?topicName=(?<TopicName>.+))?$/ix", $header->getBodyAsString(), $listManagementOptions)) {
                $request['ListManagementOptions'] = \array_filter($listManagementOptions, fn ($e) => \in_array($e, ['ContactListName', 'TopicName']), \ARRAY_FILTER_USE_KEY);
            }
        }
        if ($email->getReturnPath()) {
            $request['FeedbackForwardingEmailAddress'] = $email->getReturnPath()->toString();
        }

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $request['EmailTags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
            }
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

    protected function stringifyAddresses(array $addresses): array
    {
        return \array_map(fn (Address $a) => $this->stringifyAddress($a), $addresses);
    }

    protected function stringifyAddress(Address $a): string
    {
        // AWS does not support UTF-8 address
        if (\preg_match('~[\x00-\x08\x10-\x19\x7F-\xFF\r\n]~', $name = $a->getName())) {
            return \sprintf(
                '=?UTF-8?B?%s?= <%s>',
                \base64_encode($name),
                $a->getEncodedAddress(),
            );
        }

        return $a->toString();
    }
}
