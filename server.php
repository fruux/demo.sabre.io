<?php

ini_set('display_errors','1');

if (!file_exists('vendor/autoload.php')) {
    throw new \Exception('Please run "composer install"');
}



$pdo = new PDO('sqlite:data/db.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Creating database.
if (!is_writable('data/db.sqlite')) {
    throw new \Exception('data/db.sqlite and data/ must be writable by the server');
}


try {
    $pdo->query("SELECT * FROM calendars");
} catch (\Exception $e) {

    // Assuming tables have not been created yet.

    foreach(glob('vendor/sabre/dav/examples/sql/sqlite.*') as $sql) {

        $pdo->exec(file_get_contents($sql));

    }

    $user = $pdo->prepare('INSERT INTO users (username, digesta1) VALUES (?, ?)');
    $principal = $pdo->prepare('INSERT INTO principals (uri, email, displayname) VALUES (?, ?, ?)');
    $calendar = $pdo->prepare('INSERT INTO calendars (components) VALUES (?)');
    $calendarInstance = $pdo->prepare('INSERT INTO calendarinstances (calendarid, principaluri, access, displayname, uri) VALUES (?, ?, ?, ?, ?)');
    $addressbook = $pdo->prepare('INSERT INTO addressbooks (principaluri, displayname, uri) VALUES (?, ?, ?)');

    foreach(range(1,100) as $userid) {

        $username = 'user' . $userid;
        $principalurl = 'principals/' . $username;

        $user->execute([$username, md5($username . ':SabreDAV:password')]);
        $principal->execute([$principalurl, $username . '.test@sabre.io', 'User ' . $userid]);

        $calendar->execute(['VEVENT']);
        $lastId = $pdo->lastInsertId();
        $calendarInstance->execute([$lastId, $principalurl, 1, 'Work', 'work']);

        $calendar->execute(['VEVENT']);
        $lastId = $pdo->lastInsertId();
        $calendarInstance->execute([$lastId, $principalurl, 1, 'Home', 'home']);

        $calendar->execute(['VTODO']);
        $lastId = $pdo->lastInsertId();
        $calendarInstance->execute([$lastId, $principalurl, 1, 'Tasks', 'tasks']);

        $calendar->execute(['VJOURNAL']);
        $lastId = $pdo->lastInsertId();
        $calendarInstance->execute([$lastId, $principalurl, 1, 'Journals', 'journals']);

        $addressbook->execute([$principalurl, 'Family', 'family']);
        $addressbook->execute([$principalurl, 'Business', 'business']);

    }

}

/**
 * Mapping PHP errors to exceptions.
 *
 * While this is not strictly needed, it makes a lot of sense to do so. If an
 * E_NOTICE or anything appears in your code, this allows SabreDAV to intercept
 * the issue and send a proper response back to the client (HTTP/1.1 500).
 */
function exception_error_handler($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");

require 'vendor/autoload.php';


/**
 * The backends. Yes we do really need all of them.
 *
 * This allows any developer to subclass just any of them and hook into their
 * own backend systems.
 */
$authBackend      = new \Sabre\DAV\Auth\Backend\PDO($pdo);
$principalBackend = new \Sabre\DAVACL\PrincipalBackend\PDO($pdo);
$carddavBackend   = new \Sabre\CardDAV\Backend\PDO($pdo);
$caldavBackend    = new \Sabre\CalDAV\Backend\PDO($pdo);

/**
 * The directory tree
 *
 * Basically this is an array which contains the 'top-level' directories in the
 * WebDAV server.
 */
$nodes = [
    // /principals
    new \Sabre\CalDAV\Principal\Collection($principalBackend),
    // /calendars
    new \Sabre\CalDAV\CalendarRoot($principalBackend, $caldavBackend),
    // /addressbook
    new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
];

// The object tree needs in turn to be passed to the server class
$server = new \Sabre\DAV\Server($nodes);
if (isset($baseUri)) $server->setBaseUri($baseUri);

// Plugins
$server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend));
$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
$server->addPlugin(new \Sabre\DAV\Sync\Plugin());
$server->addPlugin(new \Sabre\DAV\Sharing\Plugin());

//ACL
$aclPlugin = new \Sabre\DAVACL\Plugin();
$aclPlugin->adminPrincipals = ['principals/admin'];
$server->addPlugin($aclPlugin);

// CalDAV plugins
$server->addPlugin(new \Sabre\CalDAV\Plugin());
$server->addPlugin(new \Sabre\CalDAV\Schedule\Plugin());
$server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
$server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());

// CardDAV plugins
$server->addPlugin(new \Sabre\CardDAV\Plugin());
$server->addPlugin(new \Sabre\CardDAV\VCFExportPlugin());

// Sharing
$server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
$server->addPlugin(new \Sabre\CalDAV\Sharing\Plugin());


// And off we go!
$server->exec();
