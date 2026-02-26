<?php

use Psr\Http\Message\ResponseInterface;

class Swift_Transport_Api_SendgridTransport extends Swift_Transport_AbstractHttpApiTransport
{
    private const HOST = 'https://api.sendgrid.com';

    protected function doSend(Swift_Mime_SimpleMessage $message): array
    {
        $payload = $this->getPayload($message);

        $response = $this->httpClient->request('POST', $this->getEndpoint(), [
            'headers' => \array_merge($this->getAuthHeaders(), [
                'Content-Type' => 'application/json',
            ]),
            'body'        => \json_encode($payload),
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $body     = \json_decode($response->getBody()->getContents(), true);
            $errorMsg = $body['errors'][0]['message'] ?? 'Unknown SendGrid error';
            throw new Swift_TransportException('SendGrid API error: '.$errorMsg);
        }

        return ['recipients' => $this->countRecipients($message)];
    }

    protected function getEndpoint(): string
    {
        return self::HOST.'/v3/mail/send';
    }

    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }

    protected function parseResponse(ResponseInterface $response): array
    {
        return \json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getPingEndpoint(): string
    {
        return self::HOST.'/v3/scopes';
    }

    private function getPayload(Swift_Mime_SimpleMessage $message): array
    {
        $tags     = $this->extractTags($message);
        $metadata = $this->extractMetadata($message);

        $from      = $message->getFrom();
        $fromEmail = \array_key_first($from);
        $fromName  = $from[$fromEmail];

        $personalization = [];

        // To
        $personalization['to'] = [];
        foreach ($message->getTo() as $email => $name) {
            $personalization['to'][] = \array_filter(['email' => $email, 'name' => $name]);
        }

        // CC
        if ($cc = $message->getCc()) {
            $personalization['cc'] = [];
            foreach ($cc as $email => $name) {
                $personalization['cc'][] = \array_filter(['email' => $email, 'name' => $name]);
            }
        }

        // BCC
        if ($bcc = $message->getBcc()) {
            $personalization['bcc'] = [];
            foreach ($bcc as $email => $name) {
                $personalization['bcc'][] = \array_filter(['email' => $email, 'name' => $name]);
            }
        }

        $payload = [
            'personalizations' => [$personalization],
            'from'             => \array_filter(['email' => $fromEmail, 'name' => $fromName]),
            'subject'          => $message->getSubject(),
        ];

        // Reply-To
        if ($replyTo = $message->getReplyTo()) {
            $replyEmail          = \array_key_first($replyTo);
            $payload['reply_to'] = \array_filter(['email' => $replyEmail, 'name' => $replyTo[$replyEmail]]);
        }

        // Content (text and/or html)
        $body               = $this->getMessageBody($message);
        $payload['content'] = [];
        if ($body['text']) {
            $payload['content'][] = ['type' => 'text/plain', 'value' => $body['text']];
        }
        if ($body['html']) {
            $payload['content'][] = ['type' => 'text/html', 'value' => $body['html']];
        }

        // Attachments
        $attachments = $this->getMessageAttachments($message);
        if (!empty($attachments)) {
            $payload['attachments'] = [];
            foreach ($attachments as $att) {
                $payload['attachments'][] = \array_filter([
                    'content'     => \base64_encode($att['content']),
                    'type'        => $att['contentType'],
                    'filename'    => $att['filename'],
                    'disposition' => $att['disposition'],
                    'content_id'  => 'inline' === $att['disposition'] ? $att['contentId'] : null,
                ]);
            }
        }

        // Tags → categories (array of strings)
        if (!empty($tags)) {
            $payload['categories'] = $tags;
        }

        // Metadata → custom_args (object in personalizations)
        if (!empty($metadata)) {
            $payload['personalizations'][0]['custom_args'] = $metadata;
        }

        return $payload;
    }
}
