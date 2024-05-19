<?php

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * A base test case with some custom expectations.
 *
 * @author Rouven Weßling
 */
class SwiftMailerTestCase extends PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    public static function regExp($pattern)
    {
        self::validatePattern($pattern);

        return new PHPUnit\Framework\Constraint\RegularExpression($pattern);
    }

    private static function validatePattern($pattern)
    {
        if (!\is_string($pattern)) {
            throw new InvalidArgumentException(\sprintf('Argument 1 passed must be of the type string, %s given', \gettype($pattern)));
        }
    }

    public function assertIdenticalBinary($expected, $actual, $message = '')
    {
        $constraint = new IdenticalBinaryConstraint($expected);
        self::assertThat($actual, $constraint, $message);
    }

    protected function getMockery($class)
    {
        return Mockery::mock($class);
    }
}
