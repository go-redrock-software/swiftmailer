<?php

$config = \json_decode('cli' === \PHP_SAPI ? $argv[1] : $_SERVER['SWIFT_STREAM_CONFIG'], true, flags: \JSON_THROW_ON_ERROR);
\spl_autoload_register(static function (string $class) use ($config): void {
    // Register the actual selected implementation before eager Composer aliases.
    // During Infection this is the mutant, not the original repository file.
    if ('Swift_Plugins_Reporters_HtmlReporter' === $class) {
        require $config['implementation'];
    }
});
require $config['autoload'];
if ($config['buffered']) {
    \ob_start();
}
(new Swift_Plugins_Reporters_HtmlReporter())->notify(new Swift_Message('Streaming fixture'), 'stream<&"@example.test', $config['result']);
$gate = \stream_socket_client('unix://'.$config['control'], $code, $error, 5);
if (false === $gate) {
    throw new RuntimeException('Unable to connect to the test control socket.');
}
\stream_set_timeout($gate, 5);
\fwrite($gate, \json_encode([
    'sapi'           => \PHP_SAPI,
    'version'        => \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION,
    'level'          => \ob_get_level(),
    'implementation' => (new ReflectionClass(Swift_Plugins_Reporters_HtmlReporter::class))->getFileName(),
], \JSON_THROW_ON_ERROR)."\n");
if ('r' !== \fread($gate, 1)) {
    throw new RuntimeException('The test did not release the completed notification.');
}
\fclose($gate);
if ($config['buffered']) {
    \ob_end_flush();
}
