<?php

// Isolated loopback protocol fixture; never delivers a message.
$server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (false === $server) {
    throw new RuntimeException('Cannot create loopback fixture: '.$error);
}
echo \stream_socket_get_name($server, false)."\n";
\fflush(\STDOUT);
$connection = \stream_socket_accept($server, 5);
if (false === $connection) {
    throw new RuntimeException('No loopback client connected.');
}
\stream_set_timeout($connection, 5);
\fwrite($connection, "220 loopback.safe.test ESMTP\r\n");
$commands = [];
$stage    = null;
while (false !== $line = \fgets($connection)) {
    $command    = \rtrim($line, "\r\n");
    $commands[] = $command;
    if (\str_starts_with($command, 'EHLO ')) {
        if ('hold' === ($argv[1] ?? '')) {
            // A broken/default 30-second client timeout sees EOF after this bound.
            $read  = [$connection];
            $write = $except = [];
            \stream_select($read, $write, $except, 3);
            break;
        }
        \fwrite($connection, "250-loopback.safe.test\r\n250 AUTH PLAIN LOGIN XOAUTH2\r\n");
    } elseif ('AUTH LOGIN' === $command) {
        $stage = 'username';
        \fwrite($connection, "334 VXNlcm5hbWU6\r\n");
    } elseif ('username' === $stage) {
        $stage = 'password';
        \fwrite($connection, "334 UGFzc3dvcmQ6\r\n");
    } elseif ('password' === $stage || \str_starts_with($command, 'AUTH PLAIN ') || \str_starts_with($command, 'AUTH XOAUTH2 ')) {
        $stage = null;
        \fwrite($connection, "235 2.7.0 Authenticated\r\n");
    } elseif ('QUIT' === $command) {
        \fwrite($connection, "221 Bye\r\n");
        break;
    } else {
        \fwrite($connection, "500 Unexpected fixture command\r\n");
    }
}
\fclose($connection);
\fclose($server);
echo \json_encode($commands, \JSON_THROW_ON_ERROR)."\n";
