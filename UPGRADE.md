!!!IMPORTANT!!!

The following upgrading instructions are cumulative. That is, if you want to upgrade from version A to version C and there is version B between A and C, you need to follow the instructions for both A and B.

## Upgrade from SoftMocks 2.0.6

- update php to at least `7.0`
- update `nikic/php-parser` to `^4.3.0`, so if you use it, then update
- install ext-json

## Upgrade from SoftMocks 1.3.5

- Class constants redefining logic was changed (see [CHANGELOG.md](CHANGELOG.md)). Check class constants redefining usages, that it's working.
- Deprecated class `\QA\SoftMocks` was removed. Use `\Badoo\SoftMocks` instead of it.
- Now \Badoo\SoftMocks::rewrite() can throw \RuntimeException(). Check that you don't pass empty or not exists file to this method. Catch exception if need.

## Upgrade from SoftMocks 1.1.2

- Class `\QA\SoftMocks` marked as deprecated.
- All methods of `\QA\SoftMocks` moved to `\Badoo\SoftMocks`.

## Upgrade from SoftMocks 1.1.1

Upgrading from 1.1.1 to 1.1.2 does not require any changes.

## Upgrade from SoftMocks 1.1.0

Upgrading from 1.1.0 to 1.1.1 does not require any changes.

## Upgrade from SoftMocks 1.0

- Method `\QA\SoftMocks::setPhpunitPath()` marked as deprecated and will be removed in feature versions. Use `\QA\SoftMocks::addIgnorePath()` instead of it.
- Method `\QA\SoftMocks::setPhpParserPath()` marked as deprecated and will be removed in feature versions. Use `\QA\SoftMocks::addIgnorePath()` instead of it.
- Method `\QA\SoftMocks::getMockCode()` was removed.
- Method `\QA\SoftMocks::generateCode()` was marked as private.
- Parameter $strict for method `\QA\SoftMocks::redefineMethod()` was removed. Now only strict mode available. You should check using this parameter.
