<?php

use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Generated\Models\Attachment;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody;
use Microsoft\Graph\GraphServiceClient;

class Swift_Transport_Api_MicrosoftGraphTransport extends Swift_Transport_AbstractApiTransport
{
    private GraphServiceClient $client;

    private Swift_Events_EventDispatcher $dispatcher;

    private string $sendingAccountUserId;

    /**
     * @var true
     */
    private bool $shouldUseFromAddress = false;

    public function __construct(
        GraphServiceClient $client,
        string $sendingAccountUserId,
        ?Swift_Events_EventDispatcher $dispatcher = null,
    ) {
        $this->client               = $client;
        $this->dispatcher           = $dispatcher;
        $this->sendingAccountUserId = $sendingAccountUserId;
    }

    public function getSendingAccountUserId(): string
    {
        return $this->sendingAccountUserId;
    }

    public function setSendingAccountUserId(string $sendingAccountUserId): void
    {
        $this->sendingAccountUserId = $sendingAccountUserId;
        $this->shouldUseFromAddress = false;
    }

    public function useFromAddressAsSendingAccountUserId(): void
    {
        $this->shouldUseFromAddress = true;
    }

    public function ping(): bool
    {
        if (!$this->isStarted()) {
            $this->start();
        }

        // nothing to do for a "ping" really

        return true;
    }

    public function start(): void
    {
        if (!$this->started) {
            if ($evt = $this->eventDispatcher->createTransportChangeEvent($this)) {
                $this->eventDispatcher->dispatchEvent($evt, 'beforeTransportStarted');
                if ($evt->bubbleCancelled()) {
                    return;
                }
            }

            // nothing to "start" with an API connection, but we should still honor the event dispatcher expectations
            if ($evt) {
                $this->eventDispatcher->dispatchEvent($evt, 'transportStarted');
            }

            $this->started = true;
        }
    }

    /**
     * Sends a Swift_Mime_SimpleMessage using the Microsoft Graph API.
     *
     * @param Swift_Mime_SimpleMessage $message           the message to send
     * @param array|null               &$failedRecipients A reference to an array that will contain any failed recipients
     *
     * @throws Swift_TransportException
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
        if (null === $failedRecipients) {
            $failedRecipients = [];
        }

        if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }
        // create and dispatch event before transport start
        $event = $this->dispatcher->createTransportChangeEvent($this);
        $this->dispatcher->dispatchEvent($event, 'beforeTransportStarted');
        if ($event->bubbleCancelled()) {
            return 0;
        }

        $recipient_count = 0;

        $recipient    = new Recipient();
        $emailAddress = new EmailAddress();

        $failedRecipients[] = \array_key_first($message->getTo());
        $emailAddress->setAddress(\array_key_first($message->getTo()));
        $emailAddress->setName(\array_values($message->getTo())[0]);
        $recipient->setEmailAddress($emailAddress);

        $graphMessage = new Message();
        $graphMessage->setSubject($message->getSubject());

        $body = new ItemBody();
        $body->setContent($message->getBody());
        try {
            $body->setContentType(
                new BodyType(
                    match ($message->getBodyContentType()) {
                        'text/plain' => 'text',
                        'text/html'  => 'html',
                    },
                ),
            );
        } catch (ReflectionException $e) {
            $this->throwException(new Swift_TransportException("Failed to set Graph BodyType: {$e->getMessage()}"));
        }
        $graphMessage->setBody($body);
        $graphMessage->setToRecipients([$recipient]);

        if (\count($message->getCc() ?? []) > 0) {
            $graphMessage->setCcRecipients(\array_map(function ($row) use (&$recipient_count, &$failedRecipients) {
                $failedRecipients[] = \array_key_first($row);
                ++$recipient_count;

                return $this->convertSwiftEmailAddressToGraphRecipient($row);
            }, $message->getCc() ?? []));
        }

        if (\count($message->getBcc() ?? []) > 0) {
            $graphMessage->setBccRecipients(\array_map(function ($row) use (&$recipient_count, &$failedRecipients) {
                $failedRecipients[] = \array_key_first($row);
                ++$recipient_count;

                return $this->convertSwiftEmailAddressToGraphRecipient($row);
            }, $message->getBcc() ?? []));
        }

        $graphAttachments = [];

        foreach ($message->getChildren() ?? [] as $swiftAttachment) {
            $graphAttachments[] = $this->convertSwiftAttachmentToGraphAttachment($swiftAttachment);
        }

        if (!empty($graphAttachments)) {
            $graphMessage->setAttachments($graphAttachments);
        }

        $recipient    = new Recipient();
        $emailAddress = new EmailAddress();
        $replyTo      = $message->getReplyTo();
        if (\is_array($replyTo)) {
            $replyTo = \array_key_first($replyTo);
            $emailAddress->setAddress($replyTo);
            $recipient->setEmailAddress($emailAddress);
            $graphMessage->setReplyTo([$recipient]);
            ++$recipient_count;
        }

        $sendMailBody = new SendMailPostRequestBody();
        $sendMailBody->setMessage($graphMessage);

        try {
            $this->client->users()->byUserId($this->sendingAccountUserId)->sendMail()->post($sendMailBody)->wait();
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            }
            $failedRecipients = [];
        } catch (Throwable $e) {
            $exception = new Swift_TransportException("Failed to send email: {$e->getMessage()}", $e->getCode(), $e);
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $evt->setFailedRecipients($failedRecipients);
            }
            $recipient_count = 0;
            $failure         = true;
        } finally {
            $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
        }

        if (($failure ?? false) && isset($exception)) {
            throw $exception;
        }

        return $recipient_count;
    }

    private function convertSwiftEmailAddressToGraphRecipient(array $swift_email): Recipient
    {
        $recipient    = new Recipient();
        $emailAddress = new EmailAddress();

        $emailAddress->setAddress(\array_key_first($swift_email));
        $emailAddress->setName(\array_values($swift_email)[0]);

        $recipient->setEmailAddress($emailAddress);

        return $recipient;
    }

    private function convertSwiftAttachmentToGraphAttachment(Swift_Attachment $swiftAttachment): Attachment
    {
        $graphAttachment = new FileAttachment();
        $graphAttachment->setName($swiftAttachment->getFilename());
        $graphAttachment->setContentType($swiftAttachment->getContentType());
        $graphAttachment->setIsInline('attachment' !== $swiftAttachment->getDisposition());
        $graphAttachment->setContentBytes(
            Utils::streamFor(\base64_encode($swiftAttachment->getBody())),
        ); // Graph API requires the content to be base64-encoded
        $graphAttachment->setSize($swiftAttachment->getSize());

        return $graphAttachment;
    }

    protected function getApiConnection(): GraphServiceClient
    {
        return $this->client;
    }
}
