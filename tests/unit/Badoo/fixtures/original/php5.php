<?php
/**
 * This file contains php5 code
 * And comments
 */

// Functions calls
error_reporting(E_ALL);
ini_set('display_errors', true);

// Conditions
if (!empty($_SERVER['HTTP_ORIG_DOMAIN'])) {
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_ORIG_DOMAIN'];

    // Various strings
    $header = 'HTTP/1.1 301 Moved Permanently';
    $redirect_address = 'https://badoo.com';

    header($header);
    header('Location: ' . $redirect_address);

    echo <<<END
<html>
 <head>
  <title>Status 301 - Moved Permanently</title>
  <meta http-equiv="Refresh" content="0; url=$redirect_address">
 </head>
 <body bgcolor="#ffffff" text="#000000" link="#ff0000" alink="#ff0000" vlink="#ff0000">
 Document permanently moved: <a href="$redirect_address">$redirect_address</a>
 </body>
</html>
END;
    exit;
} elseif (false) {
    echo "Never1!\n";
} else if (false) {
    echo "Never2!\n";
} else {
    echo "Always!\n";
}

if (true) echo "Always!\n";
else echo "Never!\n";

$developer = 'somebody';

$_SERVER['developer'] = $developer;

@ini_set('error_log', '/local/logs/php/badoo-' . $developer . '.log');

define('PHPWEB_PATH_PHOTOS', '/home/' . $developer . '/photos');

$old_umask = umask(0);
$create_dirs = [
    PHPWEB_PATH_PHOTOS
];

include_once 'debug.php';

isRobotDebug();
isCssDebug();
if (isCompressHTMLDebug()) define('COMPRESS_HTML_DEBUG', true);

while (false) echo "never!\n";

while (false) {
    echo "never!\n";
}

do echo "always!\n";
while (false);
do {
    echo "always!\n";
} while (false);

$array = [
    1,
    replaceSomething('string'),
    3,
];
for ($i = 0; $i < \count($array); $i++) {
    $value = $array[$i];
}

foreach ($array as $key => $value) {
    echo "{$key}: {$value}\n";
}

$switch = 5;

switch ($switch) {
    case 4:
        echo "switch 4\n";
        break;

    case 5:
    case 6:
        echo "switch 5|6\n";
        break;

    default:
        echo "switch default\n";
}

function replaceSomething($string)
{
    // Comment
    /* Comment */
    return str_replace('something', 'somebody', $string);
}

class SomeClass
{
    const VALUE = 1;

    public $a = 1;

    public static function getValue()
    {
        return self::VALUE;
    }

    public function method($string)
    {
        return self::methodSelf($string);
    }

    protected static function methodSelf($string)
    {
        return replaceSomething($string);
    }
}
