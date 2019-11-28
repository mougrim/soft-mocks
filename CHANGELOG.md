# SoftMocks ChangeLog

## master

There are next changes:

- add support phpunit 7.x and 8.x
- update `nikic/php-parser` to `^4.3.0`, so now version isn't specific
- minimum php version now is `7.0`
- now ext-json is required for get nikic/php-parser version for right rewritten files cache
- fixed error 'Unbinding $this of closure is deprecated'

## v2.0.6

There are next changes:

- fix for 2-element-array callable

## v2.0.5

There are next changes:

- fix `is_callable` method which fails on malformed arrays

## v2.0.4

There are next changes:

- make more unique rewritten files paths depends on php internals

## v2.0.3

There are next changes:

- fix `recursiveGetTraits` method which didn't work for case when one trait uses another trait

## v2.0.2

There are next changes:

- fix resolve relative file path

## v2.0.1

There are next changes:

- use mb_orig_* functions
- create directory /tmp/mocks for cache by default
- constant SOFTMOCKS_ROOT_PATH marked as deprecated, use `\Badoo\SoftMocks::setProjectPath()` instead of it
- fix create mocks cache dir race condition

## v2.0.0

There are next changes:

- fix short array destructing when some elements are absent;
- dev dependence vaimo/composer-patches was updated from 3.4.3 to 3.23.1;
- patch level for patches was provided;
- phpunit6 support was added;
- class static protected constant was fixed;
- class constants inheritance was fixed:
  - before fix:
    ```php
    class A {const NAME = 'A';}
    class B {}
    \Badoo\SoftMocks::redefineConstant(A::class . '::NAME', 'B');
    echo A::NAME . "\n"; // B
    echo B::NAME . "\n"; // A
    ```
  - after fix:
    ```php
    class A {const NAME = 'A';}
    class B {}
    \Badoo\SoftMocks::redefineConstant(A::class . '::NAME', 'B');
    echo A::NAME . "\n"; // B
    echo B::NAME . "\n"; // B
    ```
- soft-mocks init logic was moved to src/init_with_composer.php script from phpunit patch;
- methods \Badoo\SoftMocks::getRewrittenFilePath() and \Badoo\SoftMocks::getOriginalFilePath() were added;
- SOFT_MOCKS_CACHE_PATH environment variable was added for redefine default mocks cache path;
- Now \RuntimeException() isn't caught in \Badoo\SoftMocks::rewrite().

## v1.3.5

There are next changes:

- using getenv instead of $_ENV global variable;
- error "PHP Fatal error:  Class 'Symfony\Polyfill\Php70\Php70' not found" was fixed;
- use path in project for cached files path.

## v1.3.4

There are next changes:

- Support private/protected class constants;
- Using getenv instead of $_ENV global variable.

## v1.3.3

There are next changes:

- Added $variadic_params_idx (string, '' - no variadic params, otherwise - it's idx in function arguments).

## v1.3.2

There are next changes:

- Line numbering in rewritten code improved;
- Only multiline /**/ comments are present in rewritten file.

## v1.3.0

There are next changes:

- PHP 7.1 support (mostly nullable and void return type declarations);
- update nikic/php-parser to 3.0.6;
- fix bug with throwing from generators;
- added tests for constants redefine.

## v1.2.0

There are next changes:

- added Travis and Scrutinizer support;
- skipped running PHP7.0 tests on previously versions of PHP;
- changed default namespace to \Badoo. \QA namespace marked as deprecated and will be removed in 2.0.0;
- \QA\SoftMocksTraverser::$can_ref gone private, was mistakenly without scope.

## v1.1.2

There are next changes:

- vaimo/composer-patches version was fixed for prevent error 'The "badoo/soft-mocks/patches/phpunit5.x/phpunit_phpunit.patch" file could not be downloaded: failed to open stream: No such file or directory';
- load parser file was added for prevent error "Fatal error: Uncaught Error: Class 'PhpParser\NodeTraverser' not found in vendor/badoo/soft-mocks/src/QA/SoftMocks.php:1154".

## v1.1.1

There are next changes:

- nikic/php-parser was updated to 2.0.0beta1;
- using nikic/php-parser version in path to rewritten file was added;
- info how reapply patches was added.

## v1.1.0

There are next changes:

- patches for phpunit in composer.json was added;
- exact version of nikic/php-parser in composer.json was provided;
- parameter $strict for method `\QA\SoftMocks::redefineMethod()` was removed, now only strict mode available;
- redefine for built-in mocks was allowed (is activated by `\QA\SoftMocks::setRewriteInternal(true)`) [https://github.com/badoo/soft-mocks/pull/15](https://github.com/badoo/soft-mocks/pull/15), thanks [Mougrim](https://github.com/mougrim);
- null for redefined constants was allowed [https://github.com/badoo/soft-mocks/pull/11](https://github.com/badoo/soft-mocks/pull/11), thanks [Alexey Manukhin](https://github.com/axxapy);
- error "Fatal error: Couldn't find constant QA\SoftMocks::CLASS in /src/QA/SoftMocks.php on line 388" was fixed for old versions hhvm [https://github.com/badoo/soft-mocks/pull/16](https://github.com/badoo/soft-mocks/pull/16), thanks [Mougrim](https://github.com/mougrim);
- warning "PHP Warning:  array_key_exists() expects exactly 2 parameters" was fixed [https://github.com/badoo/soft-mocks/pull/14](https://github.com/badoo/soft-mocks/pull/14), thanks [Mougrim](https://github.com/mougrim);
- handle phpunit wrapped exceptions (PHPUnit_Framework_ExceptionWrapper, \PHPUnit\Framework\ExceptionWrapper);
- unit tests was added.
