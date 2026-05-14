<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Adds read-receipt tracking to outgoing messages via MDN headers,
 * tracking pixels, or both.
 *
 * @author Nicolas Corder
 */
class Swift_Plugins_ReadReceiptPlugin implements Swift_Events_SendListener
{
    /** Request an MDN (Disposition-Notification-To) header. */
    public const int MODE_MDN = 1;

    /** Inject a 1×1 tracking pixel into the HTML body. */
    public const int MODE_PIXEL = 2;

    /** Use both MDN header and tracking pixel. */
    public const int MODE_BOTH = 3;

    private int $mode;

    private ?string $address;

    /** @var callable(Swift_Mime_SimpleMessage): ?string|null */
    private $pixelUrlGenerator;

    private ?Swift_Mime_SimpleMessage $lastMessage = null;

    private ?string $originalBody = null;

    /** @var array<string, string> */
    private array $originalChildBodies = [];

    private bool $hadReadReceiptHeader = false;

    /** @var mixed */
    private $originalReadReceiptTo = null;

    /**
     * @param int           $mode              MODE_MDN, MODE_PIXEL, or MODE_BOTH
     * @param string|null   $address           Email for MDN receipts (defaults to From)
     * @param callable|null $pixelUrlGenerator Receives the message, returns the pixel URL
     */
    public function __construct(
        int $mode = self::MODE_MDN,
        ?string $address = null,
        ?callable $pixelUrlGenerator = null,
    ) {
        $this->setMode($mode);
        $this->address = $address;
        $this->pixelUrlGenerator = $pixelUrlGenerator;
    }

    public function setMode(int $mode): void
    {
        if ($mode < 1 || $mode > 3) {
            throw new Swift_SwiftException(
                'Invalid read receipt mode. Use MODE_MDN (1), MODE_PIXEL (2), or MODE_BOTH (3).'
            );
        }
        $this->mode = $mode;
    }

    public function getMode(): int
    {
        return $this->mode;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setPixelUrlGenerator(?callable $generator): void
    {
        $this->pixelUrlGenerator = $generator;
    }

    public function getPixelUrlGenerator(): ?callable
    {
        return $this->pixelUrlGenerator;
    }

    #[Override]
    public function beforeSendPerformed(Swift_Events_SendEvent $evt): void
    {
        $message = $evt->getMessage();
        $this->restoreMessage($message);
        $this->lastMessage = $message;

        if ($this->mode & self::MODE_MDN) {
            $this->applyMdn($message);
        }

        if ($this->mode & self::MODE_PIXEL) {
            $this->applyPixel($message);
        }
    }

    #[Override]
    public function sendPerformed(Swift_Events_SendEvent $evt): void
    {
        $this->restoreMessage($evt->getMessage());
    }

    private function applyMdn(Swift_Mime_SimpleMessage $message): void
    {
        $address = $this->address ?? $this->resolveFromAddress($message);
        if (null === $address) {
            return;
        }

        $this->hadReadReceiptHeader = $message->getHeaders()->has('Disposition-Notification-To');
        $this->originalReadReceiptTo = $message->getReadReceiptTo();

        $message->setReadReceiptTo($address);
    }

    private function applyPixel(Swift_Mime_SimpleMessage $message): void
    {
        if (null === $this->pixelUrlGenerator) {
            return;
        }

        $url = ($this->pixelUrlGenerator)($message);
        if (null === $url || '' === $url) {
            return;
        }

        $pixel = '<img src="'
            . \htmlspecialchars($url, \ENT_QUOTES, 'UTF-8')
            . '" alt="" width="1" height="1" border="0"'
            . ' style="height:1px !important;width:1px !important;border:0 !important;'
            . 'margin:0 !important;padding:0 !important;" />';

        $contentType = $message->getContentType();
        if (null !== $contentType && false !== \stripos($contentType, 'text/html')) {
            $body = $message->getBody();
            if (\is_string($body) && '' !== $body) {
                $this->originalBody = $body;
                $message->setBody($this->injectPixel($body, $pixel));

                return;
            }
        }

        foreach ($message->getChildren() as $child) {
            $childType = $child->getContentType();
            if (null !== $childType && false !== \stripos($childType, 'text/html')) {
                $body = $child->getBody();
                if (\is_string($body) && '' !== $body) {
                    $this->originalChildBodies[$child->getId()] = $body;
                    $child->setBody($this->injectPixel($body, $pixel));
                }
            }
        }
    }

    private function injectPixel(string $html, string $pixel): string
    {
        $pos = \stripos($html, '</body>');
        if (false !== $pos) {
            return \substr($html, 0, $pos) . $pixel . \substr($html, $pos);
        }

        return $html . $pixel;
    }

    private function restoreMessage(Swift_Mime_SimpleMessage $message): void
    {
        if ($this->lastMessage !== $message) {
            return;
        }

        if (null !== $this->originalBody) {
            $message->setBody($this->originalBody);
            $this->originalBody = null;
        }

        if (!empty($this->originalChildBodies)) {
            foreach ($message->getChildren() as $child) {
                $id = $child->getId();
                if (\array_key_exists($id, $this->originalChildBodies)) {
                    $child->setBody($this->originalChildBodies[$id]);
                }
            }
            $this->originalChildBodies = [];
        }

        if (!$this->hadReadReceiptHeader) {
            $message->getHeaders()->removeAll('Disposition-Notification-To');
        } elseif (null !== $this->originalReadReceiptTo) {
            $message->setReadReceiptTo($this->originalReadReceiptTo);
        }

        $this->hadReadReceiptHeader = false;
        $this->originalReadReceiptTo = null;
        $this->lastMessage = null;
    }

    private function resolveFromAddress(Swift_Mime_SimpleMessage $message): ?string
    {
        $from = $message->getFrom();
        if (\is_array($from) && !empty($from)) {
            return \array_key_first($from);
        }

        return null;
    }
}
