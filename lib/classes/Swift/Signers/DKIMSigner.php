<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * DKIM Signer used to apply DKIM Signature to a message.
 *
 * @author     Xavier De Cock <xdecock@gmail.com>
 */
class Swift_Signers_DKIMSigner implements Swift_Signers_HeaderSigner
{
    /**
     * Headers to oversign when oversigning is enabled.
     */
    private const OVERSIGN_HEADERS = [
        'from', 'to', 'subject', 'date', 'cc', 'reply-to', 'message-id',
    ];

    protected string $privateKey;

    protected string $domainName;

    protected string $selector;

    private string $passphrase = '';

    /**
     * Hash algorithm used.
     *
     * @see RFC6376 3.3: Signers MUST implement and SHOULD sign using rsa-sha256.
     */
    protected string $hashAlgorithm = 'rsa-sha256';

    /** Body canonicalization method. */
    protected string $bodyCanon = 'simple';

    /** Header canonicalization method. */
    protected string $headerCanon = 'simple';

    /** Headers not being signed. */
    protected array $ignoredHeaders = ['return-path' => true, 'x-transport' => true];

    protected string $signerIdentity;

    protected int $bodyLen = 0;

    protected int $maxLen = PHP_INT_MAX;

    protected bool $showLen = false;

    /** When the signature has been applied (true means time()), false means not embedded. */
    protected int|bool $signatureTimestamp = true;

    /** When will the signature expire. false means not embedded. */
    protected int|false $signatureExpiration = false;

    protected bool $debugHeaders = false;

    /**
     * Whether to oversign critical headers to prevent replay attacks.
     *
     * When enabled, From, To, Subject, Date, Cc, Reply-To, and Message-ID
     * are signed an extra time (for a non-existent instance), so any header
     * added post-signing breaks DKIM verification.
     */
    protected bool $oversigning = false;

    // work variables
    protected array $signedHeaders = [];

    /** @var string[] */
    private array $debugHeadersData = [];

    private string $bodyHash = '';

    protected $dkimHeader;

    private $bodyHashHandler;

    private $headerHash;

    private string $headerCanonData = '';

    private int $bodyCanonEmptyCounter = 0;

    private int $bodyCanonIgnoreStart = 2;

    private bool $bodyCanonSpace = false;

    private $bodyCanonLastChar;

    private string $bodyCanonLine = '';

    private array $bound = [];

    /**
     * Constructor.
     *
     * @param string $passphrase
     */
    public function __construct(
        string $privateKey,
        string $domainName,
        string $selector,
        #[SensitiveParameter] string $passphrase = '',
    ) {
        $this->domainName     = $domainName;
        $this->signerIdentity = '@'.$domainName;
        $this->selector       = $selector;
        $this->passphrase     = $passphrase;

        // Try to load as RSA key; if it fails and it's a raw binary key
        // (64 bytes for Ed25519 secret key), store it for later Ed25519 use.
        if (\defined('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES')
            && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === \strlen($privateKey)
            && !\str_contains($privateKey, '-----BEGIN')) {
            // Raw Ed25519 secret key
            $this->privateKey = $privateKey;
        } else {
            $pkeyId = \openssl_pkey_get_private($privateKey, $passphrase);
            if (!$pkeyId) {
                throw new Swift_SwiftException('Unable to load DKIM Private Key ['.\openssl_error_string().']');
            }
            $this->privateKey = $privateKey;
        }
    }

    /**
     * Reset the Signer.
     *
     * @see Swift_Signer::reset()
     */
    #[\Override]
    public function reset()
    {
        $this->headerHash            = null;
        $this->signedHeaders         = [];
        $this->bodyHash              = '';
        $this->bodyHashHandler       = null;
        $this->bodyCanonIgnoreStart  = 2;
        $this->bodyCanonEmptyCounter = 0;
        $this->bodyCanonLastChar     = null;
        $this->bodyCanonSpace        = false;
    }

    /**
     * Writes $bytes to the end of the stream.
     *
     * Writing may not happen immediately if the stream chooses to buffer.  If
     * you want to write these bytes with immediate effect, call {@link commit()}
     * after calling write().
     *
     * This method returns the sequence ID of the write (i.e. 1 for first, 2 for
     * second, etc etc).
     *
     * @param string|array $bytes
     *
     * @return int
     *
     * @throws Swift_IoException
     */
    // TODO fix return
    #[\Override]
    public function write($bytes)
    {
        // Convert array to string
        if (\is_array($bytes)) {
            $bytes = \implode('', $bytes);
        }
        $this->canonicalizeBody($bytes);
        foreach ($this->bound as $is) {
            $is->write($bytes);
        }
    }

    /**
     * For any bytes that are currently buffered inside the stream, force them
     * off the buffer.
     */
    #[\Override]
    public function commit()
    {
        // Nothing to do

    }

    /**
     * Attach $is to this stream.
     *
     * The stream acts as an observer, receiving all data that is written.
     * All {@link write()} and {@link flushBuffers()} operations will be mirrored.
     */
    #[\Override]
    public function bind(Swift_InputByteStream $is)
    {
        // Don't have to mirror anything
        $this->bound[] = $is;

    }

    /**
     * Remove an already bound stream.
     *
     * If $is is not bound, no errors will be raised.
     * If the stream currently has any buffered data it will be written to $is
     * before unbinding occurs.
     */
    #[\Override]
    public function unbind(Swift_InputByteStream $is)
    {
        // Don't have to mirror anything
        foreach ($this->bound as $k => $stream) {
            if ($stream === $is) {
                unset($this->bound[$k]);

                return;
            }
        }
    }

    /**
     * Flush the contents of the stream (empty it) and set the internal pointer
     * to the beginning.
     *
     * @throws Swift_IoException
     */
    #[\Override]
    public function flushBuffers()
    {
        $this->reset();
    }

    /**
     * Set hash_algorithm, must be one of rsa-sha256 | rsa-sha1 | ed25519-sha256.
     *
     * @param string $hash 'rsa-sha1', 'rsa-sha256', or 'ed25519-sha256'
     *
     * @return $this
     *
     * @throws Swift_SwiftException
     */
    public function setHashAlgorithm($hash)
    {
        switch ($hash) {
            case 'rsa-sha1':
                \trigger_error(
                    'rsa-sha1 is deprecated per RFC 8301 and will be removed in a future version. Use rsa-sha256 or ed25519-sha256 instead.',
                    \E_USER_DEPRECATED,
                );
                $this->hashAlgorithm = 'rsa-sha1';
                break;
            case 'rsa-sha256':
                $this->hashAlgorithm = 'rsa-sha256';
                if (!\defined('OPENSSL_ALGO_SHA256')) {
                    throw new Swift_SwiftException('Unable to set sha256 as it is not supported by OpenSSL.');
                }
                break;
            case 'ed25519-sha256':
                if (!\function_exists('sodium_crypto_sign_detached')) {
                    throw new Swift_SwiftException('The sodium extension is required for ed25519-sha256 DKIM signing.');
                }
                $this->hashAlgorithm = 'ed25519-sha256';
                break;
            default:
                throw new Swift_SwiftException(\sprintf('Unable to set the hash algorithm, must be one of rsa-sha1, rsa-sha256, or ed25519-sha256 (%s given).', $hash));
        }

        return $this;
    }

    /**
     * Set the body canonicalization algorithm.
     *
     * @param string $canon
     *
     * @return $this
     */
    public function setBodyCanon($canon)
    {
        if ('relaxed' == $canon) {
            $this->bodyCanon = 'relaxed';
        } else {
            $this->bodyCanon = 'simple';
        }

        return $this;
    }

    /**
     * Set the header canonicalization algorithm.
     *
     * @param string $canon
     *
     * @return $this
     */
    public function setHeaderCanon($canon)
    {
        if ('relaxed' == $canon) {
            $this->headerCanon = 'relaxed';
        } else {
            $this->headerCanon = 'simple';
        }

        return $this;
    }

    /**
     * Set the signer identity.
     *
     * @param string $identity
     *
     * @return $this
     */
    public function setSignerIdentity($identity)
    {
        $this->signerIdentity = $identity;

        return $this;
    }

    /**
     * Set the length of the body to sign.
     *
     * @param mixed $len (bool or int)
     *
     * @return $this
     */
    public function setBodySignedLen($len)
    {
        if (true === $len) {
            $this->showLen = true;
            $this->maxLen  = PHP_INT_MAX;
        } elseif (false === $len) {
            $this->showLen = false;
            $this->maxLen  = PHP_INT_MAX;
        } else {
            $this->showLen = true;
            $this->maxLen  = (int) $len;
        }

        return $this;
    }

    /**
     * Set the signature timestamp.
     *
     * @param int $time A timestamp
     *
     * @return $this
     */
    public function setSignatureTimestamp($time)
    {
        $this->signatureTimestamp = $time;

        return $this;
    }

    /**
     * Set the signature expiration timestamp.
     *
     * @param int $time A timestamp
     *
     * @return $this
     */
    public function setSignatureExpiration($time)
    {
        $this->signatureExpiration = $time;

        return $this;
    }

    /**
     * Enable / disable the DebugHeaders.
     *
     * @param bool $debug
     *
     * @return Swift_Signers_DKIMSigner
     */
    public function setDebugHeaders($debug)
    {
        $this->debugHeaders = (bool) $debug;

        return $this;
    }

    /**
     * Enable or disable oversigning of critical headers.
     *
     * @return $this
     */
    public function setOversigning(bool $oversign)
    {
        $this->oversigning = $oversign;

        return $this;
    }

    /**
     * Start Body.
     */
    #[\Override]
    public function startBody()
    {
        // Init
        switch ($this->hashAlgorithm) {
            case 'rsa-sha256':
            case 'ed25519-sha256':
                $this->bodyHashHandler = \hash_init('sha256');
                break;
            case 'rsa-sha1':
                $this->bodyHashHandler = \hash_init('sha1');
                break;
        }
        $this->bodyCanonLine = '';
    }

    /**
     * End Body.
     */
    #[\Override]
    public function endBody()
    {
        $this->endOfBody();
    }

    /**
     * Returns the list of Headers Tampered by this plugin.
     *
     * @return array
     */
    #[\Override]
    public function getAlteredHeaders()
    {
        if ($this->debugHeaders) {
            return ['DKIM-Signature', 'X-DebugHash'];
        }

        return ['DKIM-Signature'];
    }

    /**
     * Adds an ignored Header.
     *
     * @param string $header_name
     *
     * @return Swift_Signers_DKIMSigner
     */
    #[\Override]
    public function ignoreHeader($header_name)
    {
        $lower = \strtolower($header_name ?? '');
        if ('from' === $lower) {
            return $this;
        }
        $this->ignoredHeaders[$lower] = true;

        return $this;
    }

    /**
     * Set the headers to sign.
     *
     * @return Swift_Signers_DKIMSigner
     */
    #[\Override]
    public function setHeaders(Swift_Mime_SimpleHeaderSet $headers)
    {
        $this->headerCanonData = '';
        // Loop through Headers
        $listHeaders = $headers->listAll();
        foreach ($listHeaders as $hName) {
            // Check if we need to ignore Header
            if (!isset($this->ignoredHeaders[\strtolower($hName ?? '')])) {
                if ($headers->has($hName)) {
                    $tmp = $headers->getAll($hName);
                    foreach ($tmp as $header) {
                        if ('' != $header->getFieldBody()) {
                            $this->addHeader($header->toString());
                            $this->signedHeaders[] = $header->getFieldName();
                        }
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Add the signature to the given Headers.
     *
     * @return Swift_Signers_DKIMSigner
     */
    #[\Override]
    public function addSignature(Swift_Mime_SimpleHeaderSet $headers)
    {
        // Build the header list, with optional oversigning
        $headerList = $this->signedHeaders;
        if ($this->oversigning) {
            $lowerSigned = \array_map('strtolower', $headerList);
            foreach (self::OVERSIGN_HEADERS as $oh) {
                if (\in_array($oh, $lowerSigned, true)) {
                    // Find the original-case version to keep formatting consistent
                    $idx          = \array_search($oh, $lowerSigned, true);
                    $headerList[] = $this->signedHeaders[$idx];
                }
            }
        }
        // Prepare the DKIM-Signature
        $params      = ['v' => '1', 'q' => 'dns/txt', 'a' => $this->hashAlgorithm, 'bh' => \base64_encode($this->bodyHash ?? ''), 'd' => $this->domainName, 'h' => \implode(': ', $headerList), 'i' => $this->signerIdentity, 's' => $this->selector];
        $params['c'] = $this->headerCanon.'/'.$this->bodyCanon;
        if ($this->showLen) {
            $params['l'] = $this->bodyLen;
        }
        if (true === $this->signatureTimestamp) {
            $params['t'] = \time();
            if (false !== $this->signatureExpiration) {
                $params['x'] = $params['t'] + $this->signatureExpiration;
            }
        } else {
            if (false !== $this->signatureTimestamp) {
                $params['t'] = $this->signatureTimestamp;
            }
            if (false !== $this->signatureExpiration) {
                $params['x'] = $this->signatureExpiration;
            }
        }
        if ($this->debugHeaders) {
            $params['z'] = \implode('|', $this->debugHeadersData);
        }
        $string = '';
        foreach ($params as $k => $v) {
            $string .= $k.'='.$v.'; ';
        }
        $string = \trim($string);
        $headers->addTextHeader('DKIM-Signature', $string);
        // Add the last DKIM-Signature
        $tmp              = $headers->getAll('DKIM-Signature');
        $this->dkimHeader = \end($tmp);
        $this->addHeader(\trim($this->dkimHeader->toString() ?? '')."\r\n b=", true);
        if ($this->debugHeaders) {
            $headers->addTextHeader('X-DebugHash', \base64_encode($this->headerHash ?? ''));
        }
        $this->dkimHeader->setValue($string.' b='.\trim(\chunk_split(\base64_encode($this->getEncryptedHash() ?? ''), 73, ' ')));

        return $this;
    }

    /* Private helpers */

    protected function addHeader($header, $is_sig = false)
    {
        switch ($this->headerCanon) {
            case 'relaxed':
                // Prepare Header and cascade
                $exploded = \explode(':', $header, 2);
                $name     = \strtolower(\trim($exploded[0]));
                $value    = \str_replace("\r\n", '', $exploded[1]);
                $value    = \preg_replace("/[ \t][ \t]+/", ' ', $value);
                $header   = $name.':'.\trim($value).($is_sig ? '' : "\r\n");
                // no break
            case 'simple':
                // Nothing to do
        }
        $this->addToHeaderHash($header);
    }

    protected function canonicalizeBody($string)
    {
        $len    = \strlen($string);
        $canon  = '';
        $method = ('relaxed' == $this->bodyCanon);
        for ($i = 0; $i < $len; ++$i) {
            if ($this->bodyCanonIgnoreStart > 0) {
                --$this->bodyCanonIgnoreStart;
                continue;
            }
            switch ($string[$i]) {
                case "\r":
                    $this->bodyCanonLastChar = "\r";
                    break;
                case "\n":
                    if ("\r" == $this->bodyCanonLastChar) {
                        if ($method) {
                            $this->bodyCanonSpace = false;
                        }
                        if ('' == $this->bodyCanonLine) {
                            ++$this->bodyCanonEmptyCounter;
                        } else {
                            $this->bodyCanonLine = '';
                            $canon .= "\r\n";
                        }
                    }
                    // Wooops Error
                    // todo handle it but should never happen

                    break;
                case ' ':
                case "\t":
                    if ($method) {
                        $this->bodyCanonSpace = true;
                        break;
                    }
                    // no break
                default:
                    if ($this->bodyCanonEmptyCounter > 0) {
                        $canon .= \str_repeat("\r\n", $this->bodyCanonEmptyCounter);
                        $this->bodyCanonEmptyCounter = 0;
                    }
                    if ($this->bodyCanonSpace) {
                        $this->bodyCanonLine .= ' ';
                        $canon               .= ' ';
                        $this->bodyCanonSpace = false;
                    }
                    $this->bodyCanonLine .= $string[$i];
                    $canon               .= $string[$i];
            }
        }
        $this->addToBodyHash($canon);
    }

    protected function endOfBody()
    {
        // Add trailing line return if last line is non-empty
        if (\strlen($this->bodyCanonLine) > 0) {
            $this->addToBodyHash("\r\n");
        }

        // RFC 6376 Section 3.4.3: If the body is null (not even one CRLF),
        // a CRLF is added for simple canonicalization.
        if ('simple' === $this->bodyCanon && 0 === $this->bodyLen) {
            $this->addToBodyHash("\r\n");
        }

        $this->bodyHash = \hash_final($this->bodyHashHandler, true);
    }

    private function addToBodyHash($string)
    {
        $len = \strlen($string);
        if ($len > ($new_len = ($this->maxLen - $this->bodyLen))) {
            $string = \substr($string, 0, $new_len);
            $len    = $new_len;
        }
        \hash_update($this->bodyHashHandler, $string);
        $this->bodyLen += $len;
    }

    private function addToHeaderHash($header)
    {
        if ($this->debugHeaders) {
            $this->debugHeadersData[] = \trim($header ?? '');
        }
        $this->headerCanonData .= $header;
    }

    /**
     * @return string
     *
     * @throws Swift_SwiftException
     */
    private function getEncryptedHash()
    {
        $signature = '';

        if ('ed25519-sha256' === $this->hashAlgorithm) {
            // Ed25519 uses sodium_crypto_sign_detached with the raw secret key.
            // The header canon data is first hashed with SHA-256, then signed.
            $hash      = \hash('sha256', $this->headerCanonData, true);
            $signature = \sodium_crypto_sign_detached($hash, $this->privateKey);

            return $signature;
        }

        switch ($this->hashAlgorithm) {
            case 'rsa-sha1':
                $algorithm = OPENSSL_ALGO_SHA1;
                break;
            case 'rsa-sha256':
            default:
                $algorithm = OPENSSL_ALGO_SHA256;
                break;
        }
        $pkeyId = \openssl_pkey_get_private($this->privateKey, $this->passphrase);
        if (!$pkeyId) {
            throw new Swift_SwiftException('Unable to load DKIM Private Key ['.\openssl_error_string().']');
        }
        if (\openssl_sign($this->headerCanonData, $signature, $pkeyId, $algorithm)) {
            return $signature;
        }
        throw new Swift_SwiftException('Unable to sign DKIM Hash ['.\openssl_error_string().']');
    }
}
