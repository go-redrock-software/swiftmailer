<?php

/*
 * This file is part of SwiftMailer.
 * (c) 2004-2009 Chris Corbyn
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

//Define constants here
const CONNECTION_ENCRYPTION_MODE_STARTTLS = 'tls';
const CONNECTION_ENCRYPTION_MODE_TLS = 'ssl';
const CONNECTION_ENCRYPTION_MODE_NONE = null;


require __DIR__.'/classes/Swift.php';

Swift::registerAutoload(function () {
    // Load in dependency maps
    require __DIR__.'/dependency_maps/cache_deps.php';
    require __DIR__.'/dependency_maps/mime_deps.php';
    require __DIR__.'/dependency_maps/message_deps.php';
    require __DIR__.'/dependency_maps/transport_deps.php';

    // Load in global library preferences
    require __DIR__.'/preferences.php';
});
