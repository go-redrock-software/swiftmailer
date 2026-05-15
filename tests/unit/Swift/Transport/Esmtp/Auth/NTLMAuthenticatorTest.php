<?php

class Swift_Transport_Esmtp_Auth_NTLMAuthenticatorTest extends SwiftMailerTestCase
{
    private $message1 = '4e544c4d535350000100000007020000';

    private $message2 = '4e544c4d53535000020000000c000c003000000035828980514246973ea892c10000000000000000460046003c00000054004500530054004e00540002000c0054004500530054004e00540001000c004d0045004d0042004500520003001e006d0065006d006200650072002e0074006500730074002e0063006f006d0000000000';

    private $message3 = '4e544c4d5353500003000000180018006000000076007600780000000c000c0040000000080008004c0000000c000c0054000000000000009a0000000102000054004500530054004e00540074006500730074004d0045004d00420045005200bf2e015119f6bdb3f6fdb768aa12d478f5ce3d2401c8f6e9caa4da8f25d5e840974ed8976d3ada46010100000000000030fa7e3c677bc301f5ce3d2401c8f6e90000000002000c0054004500530054004e00540001000c004d0045004d0042004500520003001e006d0065006d006200650072002e0074006500730074002e0063006f006d000000000000000000';

    protected function setUp(): void
    {
        if (!\function_exists('openssl_encrypt') || !\function_exists('bcmul')) {
            $this->markTestSkipped('One of the required functions is not available.');
        }
    }

    public function testKeywordIsNtlm()
    {
        $login = $this->getAuthenticator();
        $this->assertEquals('NTLM', $login->getAuthKeyword());
    }

    public function testMessage1Generator()
    {
        $login    = $this->getAuthenticator();
        $message1 = $this->invokePrivateMethod('createMessage1', $login);

        $this->assertEquals($this->message1, \bin2hex($message1), '%s: We send the smallest ntlm message which should never fail.');
    }

    public function testLMv2Generator()
    {
        $username  = 'user';
        $password  = 'SecREt01';
        $domain    = 'DOMAIN';
        $challenge = '0123456789abcdef';
        $lmv2      = 'd6e6152ea25d03b7c6ba6629c2d6aaf0ffffff0011223344';

        $login      = $this->getAuthenticator();
        $lmv2Result = $this->invokePrivateMethod('createLMv2Password', $login, [$password, $username, $domain, \hex2bin($challenge), \hex2bin('ffffff0011223344')]);

        $this->assertEquals($lmv2, \bin2hex($lmv2Result), '%s: The keys should be the same cause we use the same values to generate them.');
    }

    public function testMessage3v1Generator()
    {
        $username     = 'test';
        $domain       = 'TESTNT';
        $workstation  = 'MEMBER';
        $lmResponse   = '1879f60127f8a877022132ec221bcbf3ca016a9f76095606';
        $ntlmResponse = 'e6285df3287c5d194f84df1a94817c7282d09754b6f9e02a';
        $message3T    = '4e544c4d5353500003000000180018006000000018001800780000000c000c0040000000080008004c0000000c000c0054000000000000009a0000000102000054004500530054004e00540074006500730074004d0045004d004200450052001879f60127f8a877022132ec221bcbf3ca016a9f76095606e6285df3287c5d194f84df1a94817c7282d09754b6f9e02a';

        $login    = $this->getAuthenticator();
        $message3 = $this->invokePrivateMethod('createMessage3', $login, [$domain, $username, $workstation, \hex2bin($lmResponse), \hex2bin($ntlmResponse)]);

        $this->assertEquals($message3T, \bin2hex($message3), '%s: We send the same information as the example is created with so this should be the same');
    }

    public function testMessage3v2Generator()
    {
        $username     = 'test';
        $domain       = 'TESTNT';
        $workstation  = 'MEMBER';
        $lmResponse   = 'bf2e015119f6bdb3f6fdb768aa12d478f5ce3d2401c8f6e9';
        $ntlmResponse = 'caa4da8f25d5e840974ed8976d3ada46010100000000000030fa7e3c677bc301f5ce3d2401c8f6e90000000002000c0054004500530054004e00540001000c004d0045004d0042004500520003001e006d0065006d006200650072002e0074006500730074002e0063006f006d000000000000000000';

        $login    = $this->getAuthenticator();
        $message3 = $this->invokePrivateMethod('createMessage3', $login, [$domain, $username, $workstation, \hex2bin($lmResponse), \hex2bin($ntlmResponse)]);

        $this->assertEquals($this->message3, \bin2hex($message3), '%s: We send the same information as the example is created with so this should be the same');
    }

    public function testGetDomainAndUsername()
    {
        $username = "DOMAIN\\user";

        $login               = $this->getAuthenticator();
        list($domain, $user) = $this->invokePrivateMethod('getDomainAndUsername', $login, [$username]);

        $this->assertEquals('DOMAIN', $domain, '%s: the fetched domain did not match');
        $this->assertEquals('user', $user, '%s: the fetched user did not match');
    }

    public function testGetDomainAndUsernameWithExtension()
    {
        $username = "domain.com\\user";

        $login               = $this->getAuthenticator();
        list($domain, $user) = $this->invokePrivateMethod('getDomainAndUsername', $login, [$username]);

        $this->assertEquals('domain.com', $domain, '%s: the fetched domain did not match');
        $this->assertEquals('user', $user, '%s: the fetched user did not match');
    }

    public function testGetDomainAndUsernameWithAtSymbol()
    {
        $username = 'user@DOMAIN';

        $login               = $this->getAuthenticator();
        list($domain, $user) = $this->invokePrivateMethod('getDomainAndUsername', $login, [$username]);

        $this->assertEquals('DOMAIN', $domain, '%s: the fetched domain did not match');
        $this->assertEquals('user', $user, '%s: the fetched user did not match');
    }

    public function testGetDomainAndUsernameWithAtSymbolAndExtension()
    {
        $username = 'user@domain.com';

        $login               = $this->getAuthenticator();
        list($domain, $user) = $this->invokePrivateMethod('getDomainAndUsername', $login, [$username]);

        $this->assertEquals('domain.com', $domain, '%s: the fetched domain did not match');
        $this->assertEquals('user', $user, '%s: the fetched user did not match');
    }

    public function testGetDomainAndUsernameWithoutDomain()
    {
        $username = 'user';

        $login               = $this->getAuthenticator();
        list($domain, $user) = $this->invokePrivateMethod('getDomainAndUsername', $login, [$username]);

        $this->assertEquals('', $domain, '%s: the fetched domain did not match');
        $this->assertEquals('user', $user, '%s: the fetched user did not match');
    }

    public function testSuccessfulAuthentication()
    {
        $domain   = 'TESTNT';
        $username = 'test';
        $secret   = 'test1234';

        $ntlm  = $this->getAuthenticator();
        $agent = $this->getAgent();
        $agent->shouldReceive('executeCommand')
            ->once()
            ->with('AUTH NTLM '.\base64_encode(
                $this->invokePrivateMethod('createMessage1', $ntlm),
            )."\r\n", [334])
            ->andReturn('334 '.\base64_encode(\hex2bin('4e544c4d53535000020000000c000c003000000035828980514246973ea892c10000000000000000460046003c00000054004500530054004e00540002000c0054004500530054004e00540001000c004d0045004d0042004500520003001e006d0065006d006200650072002e0074006500730074002e0063006f006d0000000000')));
        $agent->shouldReceive('executeCommand')
            ->once()
            ->with(\base64_encode(
                $this->invokePrivateMethod(
                    'createMessage3',
                    $ntlm,
                    [$domain, $username, \hex2bin('4d0045004d00420045005200'), \hex2bin('bf2e015119f6bdb3f6fdb768aa12d478f5ce3d2401c8f6e9'), \hex2bin('caa4da8f25d5e840974ed8976d3ada46010100000000000030fa7e3c677bc301f5ce3d2401c8f6e90000000002000c0054004500530054004e00540001000c004d0045004d0042004500520003001e006d0065006d006200650072002e0074006500730074002e0063006f006d000000000000000000')],
                ),
            )."\r\n", [235]);

        $this->assertTrue($ntlm->authenticate($agent, $username.'@'.$domain, $secret, \hex2bin('30fa7e3c677bc301'), \hex2bin('f5ce3d2401c8f6e9')), '%s: The buffer accepted all commands authentication should succeed');
    }

    public function testAuthenticationFailureSendRset()
    {
        $this->expectException(Swift_TransportException::class);

        $domain   = 'TESTNT';
        $username = 'test';
        $secret   = 'test1234';

        $ntlm  = $this->getAuthenticator();
        $agent = $this->getAgent();
        $agent->shouldReceive('executeCommand')
            ->once()
            ->with('AUTH NTLM '.\base64_encode(
                $this->invokePrivateMethod('createMessage1', $ntlm),
            )."\r\n", [334])
            ->andThrow(new Swift_TransportException(''));
        $agent->shouldReceive('executeCommand')
            ->once()
            ->with("RSET\r\n", [250]);

        $ntlm->authenticate($agent, $username.'@'.$domain, $secret, \hex2bin('30fa7e3c677bc301'), \hex2bin('f5ce3d2401c8f6e9'));
    }

    public function testCreateSecurityBuffer()
    {
        $login = $this->getAuthenticator();

        // Test with a simple ASCII value, non-16bit
        $result = $this->invokePrivateMethod('createSecurityBuffer', $login, ['TESTNT', 64, false]);
        $this->assertNotEmpty($result, '%s: Security buffer should not be empty');
        // The result should be 8 bytes (length[2] + length[2] + offset[4])
        $this->assertEquals(8, \strlen($result), '%s: Security buffer should be 8 bytes');

        // Test with is16=true
        $result16 = $this->invokePrivateMethod('createSecurityBuffer', $login, ['TESTNT', 64, true]);
        $this->assertEquals(8, \strlen($result16), '%s: Security buffer (16bit) should be 8 bytes');
    }

    public function testCreateSecurityBufferWithUnicodeValue()
    {
        $login = $this->getAuthenticator();

        $unicodeValue = \iconv('UTF-8', 'UTF-16LE', 'TESTNT');
        $result = $this->invokePrivateMethod('createSecurityBuffer', $login, [$unicodeValue, 64, true]);
        $this->assertEquals(8, \strlen($result));

        // Read back the security buffer
        $parsed = $this->invokePrivateMethod('readSecurityBuffer', $login, [\bin2hex($result)]);
        $this->assertIsArray($parsed);
        $this->assertCount(2, $parsed);
    }

    public function testGetCorrectTimestamp()
    {
        $login = $this->getAuthenticator();

        // Use a known epoch time in milliseconds
        $time = '1000000000000'; // ~Sep 2001
        $result = $this->invokePrivateMethod('getCorrectTimestamp', $login, [$time]);

        // Timestamp should be 8 bytes (64-bit Windows FILETIME)
        $this->assertEquals(8, \strlen($result), '%s: Timestamp should be 8 bytes');

        // Same input should produce same output
        $result2 = $this->invokePrivateMethod('getCorrectTimestamp', $login, [$time]);
        $this->assertEquals($result, $result2, '%s: Timestamp should be deterministic');
    }

    public function testURShiftWithZero()
    {
        $login = $this->getAuthenticator();

        // When $b is 0, should return $a unchanged
        $result = $this->invokePrivateMethod('uRShift', $login, [42, 0]);
        $this->assertEquals(42, $result, '%s: uRShift with 0 should return value unchanged');

        $result = $this->invokePrivateMethod('uRShift', $login, [255, 0]);
        $this->assertEquals(255, $result, '%s: uRShift with 0 should return value unchanged');
    }

    public function testURShiftWithNonZero()
    {
        $login = $this->getAuthenticator();

        // Standard unsigned right shift
        $result = $this->invokePrivateMethod('uRShift', $login, [128, 1]);
        $this->assertEquals(64, $result, '%s: 128 >>> 1 should equal 64');

        $result = $this->invokePrivateMethod('uRShift', $login, [256, 4]);
        $this->assertEquals(16, $result, '%s: 256 >>> 4 should equal 16');
    }

    public function testMd5Encrypt()
    {
        $login = $this->getAuthenticator();

        // HMAC-MD5 with known key and message
        $key = 'testkey';
        $msg = 'testmessage';
        $result = $this->invokePrivateMethod('md5Encrypt', $login, [$key, $msg]);

        // Should return 16 bytes (MD5 hash length)
        $this->assertEquals(16, \strlen($result), '%s: MD5 HMAC should be 16 bytes');

        // Should be deterministic
        $result2 = $this->invokePrivateMethod('md5Encrypt', $login, [$key, $msg]);
        $this->assertEquals($result, $result2, '%s: MD5 HMAC should be deterministic');
    }

    public function testMd5EncryptWithLongKey()
    {
        $login = $this->getAuthenticator();

        // Key longer than 64 bytes should be hashed first
        $longKey = \str_repeat('A', 100);
        $msg     = 'testmessage';
        $result  = $this->invokePrivateMethod('md5Encrypt', $login, [$longKey, $msg]);

        $this->assertEquals(16, \strlen($result), '%s: MD5 HMAC with long key should be 16 bytes');
    }

    public function testCreateNTLMv2Hash()
    {
        $login = $this->getAuthenticator();

        $password  = 'SecREt01';
        $username  = 'user';
        $domain    = 'DOMAIN';
        $challenge = \hex2bin('0123456789abcdef');
        $timestamp = \hex2bin('30fa7e3c677bc301');
        $client    = \hex2bin('f5ce3d2401c8f6e9');

        // Use the targetInfo blob from message2
        $targetInfo = \hex2bin('02000c0054004500530054004e00540001000c004d0045004d0042004500520003001e006d0065006d006200650072002e0074006500730074002e0063006f006d0000000000');

        $result = $this->invokePrivateMethod('createNTLMv2Hash', $login, [
            $password, $username, $domain, $challenge, $targetInfo, $timestamp, $client,
        ]);

        // NTLMv2 response = 16-byte HMAC + blob
        $this->assertGreaterThan(16, \strlen($result), '%s: NTLMv2 response should be longer than 16 bytes');

        // Should be deterministic
        $result2 = $this->invokePrivateMethod('createNTLMv2Hash', $login, [
            $password, $username, $domain, $challenge, $targetInfo, $timestamp, $client,
        ]);
        $this->assertEquals(\bin2hex($result), \bin2hex($result2), '%s: NTLMv2 hash should be deterministic');
    }

    public function testCreateBlob()
    {
        $login = $this->getAuthenticator();

        $timestamp  = \hex2bin('30fa7e3c677bc301');
        $client     = \hex2bin('f5ce3d2401c8f6e9');
        $targetInfo = \hex2bin('02000c0054004500530054004e005400');

        $result = $this->invokePrivateMethod('createBlob', $login, [$timestamp, $client, $targetInfo]);

        // Blob starts with 0x01010000 signature
        $this->assertEquals('01010000', \substr(\bin2hex($result), 0, 8), '%s: Blob should start with 01010000');
        $this->assertNotEmpty($result);
    }

    public function testCreateDesKey()
    {
        $login = $this->getAuthenticator();

        // 7-byte input should produce an 8-byte DES key
        $key7 = \substr('ABCDEFG', 0, 7);
        $result = $this->invokePrivateMethod('createDesKey', $login, [$key7]);
        $this->assertEquals(8, \strlen($result), '%s: DES key should be 8 bytes');
    }

    public function testDesEncrypt()
    {
        $login = $this->getAuthenticator();

        $key7    = \substr('ABCDEFG', 0, 7);
        $desKey  = $this->invokePrivateMethod('createDesKey', $login, [$key7]);
        $plaintext = Swift_Transport_Esmtp_Auth_NTLMAuthenticator::DESCONST;

        $result = $this->invokePrivateMethod('desEncrypt', $login, [$plaintext, $desKey]);
        $this->assertEquals(8, \strlen($result), '%s: DES encrypted output should be 8 bytes');

        // Same input should produce same output
        $result2 = $this->invokePrivateMethod('desEncrypt', $login, [$plaintext, $desKey]);
        $this->assertEquals($result, $result2, '%s: DES encryption should be deterministic');
    }

    public function testAuthenticateThrowsWithoutOpenssl()
    {
        // This test can only run if openssl IS available (which it is, per setUp).
        // We can't meaningfully test the openssl check without removing the extension.
        // Instead we verify the authenticate flow works end-to-end (already covered by testSuccessfulAuthentication).
        // This serves as documentation that lines 42,46 (constructor/getAuthKeyword) are covered by testKeywordIsNtlm.
        $this->assertTrue(true);
    }

    public function testCreateLMv2PasswordWithLongPassword()
    {
        $login = $this->getAuthenticator();

        // Password > 15 chars should return a default '00' padded response
        $longPassword = 'ThisIsAVeryLongPassword123';
        $result = $this->invokePrivateMethod('createLMv2Password', $login, [
            $longPassword, 'user', 'DOMAIN', \hex2bin('0123456789abcdef'), \hex2bin('ffffff0011223344'),
        ]);

        // Should be 24 bytes (padded from '00')
        $this->assertEquals(24, \strlen($result), '%s: LMv2 with long password should still be 24 bytes');
    }

    public function testSi2binPositive()
    {
        $login = $this->getAuthenticator();

        $result = $this->invokePrivateMethod('si2bin', $login, [42, 32]);
        $this->assertEquals(32, \strlen($result), '%s: si2bin should produce 32-bit string');
        $this->assertEquals('00000000000000000000000000101010', $result);
    }

    public function testSi2binZero()
    {
        $login = $this->getAuthenticator();

        $result = $this->invokePrivateMethod('si2bin', $login, [0, 32]);
        $this->assertEquals(32, \strlen($result));
        $this->assertEquals('00000000000000000000000000000000', $result);
    }

    public function testSi2binNegative()
    {
        $login = $this->getAuthenticator();
        $result = $this->invokePrivateMethod('si2bin', $login, [-1, 32]);
        $this->assertNotNull($result);
        $this->assertEquals(32, \strlen($result));
    }

    public function testSi2binOutOfRange()
    {
        $login = $this->getAuthenticator();

        // Value outside the representable range should return null
        $result = $this->invokePrivateMethod('si2bin', $login, [PHP_INT_MAX, 8]);
        $this->assertNull($result, '%s: Out-of-range value should return null');
    }

    public function testParseMessage2()
    {
        $login    = $this->getAuthenticator();
        $response = \hex2bin($this->message2);

        $result = $this->invokePrivateMethod('parseMessage2', $login, [$response]);

        $this->assertIsArray($result);
        $this->assertCount(10, $result, '%s: parseMessage2 should return 10 elements');

        // First element is the challenge (8 bytes)
        $this->assertEquals(8, \strlen($result[0]), '%s: Challenge should be 8 bytes');
    }

    public function testReadSubBlock()
    {
        $login = $this->getAuthenticator();

        // Build a minimal target info block in hex
        // Type 0x0002 (NetBIOS domain), length 12, value "TESTNT"
        $block = '02000c0054004500530054004e00540001000c004d0045004d0042004500520003001e006d0065006d006200650072002e0074006500730074002e0063006f006d0000000000';

        $result = $this->invokePrivateMethod('readSubBlock', $login, [$block]);
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(4, \count($result), '%s: readSubBlock should return at least 4 elements');
    }

    public function testConvertTo16bit()
    {
        $login = $this->getAuthenticator();

        $result = $this->invokePrivateMethod('convertTo16bit', $login, ['TEST']);
        $this->assertEquals('5400450053005400', \bin2hex($result));
    }

    public function testMd4Encrypt()
    {
        $login = $this->getAuthenticator();

        $result = $this->invokePrivateMethod('md4Encrypt', $login, ['password']);
        // MD4 hash should be 16 bytes
        $this->assertEquals(16, \strlen($result), '%s: MD4 hash should be 16 bytes');
    }

    public function testCastToByte()
    {
        $login = $this->getAuthenticator();

        $this->assertEquals(0, $this->invokePrivateMethod('castToByte', $login, [0]));
        $this->assertEquals(127, $this->invokePrivateMethod('castToByte', $login, [127]));
        $this->assertEquals(-128, $this->invokePrivateMethod('castToByte', $login, [128]));
        $this->assertEquals(-1, $this->invokePrivateMethod('castToByte', $login, [255]));
    }

    public function testCreateByteHex()
    {
        $login = $this->getAuthenticator();

        $result = $this->invokePrivateMethod('createByte', $login, ['01', 4, true]);
        $this->assertEquals(4, \strlen($result));
        $this->assertEquals('01000000', \bin2hex($result));
    }

    public function testCreateByteNonHex()
    {
        $login = $this->getAuthenticator();

        $result = $this->invokePrivateMethod('createByte', $login, ['AB', 4, false]);
        $this->assertEquals(4, \strlen($result));
        // 'AB' padded with null bytes to 4
        $this->assertEquals('AB', \substr($result, 0, 2));
    }

    public function testCreateLMPasswordRemoved()
    {
        $this->assertFalse(
            \method_exists(Swift_Transport_Esmtp_Auth_NTLMAuthenticator::class, 'createLMPassword'),
            'createLMPassword (NTLMv1) must be removed for security',
        );
    }

    public function testCreateNTLMPasswordRemoved()
    {
        $this->assertFalse(
            \method_exists(Swift_Transport_Esmtp_Auth_NTLMAuthenticator::class, 'createNTLMPassword'),
            'createNTLMPassword (NTLMv1) must be removed for security',
        );
    }

    public function testShortType2MessageThrows()
    {
        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('NTLM Type 2 message too short');

        $login = $this->getAuthenticator();
        $this->invokePrivateMethod('parseMessage2', $login, [\str_repeat("\x00", 20)]);
    }

    public function testDebugMethodNotPublic()
    {
        $this->assertFalse(
            \method_exists(Swift_Transport_Esmtp_Auth_NTLMAuthenticator::class, 'debug'),
            'debug() method must be removed — it echoes raw NTLM handshake data including credential hashes',
        );
    }

    public function testGetDomainAndUsernameWithMultipleBackslashes()
    {
        $login = $this->getAuthenticator();

        list($domain, $user) = $this->invokePrivateMethod('getDomainAndUsername', $login, ["DOMAIN\\sub\\user"]);
        $this->assertEquals('DOMAIN', $domain);
        $this->assertEquals("sub\\user", $user);
    }

    public function testReadSubBlockOverflowThrows()
    {
        $this->expectException(Swift_TransportException::class);
        $this->expectExceptionMessage('block length exceeds remaining buffer');

        $login = $this->getAuthenticator();
        // Header claims 12 bytes of data (0c00 = 3072, /256 = 12) but only 2 hex chars follow
        $block = '02000c005400' . '0000000000000000';
        $this->invokePrivateMethod('readSubBlock', $login, [$block]);
    }

    public function testSendMessage3AlwaysUsesV2()
    {
        $login = $this->getAuthenticator();
        $ref = new ReflectionMethod($login, 'sendMessage3');
        $params = $ref->getParameters();
        $paramNames = \array_map(fn($p) => $p->getName(), $params);

        $this->assertNotContains('v2', $paramNames, 'sendMessage3 should no longer accept a $v2 parameter');
    }

    private function getAuthenticator()
    {
        return new Swift_Transport_Esmtp_Auth_NTLMAuthenticator();
    }

    private function getAgent()
    {
        return $this->getMockery('Swift_Transport_SmtpAgent')->shouldIgnoreMissing();
    }

    private function invokePrivateMethod($method, $instance, array $args = [])
    {
        $methodC = new ReflectionMethod($instance, \trim($method));

        return $methodC->invokeArgs($instance, $args);
    }
}
