<?php

class Swift_ExceptionCauseTest extends PHPUnit\Framework\TestCase
{
    /** @dataProvider causes */
    public function testConstructorsRetainTheExactThrowableCause(string $class, string $kind): void
    {
        $cause = match ($kind) {
            'Exception'  => new Exception('fixture legacy cause'),
            'TypeError'  => new TypeError('fixture type cause'),
            'ParseError' => new ParseError('fixture parse cause'),
        };
        $exception = new $class('fixture outer message', 17, $cause);
        $this->assertSame($cause, $exception->getPrevious());
        $this->assertSame('fixture outer message', $exception->getMessage());
        $this->assertSame(17, $exception->getCode());
    }

    public static function causes(): iterable
    {
        foreach ([Swift_SwiftException::class, Swift_IoException::class, Swift_TransportException::class, Swift\SwiftException::class, Swift\IoException::class, Swift\TransportException::class] as $class) {
            foreach (['Exception', 'TypeError', 'ParseError'] as $kind) {
                yield $class.' / '.$kind => [$class, $kind];
            }
        }
    }

    public function testErrorCauseRetainsAMarkerAndStaysNonRetryable(): void
    {
        $marker    = new Swift_Transport_NonRetryableException('fixture terminal failure');
        $error     = new TypeError('fixture callback failure', 0, $marker);
        $exception = new Swift_TransportException('fixture SDK wrapper', 0, $error);
        $this->assertSame($error, $exception->getPrevious());
        $this->assertSame($marker, $exception->getPrevious()->getPrevious());
        $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($exception));
    }

    /** @dataProvider permanentErrors */
    public function testPhpErrorsRemainPermanentThroughExceptionWrappers(string $class, int $code): void
    {
        $error     = new $class('fixture programming error');
        $exception = new Swift_TransportException('connection reset: service unavailable', $code, new RuntimeException('fixture SDK wrapper', 0, $error));
        $this->assertFalse((new Swift_Transport_DefaultRetryClassifier())->isRetryable($exception));
    }

    public static function permanentErrors(): iterable
    {
        foreach ([TypeError::class, ParseError::class, AssertionError::class] as $class) {
            foreach ([0, 429, 503] as $code) {
                yield $class.' / '.$code => [$class, $code];
            }
        }
    }
}
