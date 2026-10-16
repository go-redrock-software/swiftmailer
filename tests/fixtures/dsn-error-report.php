<?php

// Load reflected implementations first so mutation runs exercise the mutated file.
require $argv[1];
require $argv[2];
require $argv[3];

$input = \json_decode(\stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
try {
    match ($input['entry']) {
        'factory'         => (new Swift_Transport_DsnTransportFactory())->fromDsnString($input['dsn']),
        'factory-alias'   => (new Swift\Transport\DsnTransportFactory())->fromDsnString($input['dsn']),
        'mailer'          => Swift::createTransportFromDsn($input['dsn']),
        'metadata-static' => Swift_Dsn_Configuration::parse($input['dsn']),
        'metadata-new'    => new Swift_Dsn_Configuration($input['dsn']),
        'metadata-alias'  => Swift\Dsn\Configuration::parse($input['dsn']),
    };
    echo \json_encode(['accepted' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $representations = [
        'message' => $error->getMessage(),
        'string'  => (string) $error,
        'trace'   => $error->getTraceAsString(),
        'object'  => \print_r($error, true),
    ];
    $leaks = [];
    foreach ($representations as $name => $text) {
        $leaks[$name] = \str_contains($text, 'PRIVATE-') || \str_contains($text, '%50%52%49%56%41%54%45-');
    }
    echo \json_encode([
        'accepted'         => false,
        'class'            => \get_class($error),
        'diagnostic'       => \str_contains($error->getMessage(), 'Invalid DSN syntax'),
        'code'             => $error->getCode(),
        'previous'         => null !== $error->getPrevious(),
        'raw_dsn_accessor' => \method_exists($error, 'getDsn'),
        'leaks'            => $leaks,
    ], JSON_THROW_ON_ERROR);
}
