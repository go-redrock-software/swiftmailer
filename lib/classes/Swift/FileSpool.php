<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2009 Fabien Potencier <fabien.potencier@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Stores Messages on the filesystem.
 *
 * @author Fabien Potencier
 * @author Xavier De Cock <xdecock@gmail.com>
 */
class Swift_FileSpool extends Swift_ConfigurableSpool
{
    /** The spool directory */
    private $path;

    private ?string $signingKey;

    /**
     * File WriteRetry Limit.
     *
     * @var int
     */
    private $retryLimit = 10;

    /**
     * Create a new FileSpool.
     *
     * @param string $path
     *
     * @throws Swift_IoException
     */
    public function __construct($path, #[\SensitiveParameter] ?string $signingKey = null)
    {
        $this->path = $path;
        $this->signingKey = $signingKey;

        if (!\file_exists($this->path)) {
            if (!\mkdir($this->path, 0777, true)) { // @codeCoverageIgnore
                throw new Swift_IoException(\sprintf('Unable to create path "%s".', $this->path)); // @codeCoverageIgnore
            }
        }
    }

    /**
     * Tests if this Spool mechanism has started.
     *
     * @return bool
     */
    public function isStarted()
    {
        return true;
    }

    /**
     * Starts this Spool mechanism.
     */
    public function start()
    {
    }

    /**
     * Stops this Spool mechanism.
     */
    public function stop()
    {
    }

    /**
     * Allow to manage the enqueuing retry limit.
     *
     * Default, is ten and allows over 64^20 different fileNames
     *
     * @param int $limit
     */
    public function setRetryLimit($limit)
    {
        $this->retryLimit = $limit;
    }

    public function setSigningKey(#[\SensitiveParameter] ?string $signingKey): void
    {
        $this->signingKey = $signingKey;
    }

    /**
     * Queues a message.
     *
     * @param Swift_Mime_SimpleMessage $message The message to store
     *
     * @return bool
     *
     * @throws Swift_IoException
     */
    public function queueMessage(Swift_Mime_SimpleMessage $message)
    {
        $ser      = \serialize($message);
        if (null !== $this->signingKey) {
            $hmac = \hash_hmac('sha256', $ser, $this->signingKey);
            $ser = $hmac."\n".$ser;
        }
        $fileName = $this->path.'/'.$this->getRandomString(32);
        for ($i = 0; $i < $this->retryLimit; ++$i) {
            /* We try an exclusive creation of the file. This is an atomic operation, it avoid locking mechanism */
            $fp = @\fopen($fileName.'.message', 'xb');
            if (false !== $fp) {
                if (false === \fwrite($fp, $ser)) { // @codeCoverageIgnore
                    return false; // @codeCoverageIgnore
                }

                return \fclose($fp);
            }
            /* The file already exists, we try a longer fileName */
            $fileName .= $this->getRandomString(1);
        }

        throw new Swift_IoException(\sprintf('Unable to create a file for enqueuing Message in "%s".', $this->path)); // @codeCoverageIgnore
    }

    /**
     * Execute a recovery if for any reason a process is sending for too long.
     *
     * @param int $timeout in second Defaults is for very slow smtp responses
     */
    public function recover($timeout = 900)
    {
        foreach (new DirectoryIterator($this->path) as $file) {
            $file = $file->getRealPath();

            if ('.message.sending' == \substr($file, -16)) {
                $lockedtime = \filectime($file);
                if ((\time() - $lockedtime) > $timeout) {
                    \rename($file, \substr($file, 0, -8));
                }
            }
        }
    }

    /**
     * Classes allowed during unserialization of spooled messages.
     *
     * This allowlist prevents arbitrary object instantiation (CWE-502) while
     * permitting all classes that a legitimately serialized Swift_Message may contain.
     */
    private const UNSERIALIZE_ALLOWED_CLASSES = [
        // Core message and entity classes
        'Swift_Message',
        'Swift_Mime_SimpleMessage',
        'Swift_Mime_SimpleMimeEntity',
        'Swift_Mime_MimePart',
        'Swift_Mime_Attachment',
        'Swift_Mime_EmbeddedFile',
        'Swift_MimePart',
        'Swift_Attachment',
        'Swift_EmbeddedFile',
        'Swift_Image',
        // Header classes
        'Swift_Mime_SimpleHeaderSet',
        'Swift_Mime_SimpleHeaderFactory',
        'Swift_Mime_Headers_DateHeader',
        'Swift_Mime_Headers_IdentificationHeader',
        'Swift_Mime_Headers_MailboxHeader',
        'Swift_Mime_Headers_ParameterizedHeader',
        'Swift_Mime_Headers_PathHeader',
        'Swift_Mime_Headers_UnstructuredHeader',
        'Swift_Mime_Headers_OpenDKIMHeader',
        // Content encoders
        'Swift_Mime_ContentEncoder_Base64ContentEncoder',
        'Swift_Mime_ContentEncoder_NativeQpContentEncoder',
        'Swift_Mime_ContentEncoder_NullContentEncoder',
        'Swift_Mime_ContentEncoder_PlainContentEncoder',
        'Swift_Mime_ContentEncoder_QpContentEncoder',
        'Swift_Mime_ContentEncoder_QpContentEncoderProxy',
        'Swift_Mime_ContentEncoder_RawContentEncoder',
        // Header encoders
        'Swift_Mime_HeaderEncoder_Base64HeaderEncoder',
        'Swift_Mime_HeaderEncoder_QpHeaderEncoder',
        // Base encoders
        'Swift_Encoder_Base64Encoder',
        'Swift_Encoder_QpEncoder',
        'Swift_Encoder_Rfc2231Encoder',
        // Character and stream classes
        'Swift_CharacterStream_ArrayCharacterStream',
        'Swift_CharacterStream_NgCharacterStream',
        'Swift_CharacterReader_GenericFixedWidthReader',
        'Swift_CharacterReader_UsAsciiReader',
        'Swift_CharacterReader_Utf8Reader',
        'Swift_CharacterReaderFactory_SimpleCharacterReaderFactory',
        // Note: Swift_ByteStream_* classes are intentionally excluded.
        // They never appear in legitimately serialized messages, and
        // TemporaryFileByteStream has a __destruct() that deletes files,
        // making it an arbitrary file deletion gadget (CWE-502).
        // Address encoders
        'Swift_AddressEncoder_IdnAddressEncoder',
        'Swift_AddressEncoder_Utf8AddressEncoder',
        'Swift_AddressEncoder_AutoAddressEncoder',
        // Cache and ID generation
        'Swift_KeyCache_ArrayKeyCache',
        'Swift_KeyCache_DiskKeyCache',
        'Swift_KeyCache_NullKeyCache',
        'Swift_KeyCache_SimpleKeyCacheInputStream',
        'Swift_Mime_IdGenerator',
        // Stream filters
        'Swift_StreamFilters_ByteArrayReplacementFilter',
        'Swift_StreamFilters_StringReplacementFilter',
        'Swift_StreamFilters_StringReplacementFilterFactory',
        // Third-party classes used by headers
        'DateTimeImmutable',
        'DateTime',
        'Egulias\EmailValidator\EmailValidator',
        'Egulias\EmailValidator\EmailLexer',
        'Doctrine\Common\Lexer\Token',
    ];

    /**
     * Sends messages using the given transport instance.
     *
     * @param Swift_Transport $transport        A transport instance
     * @param string[]        $failedRecipients An array of failures by-reference
     *
     * @return int The number of sent e-mail's
     */
    public function flushQueue(Swift_Transport $transport, &$failedRecipients = null)
    {
        $directoryIterator = new DirectoryIterator($this->path);

        /* Start the transport only if there are queued files to send */
        if (!$transport->isStarted()) {
            foreach ($directoryIterator as $file) {
                if ('.message' == \substr($file->getRealPath(), -8)) {
                    $transport->start();
                    break;
                }
            }
        }

        $failedRecipients = (array) $failedRecipients;
        $count            = 0;
        $time             = \time();
        foreach ($directoryIterator as $file) {
            $file = $file->getRealPath();

            if ('.message' != \substr($file, -8)) {
                continue;
            }

            /* We try a rename, it's an atomic operation, and avoid locking the file */
            if (\rename($file, $file.'.sending')) {
                try {
                    $contents = \file_get_contents($file.'.sending');
                    if (null !== $this->signingKey) {
                        $newlinePos = \strpos($contents, "\n");
                        if (false === $newlinePos) {
                            continue;
                        }
                        $storedHmac = \substr($contents, 0, $newlinePos);
                        $ser = \substr($contents, $newlinePos + 1);
                        $expectedHmac = \hash_hmac('sha256', $ser, $this->signingKey);
                        if (!\hash_equals($expectedHmac, $storedHmac)) {
                            continue;
                        }
                    } else {
                        $ser = $contents;
                    }

                    $message = @\unserialize(
                        $ser,
                        ['allowed_classes' => self::UNSERIALIZE_ALLOWED_CLASSES],
                    );

                    if (!$message instanceof Swift_Mime_SimpleMessage) {
                        continue;
                    }

                    $count += $transport->send($message, $failedRecipients);
                } catch (Throwable $e) {
                    // Catch exceptions from __wakeup() or transport failures
                    // so one bad message doesn't crash the entire queue.
                } finally {
                    if (\file_exists($file.'.sending')) {
                        \unlink($file.'.sending');
                    }
                }
            } else { // @codeCoverageIgnore
                /* This message has just been catched by another process */
                continue; // @codeCoverageIgnore
            }

            if ($this->getMessageLimit() && $count >= $this->getMessageLimit()) {
                break;
            }

            if ($this->getTimeLimit() && (\time() - $time) >= $this->getTimeLimit()) {
                break;
            }
        }

        return $count;
    }

    /**
     * Returns a random string needed to generate a fileName for the queue.
     *
     * @param int $count
     *
     * @return string
     */
    protected function getRandomString($count)
    {
        // This string MUST stay FS safe, avoid special chars
        $base   = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
        $ret    = '';
        $strlen = \strlen($base);
        for ($i = 0; $i < $count; ++$i) {
            $ret .= $base[\random_int(0, $strlen - 1)];
        }

        return $ret;
    }
}
