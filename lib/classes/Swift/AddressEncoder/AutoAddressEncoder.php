<?php

/*
 * Copyright (c) 2024. Redrock Software Corporation
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Auto-detecting address encoder that switches between IDN and UTF-8 modes.
 *
 * In IDN mode (default): delegates to IdnAddressEncoder, which encodes the domain
 * via idn_to_ascii() but throws on non-ASCII local-parts.
 *
 * In UTF-8 mode (when SMTPUTF8 is available): delegates to Utf8AddressEncoder,
 * which passes addresses through verbatim per RFC 6531/6532.
 *
 * The EsmtpTransport sets the mode after EHLO based on server capabilities.
 */
class Swift_AddressEncoder_AutoAddressEncoder implements Swift_AddressEncoder
{
    private Swift_AddressEncoder_IdnAddressEncoder $idnEncoder;
    private Swift_AddressEncoder_Utf8AddressEncoder $utf8Encoder;
    private bool $smtpUtf8Available = false;

    public function __construct(
        ?Swift_AddressEncoder_IdnAddressEncoder $idnEncoder = null,
        ?Swift_AddressEncoder_Utf8AddressEncoder $utf8Encoder = null,
    ) {
        $this->idnEncoder = $idnEncoder ?? new Swift_AddressEncoder_IdnAddressEncoder();
        $this->utf8Encoder = $utf8Encoder ?? new Swift_AddressEncoder_Utf8AddressEncoder();
    }

    public function encodeString(string $address): string
    {
        if ($this->smtpUtf8Available) {
            return $this->utf8Encoder->encodeString($address);
        }

        return $this->idnEncoder->encodeString($address);
    }

    /**
     * Set whether SMTPUTF8 is available on the current connection.
     *
     * Called by EsmtpTransport after EHLO capability parsing.
     */
    public function setSmtpUtf8Available(bool $available): void
    {
        $this->smtpUtf8Available = $available;
    }

    public function isSmtpUtf8Available(): bool
    {
        return $this->smtpUtf8Available;
    }
}
