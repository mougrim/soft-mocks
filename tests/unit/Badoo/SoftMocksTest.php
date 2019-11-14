<?php
/**
 * Mocks core that rewrites code
 * @author Kirill Abrosimov <k.abrosimov@corp.badoo.com>
 * @author Oleg Efimov <o.efimov@corp.badoo.com>
 * @author Rinat Akhmadeev <r.akhmadeev@corp.badoo.com>
 */
namespace Badoo\SoftMock\Tests;

class SoftMocksTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp() : void
    {
        require_once __DIR__ . '/SoftMocksTestClasses.php';
    }

    protected function tearDown() : void
    {
        \Badoo\SoftMocks::restoreAll();
        parent::tearDown();
    }

    public static function markTestSkippedForPHPVersionBelow($php_version)
    {
        if (version_compare(phpversion(), $php_version, '<')) {
            static::markTestSkipped('You PHP version do not support this, you need need at least PHP ' . $php_version);
        }
    }

    public function testParserVersion()
    {
        $composer_json = file_get_contents(__DIR__ . '/../../../composer.json');
        static::assertNotEmpty($composer_json, "Can't get content from composer.json file");
        $composer_data = json_decode($composer_json, true);
        static::assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            "Can't parse composer.json: [" . json_last_error() . '] ' . json_last_error_msg()
        );
        static::assertSame(\Badoo\SoftMocks::PARSER_VERSION, $composer_data['require']['nikic/php-parser']);
    }

    public function testExitMock()
    {
        \Badoo\SoftMocks::redefineExit(
            '',
            function ($code) {
                throw new \Exception("exit called: {$code}");
            }
        );
        try {
            $result = WithExitTestClass::doWithExit();
            $this->fail('exception expected');
        } catch (\Exception $Exception) {
            static::assertEquals('exit called: ' . (WithExitTestClass::RESULT * 10), $Exception->getMessage());
            $result = false;
        }
        static::assertFalse($result);

        \Badoo\SoftMocks::restoreExit();

        \Badoo\SoftMocks::redefineExit(
            '',
            function ($code) {
                WithExitTestClass::$exit_code = $code;
                WithExitTestClass::$exit_called = true;
            }
        );
        $result = WithExitTestClass::doWithExit();
        static::assertEquals(WithExitTestClass::RESULT, $result);
        static::assertEquals(WithExitTestClass::RESULT * 10, WithExitTestClass::$exit_code);
        static::assertTrue(WithExitTestClass::$exit_called);
        \Badoo\SoftMocks::restoreExit();
    }

    public function testRedefineConstructor()
    {
        \Badoo\SoftMocks::redefineMethod(
            ConstructTestClass::class,
            '__construct',
            '',
            '$this->constructor_params = $mm_func_args;'
        );
        $object = new ConstructTestClass(1, 2, 3);
        static::assertEquals(42, $object->doSomething());
        static::assertEquals([1, 2, 3], $object->getConstructorParams());
    }

    public function testRedefineMethod()
    {
        \Badoo\SoftMocks::redefineMethod(
            BaseInheritanceTestClass::class,
            'doSomething',
            '',
            'return 43;'
        );
        $a = new BaseInheritanceTestClass();
        static::assertEquals(43, $a->doSomething());
    }

    public function testRedefineMethodWithInheritedClasses()
    {
        \Badoo\SoftMocks::redefineMethod(
            BaseInheritanceTestClass::class,
            'doSomething',
            '',
            'return 43;'
        );
        $a = new InheritanceTestClass();
        static::assertEquals(43, $a->doSomething());
    }

    public function testRedefineMethodWithInheritedClasses2()
    {
        \Badoo\SoftMocks::redefineMethod(
            InheritanceTestClass::class,
            'doSomething',
            '',
            'return 43;'
        );
        $a = new InheritanceTestClass();
        static::assertEquals(43, $a->doSomething());
    }

    public function testParentMismatch()
    {
        \Badoo\SoftMocks::redefineMethod(
            ParentMismatchChildTestClass::class,
            'f',
            '$c',
            'return \Badoo\SoftMocks::callOriginal([\Badoo\SoftMock\Tests\ParentMismatchChildTestClass::class, "f"], [$c]) + 1;'
        );
        \Badoo\SoftMocks::redefineMethod(ParentMismatchBaseTestClass::class, 'f', '', 'return 100;');

        static::assertEquals(2, ParentMismatchChildTestClass::f(true));
        static::assertEquals(101, ParentMismatchChildTestClass::f(false));
    }

    public function testGenerators()
    {
        $Generators = new GeneratorsTestClass();
        $values = [];
        \Badoo\SoftMocks::redefineGenerator(
            GeneratorsTestClass::class,
            'yieldAb',
            [$this, 'yieldAbMock']
        );

        foreach ($Generators->yieldAb(1) as $value) {
            $values[] = $value;
        }

        static::assertEquals(
            array_values(range(0, 9)),
            $values
        );
    }

    public function yieldAbMock()
    {
        for ($i = 0; $i <= 9; $i++) {
            yield $i;
        }
    }

    public function testGeneratorsRef()
    {
        $Generators = new GeneratorsTestClass();
        $values = [];

        \Badoo\SoftMocks::redefineGenerator(
            GeneratorsTestClass::class,
            'yieldRef',
            [$this, 'yieldRefMock']
        );

        foreach ($Generators->yieldRef(10) as $value) {
            $value++;
            $values[] = $value;
        }
        unset($value);

        static::assertEquals(
            array_values(range(1, 10)),
            $values
        );

        $values = [];

        foreach ($Generators->yieldRef(10) as &$value) {
            $value++;
            $values[] = $value;
        }
        unset($value);

        static::assertEquals(
            array_values(range(1, 9, 2)),
            $values
        );
    }

    public function &yieldRefMock($num)
    {
        for ($i = 0; $i < $num; $i++) {
            yield $i;
        }
    }

    public function testDefault()
    {
        $Misc = new DefaultTestClass();
        static::assertTrue($Misc->doSomething(1, 2));

        \Badoo\SoftMocks::redefineMethod(
            DefaultTestClass::class,
            'doSomething',
            '$a, $b = 3',
            'return [$a, $b];'
        );

        static::assertEquals([1, 2], $Misc->doSomething(1, 2));
        static::assertEquals([1, 3], $Misc->doSomething(1));
    }

    public function testConstants()
    {
        static::assertEquals(1, DefaultTestClass::getValue());

        \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\DefaultTestClass::VALUE', 2);

        static::assertEquals(2, DefaultTestClass::getValue());
    }

    public function testNotRedefinedClassConstants()
    {
        try {
            ConstantRedeclareBaseTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareBaseTestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareFirstTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareFirstTestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareSecondTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareThirdTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareForthTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        static::assertSame(2, ConstantRedeclareFirstTestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareFirstTestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareFirstTestClass::getFirstStaticValue());

        static::assertSame(2, ConstantRedeclareSecondTestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getSecondSelfValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getSecondStaticValue());

        static::assertSame(4, ConstantRedeclareThirdTestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdStaticValue());

        static::assertSame(4, ConstantRedeclareForthTestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthStaticValue());
    }

    public function testBaseClassConstantRedefined()
    {
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBaseTestClass::class . '::VALUE',
            10
        );

        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstTestClass::getBaseSelfValue());
        static::assertSame(2, ConstantRedeclareFirstTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareFirstTestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareFirstTestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondTestClass::getBaseSelfValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getSecondSelfValue());
        static::assertSame(2, ConstantRedeclareSecondTestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdTestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareThirdTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthTestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareForthTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthStaticValue());
    }

    public function testFromBaseToFirstClassConstantRedefined()
    {
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBaseTestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstTestClass::class . '::VALUE',
            20
        );

        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstTestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondTestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getSecondParentValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getSecondSelfValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdTestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getSecondParentValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getSecondStaticValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthTestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getSecondParentValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getSecondStaticValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthStaticValue());
    }

    public function testFromBaseToSecondClassConstantRedefined()
    {
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBaseTestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstTestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondTestClass::class . '::VALUE',
            30
        );

        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstTestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondTestClass::getBaseSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getFirstSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getSecondSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdTestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareThirdTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareThirdTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdTestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthTestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareForthTestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareForthTestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthTestClass::getForthStaticValue());
    }

    public function testFromBaseToThirdClassConstantRedefined()
    {
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBaseTestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstTestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondTestClass::class . '::VALUE',
            30
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareThirdTestClass::class . '::VALUE',
            40
        );

        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstTestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondTestClass::getBaseSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getFirstSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getSecondSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdTestClass::getBaseSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getFirstSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareThirdTestClass::getSecondSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareThirdTestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getThirdSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthTestClass::getBaseSelfValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getFirstSelfValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareForthTestClass::getSecondSelfValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareForthTestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getThirdSelfValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getThirdStaticValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getForthParentValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getForthSelfValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getForthStaticValue());
    }

    public function testFromBaseToForthClassConstantRedefined()
    {
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBaseTestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstTestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondTestClass::class . '::VALUE',
            30
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareThirdTestClass::class . '::VALUE',
            40
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareForthTestClass::class . '::VALUE',
            50
        );

        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstTestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstTestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondTestClass::getBaseSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getFirstSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getSecondSelfValue());
        static::assertSame(30, ConstantRedeclareSecondTestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdTestClass::getBaseSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getFirstSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareThirdTestClass::getSecondSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareThirdTestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getThirdSelfValue());
        static::assertSame(40, ConstantRedeclareThirdTestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthTestClass::getBaseSelfValue());
        static::assertSame(50, ConstantRedeclareForthTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getFirstSelfValue());
        static::assertSame(50, ConstantRedeclareForthTestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthTestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareForthTestClass::getSecondSelfValue());
        static::assertSame(50, ConstantRedeclareForthTestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareForthTestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getThirdSelfValue());
        static::assertSame(50, ConstantRedeclareForthTestClass::getThirdStaticValue());
        static::assertSame(40, ConstantRedeclareForthTestClass::getForthParentValue());
        static::assertSame(50, ConstantRedeclareForthTestClass::getForthSelfValue());
        static::assertSame(50, ConstantRedeclareForthTestClass::getForthStaticValue());
    }

    public function testRedifineBaseClassConstantAndRemoveRestoreOtherClassConstants()
    {
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBaseTestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstTestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondTestClass::class . '::VALUE',
            30
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareThirdTestClass::class . '::VALUE',
            40
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareForthTestClass::class . '::VALUE',
            50
        );

        \Badoo\SoftMocks::removeConstant(ConstantRedeclareFirstTestClass::class . '::VALUE');
        \Badoo\SoftMocks::restoreConstant(ConstantRedeclareSecondTestClass::class . '::VALUE');
        \Badoo\SoftMocks::removeConstant(ConstantRedeclareThirdTestClass::class . '::VALUE');
        \Badoo\SoftMocks::restoreConstant(ConstantRedeclareForthTestClass::class . '::VALUE');

        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBaseTestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareFirstTestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getFirstStaticValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getSecondParentValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getSecondSelfValue());
        static::assertSame(10, ConstantRedeclareSecondTestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getFirstStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getSecondParentValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getSecondSelfValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getSecondStaticValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getThirdParentValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getThirdSelfValue());
        static::assertSame(10, ConstantRedeclareThirdTestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthTestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getFirstStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getSecondParentValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getSecondSelfValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getSecondStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getThirdParentValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getThirdSelfValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getThirdStaticValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getForthParentValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getForthSelfValue());
        static::assertSame(10, ConstantRedeclareForthTestClass::getForthStaticValue());
    }

    public function testRemoveConstantFromHierarchy()
    {
        \Badoo\SoftMocks::removeConstant(ConstantRedeclareFirstTestClass::class . '::VALUE');
        \Badoo\SoftMocks::removeConstant(ConstantRedeclareThirdTestClass::class . '::VALUE');

        try {
            ConstantRedeclareBaseTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareBaseTestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareFirstTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstTestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstTestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstTestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstTestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareSecondTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondTestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondTestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondTestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondTestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondTestClass::getSecondParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondTestClass::getSecondSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondTestClass::getSecondStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareThirdTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getSecondParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getSecondSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getSecondStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getThirdParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getThirdSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdTestClass::getThirdStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareForthTestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBaseTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getSecondParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getSecondSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getSecondStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getThirdParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getThirdSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getThirdStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getForthParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getForthSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthTestClass::getForthStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        } catch (\RuntimeException $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthTestClass::VALUE'",
                $Error->getMessage()
            );
        }
    }

    public function testInheritMock()
    {
        \Badoo\SoftMocks::redefineMethod(
            EmptyTestClass::class,
            'getter',
            '',
            'return 20;'
        );
        $Child = new EmptyEmptyTestClass();
        static::assertSame(20, $Child->getter());
        $Test = new EmptyTestClass();
        static::assertSame(20, $Test->getter());
        $Parent = new BaseTestClass();
        static::assertSame(10, $Parent->getter());
    }

    public function testInheritTrapMock()
    {
        \Badoo\SoftMocks::redefineMethod(
            BaseTestClass::class,
            'getter',
            '',
            'return 30;'
        );
        $Child = new ReplacingParentTestClass();
        static::assertEquals(20, $Child->getter());

        $Parent = new BaseTestClass();
        static::assertEquals(30, $Parent->getter());
    }

    public function testInheritParentMock()
    {
        \Badoo\SoftMocks::redefineMethod(
            BaseTestClass::class,
            'getter',
            '',
            'return 7;'
        );
        $Child = new EmptyParentTestClass();
        $res = $Child->getter();
        static::assertSame(14, $res);
    }

    public function testInheritStaticMock()
    {
        \Badoo\SoftMocks::redefineMethod(
            get_parent_class(GrandChildStaticTestClass::class),
            'getString',
            '',
            'return "D";'
        );
        static::assertSame('CD', GrandChildStaticTestClass::getString());
    }

    public function testDescendantGood()
    {
        self::assertSame(20, DescendantFirstTestClass::getDescendant());
    }

    public function testDescendantBad()
    {
        $exception_class = \Error::class;
        if (PHP_VERSION_ID < 70100) {
            $exception_class = \RuntimeException::clas;
        }
        if (\method_exists($this, 'expectException')) {
            $this->expectException($exception_class);
            $this->expectExceptionMessage('Undefined class constant');
        } else {
            // for phpunit 4.x
            $this->setExpectedException($exception_class, 'Undefined class constant');
        }
        DescendantBaseTestClass::getDescendant();
    }

    public function dataProviderResolveFile()
    {
        return [
            'Absolute file path' => [
                'file' => __DIR__ . '/fixtures/original/php7.php',
                'result' => __DIR__ . '/fixtures/original/php7.php',
            ],
            'Relative file path in include path' => [
                'file' => 'original/php7.php',
                'result' => __DIR__ . '/fixtures/original/php7.php',
            ],
            'Relative file path in cwd' => [
                'file' => 'Badoo/fixtures/original/php7.php',
                'result' => __DIR__ . '/fixtures/original/php7.php',
            ],
            'Relative file path current dir' => [
                'file' => 'fixtures/original/php7.php',
                'result' => __DIR__ . '/fixtures/original/php7.php',
            ],
            'Stream' => [
                'file' => 'stream://some/path',
                'result' => 'stream://some/path',
            ],
        ];
    }

    /**
     * @dataProvider dataProviderResolveFile
     * @param $file
     * @param $expected_result
     */
    public function testResolveFile($file, $expected_result)
    {
        \Badoo\SoftMocks::redefineFunction('realpath', '', function ($path) { return $path; });
        $old_include_path = get_include_path();
        $old_cwd = getcwd();
        set_include_path(__DIR__ . '/fixtures:.');
        chdir(__DIR__ . '/..');
        $result = $this->getPrepareFilePathToRewriteMethod()->invoke(null, $file);
        set_include_path($old_include_path);
        chdir($old_cwd);
        static::assertSame($expected_result, $result);
    }

    public function testResolveFileFileShouldNotBeEmptyException()
    {
        if (\method_exists($this, 'expectException')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('File should not be empty');
        } else {
            // for phpunit 4.x
            $this->setExpectedException(\RuntimeException::class, 'File should not be empty');
        }
        $this->getPrepareFilePathToRewriteMethod()->invoke(null, '');
    }

    public function testResolveAbsoluteFilePathNotResolvedException()
    {
        $file = __DIR__ . '/fixtures/original/__unknown.php';
        $exception_message = "Can't resolve file '{$file}'";
        if (\method_exists($this, 'expectExceptionMessage')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($exception_message);
        } else {
            // for phpunit 4.x
            $this->setExpectedException(\RuntimeException::class, $exception_message);
        }
        $this->getPrepareFilePathToRewriteMethod()->invoke(null, $file);
    }

    public function testResolveRelativeFilePathNotResolvedException()
    {
        $file = 'unit/Badoo/fixtures/original/php7.php';
        $exception_message = "Can't resolve file '{$file}'";
        if (\method_exists($this, 'expectExceptionMessage')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($exception_message);
        } else {
            // for phpunit 4.x
            $this->setExpectedException(\RuntimeException::class, $exception_message);
        }
        $old_include_path = get_include_path();
        $old_cwd = getcwd();
        set_include_path(__DIR__ . '/fixtures:.');
        chdir(__DIR__ . '/..');
        try {
            $this->getPrepareFilePathToRewriteMethod()->invoke(null, $file);
        } finally {
            set_include_path($old_include_path);
            chdir($old_cwd);
        }
    }

    protected function getPrepareFilePathToRewriteMethod()
    {
        $ReflectionClass = new \ReflectionClass(\Badoo\SoftMocks::class);
        /** @uses \Badoo\SoftMocks::prepareFilePathToRewrite */
        $ResolveFileMethod = $ReflectionClass->getMethod('prepareFilePathToRewrite');
        $ResolveFileMethod->setAccessible(true);
        return $ResolveFileMethod;
    }

    public function testNotOk()
    {
        if (\method_exists($this, 'expectExceptionMessage')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('You will never see this message');
        } else {
            // for phpunit 4.x
            $this->setExpectedException(\RuntimeException::class, 'You will never see this message');
        }

        throw new \RuntimeException("You will never see this message");

        $Mock = $this->getMock(MyTest::class, ['stubFunction']);
        $Mock->expects($this->any())->method('stubFunction')->willReturn(
            function () {
                yield 10;
            }
        );
    }

    public function stubFunction() {}

    public function testMockAbstractClassWithoutConstant()
    {
        /** @var WithoutConstantsTestClass $Object */
        $Object = $this->getMockForAbstractClass(WithoutConstantsTestClass::class, [], 'WithoutConstantsTestClassMock');

        \Badoo\SoftMocks::redefineConstant('WithoutConstantsTestClassMock::A', 1);

        static::assertEquals(1, $Object->getA());
    }

    public function testAnonymous()
    {
        static::markTestSkippedForPHPVersionBelow('7.0.0');

        require_once __DIR__ . '/AnonymousTestClass.php';
        static::assertEquals(1, AnonymousTestClass::SOMETHING);
        $test = new AnonymousTestClass();
        $obj = $test->doSomething();
        \Badoo\SoftMocks::redefineMethod(
            get_class($obj),
            'meth',
            '',
            'return "Test";'
        );
        static::assertSame('Test', $obj->meth());
    }

    public function testWithReturnTypeDeclarationsPHP7()
    {
        static::markTestSkippedForPHPVersionBelow('7.0.0');

        require_once __DIR__ . '/WithReturnTypeDeclarationsPHP7TestClass.php';

        \Badoo\SoftMocks::redefineMethod(
            WithReturnTypeDeclarationsPHP7TestClass::class,
            'getString',
            '',
            'return "string2";'
        );
        $res = WithReturnTypeDeclarationsPHP7TestClass::getString();
        static::assertSame("string2", $res);
    }

    public function testWithReturnTypeDeclarationsPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithReturnTypeDeclarationsPHP71TestClass.php';

        \Badoo\SoftMocks::redefineMethod(
            WithReturnTypeDeclarationsPHP71TestClass::class,
            'getStringOrNull',
            '',
            'return "string3";'
        );
        $int = null;
        $res = WithReturnTypeDeclarationsPHP71TestClass::getStringOrNull($int);
        static::assertSame("string3", $res);
    }

    public function providerWithOrWithoutMock()
    {
        return [
            'without mock' => [false],
            'with mock'    => [true],
        ];
    }

    public function testWithPrivateConstantPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        static::assertEquals(1, WithRestrictedConstantsPHP71TestClass::getPrivateValue());

        \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PRIVATE_VALUE', 2);

        static::assertEquals(2, WithRestrictedConstantsPHP71TestClass::getPrivateValue());
    }

    /**
     * @dataProvider providerWithOrWithoutMock
     *
     * @param bool $set_mock
     */
    public function testWithWrongPrivateConstantAccessPHP71($set_mock)
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot access private const');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        if ($set_mock) {
            \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PRIVATE_VALUE', 2);
        }

        WithWrongPrivateConstantAccessPHP71TestClass::getPrivateValue();
    }

    /**
     * @dataProvider providerWithOrWithoutMock
     *
     * @param bool $set_mock
     */
    public function testWithWrongPrivateConstantAccessFromFunctionPHP71($set_mock)
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot access private const');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        if ($set_mock) {
            \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PRIVATE_VALUE', 2);
        }

        getPrivateValue();
    }

    /**
     * @dataProvider providerWithOrWithoutMock
     *
     * @param bool $set_mock
     */
    public function testWithWrongParentPrivateConstantAccessPHP71($set_mock)
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot access private const');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        if ($set_mock) {
            \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PRIVATE_VALUE', 2);
        }

        WithRestrictedConstantsChildPHP71TestClass::getParentPrivateValue();
    }

    public function testWithSelfProtectedConstantPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        static::assertEquals(11, WithRestrictedConstantsPHP71TestClass::getSelfProtectedValue());

        \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PROTECTED_VALUE', 22);

        static::assertEquals(22, WithRestrictedConstantsPHP71TestClass::getSelfProtectedValue());
    }

    public function testWithSelfProtectedConstantFromChildPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        static::assertEquals(11, WithRestrictedConstantsChildPHP71TestClass::getSelfProtectedValue());

        \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PROTECTED_VALUE', 22);

        static::assertEquals(22, WithRestrictedConstantsChildPHP71TestClass::getSelfProtectedValue());
    }

    public function testWithStaticProtectedConstantPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        static::assertEquals(11, WithRestrictedConstantsPHP71TestClass::getStaticProtectedValue());

        \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PROTECTED_VALUE', 22);

        static::assertEquals(22, WithRestrictedConstantsPHP71TestClass::getStaticProtectedValue());
    }

    public function testWithStaticProtectedConstantFromChildPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        static::assertEquals(11, WithRestrictedConstantsChildPHP71TestClass::getStaticProtectedValue());

        \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PROTECTED_VALUE', 22);

        static::assertEquals(22, WithRestrictedConstantsChildPHP71TestClass::getStaticProtectedValue());
    }

    public function testWithThisProtectedConstantFromChildPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        $object = new WithRestrictedConstantsChildPHP71TestClass();

        static::assertEquals(11, $object->getThisObjectProtectedValue());

        \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PROTECTED_VALUE', 22);

        static::assertEquals(22, $object->getThisObjectProtectedValue());
    }

    /**
     * @dataProvider providerWithOrWithoutMock
     *
     * @param bool $set_mock
     */
    public function testWithWrongProtectedConstantAccessPHP71($set_mock)
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot access protected const');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        if ($set_mock) {
            \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PROTECTED_VALUE', 22);
        }

        getProtectedValue();
    }

    /**
     * @dataProvider providerWithOrWithoutMock
     *
     * @param bool $set_mock
     */
    public function testWithWrongProtectedConstantAccessFromFunctionPHP71($set_mock)
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot access protected const');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        if ($set_mock) {
            \Badoo\SoftMocks::redefineConstant('\Badoo\SoftMock\Tests\WithRestrictedConstantsPHP71TestClass::PROTECTED_VALUE', 22);
        }

        getProtectedValue();
    }

    public function testNotRedefinedClassConstantsPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        try {
            ConstantRedeclareBasePHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareBasePHP71TestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareFirstPHP71TestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareForthPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        static::assertSame(2, ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue());

        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue());

        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue());

        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getBaseStaticValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthStaticValue());
    }

    public function testBaseClassConstantRedefinedPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBasePHP71TestClass::class . '::VALUE',
            10
        );

        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue());
        static::assertSame(2, ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue());
        static::assertSame(2, ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareThirdPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstParentValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getFirstStaticValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getSecondParentValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getSecondStaticValue());
        static::assertSame(2, ConstantRedeclareForthPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthStaticValue());
    }

    public function testFromBaseToFirstClassConstantRedefinedPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBasePHP71TestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstPHP71TestClass::class . '::VALUE',
            20
        );

        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getSecondParentValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getSecondParentValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getSecondParentValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getSecondStaticValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthStaticValue());
    }

    public function testFromBaseToSecondClassConstantRedefinedPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBasePHP71TestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstPHP71TestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondPHP71TestClass::class . '::VALUE',
            30
        );

        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareThirdPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getBaseSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getFirstSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareForthPHP71TestClass::getSecondSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareForthPHP71TestClass::getThirdParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getThirdStaticValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthParentValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthSelfValue());
        static::assertSame(4, ConstantRedeclareForthPHP71TestClass::getForthStaticValue());
    }

    public function testFromBaseToThirdClassConstantRedefinedPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBasePHP71TestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstPHP71TestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondPHP71TestClass::class . '::VALUE',
            30
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareThirdPHP71TestClass::class . '::VALUE',
            40
        );

        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareThirdPHP71TestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getBaseSelfValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getFirstSelfValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareForthPHP71TestClass::getSecondSelfValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareForthPHP71TestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getThirdSelfValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getThirdStaticValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getForthParentValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getForthSelfValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getForthStaticValue());
    }

    public function testFromBaseToForthClassConstantRedefinedPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBasePHP71TestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstPHP71TestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondPHP71TestClass::class . '::VALUE',
            30
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareThirdPHP71TestClass::class . '::VALUE',
            40
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareForthPHP71TestClass::class . '::VALUE',
            50
        );

        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue());
        static::assertSame(20, ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareSecondPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue());
        static::assertSame(30, ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareThirdPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareThirdPHP71TestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue());
        static::assertSame(40, ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getBaseSelfValue());
        static::assertSame(50, ConstantRedeclareForthPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstParentValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getFirstSelfValue());
        static::assertSame(50, ConstantRedeclareForthPHP71TestClass::getFirstStaticValue());
        static::assertSame(20, ConstantRedeclareForthPHP71TestClass::getSecondParentValue());
        static::assertSame(30, ConstantRedeclareForthPHP71TestClass::getSecondSelfValue());
        static::assertSame(50, ConstantRedeclareForthPHP71TestClass::getSecondStaticValue());
        static::assertSame(30, ConstantRedeclareForthPHP71TestClass::getThirdParentValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getThirdSelfValue());
        static::assertSame(50, ConstantRedeclareForthPHP71TestClass::getThirdStaticValue());
        static::assertSame(40, ConstantRedeclareForthPHP71TestClass::getForthParentValue());
        static::assertSame(50, ConstantRedeclareForthPHP71TestClass::getForthSelfValue());
        static::assertSame(50, ConstantRedeclareForthPHP71TestClass::getForthStaticValue());
    }

    public function testRedifineBaseClassConstantAndRemoveRestoreOtherClassConstantsPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareBasePHP71TestClass::class . '::VALUE',
            10
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareFirstPHP71TestClass::class . '::VALUE',
            20
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareSecondPHP71TestClass::class . '::VALUE',
            30
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareThirdPHP71TestClass::class . '::VALUE',
            40
        );
        \Badoo\SoftMocks::redefineConstant(
            ConstantRedeclareForthPHP71TestClass::class . '::VALUE',
            50
        );

        \Badoo\SoftMocks::removeConstant(ConstantRedeclareFirstPHP71TestClass::class . '::VALUE');
        \Badoo\SoftMocks::restoreConstant(ConstantRedeclareSecondPHP71TestClass::class . '::VALUE');
        \Badoo\SoftMocks::removeConstant(ConstantRedeclareThirdPHP71TestClass::class . '::VALUE');
        \Badoo\SoftMocks::restoreConstant(ConstantRedeclareForthPHP71TestClass::class . '::VALUE');

        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareBasePHP71TestClass::getBaseStaticValue());

        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue());

        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getSecondParentValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue());
        static::assertSame(10, ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue());

        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getSecondParentValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getThirdParentValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue());
        static::assertSame(10, ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue());

        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getBaseSelfValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getBaseStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstParentValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstSelfValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getFirstStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getSecondParentValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getSecondSelfValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getSecondStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getThirdParentValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getThirdSelfValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getThirdStaticValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getForthParentValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getForthSelfValue());
        static::assertSame(10, ConstantRedeclareForthPHP71TestClass::getForthStaticValue());
    }

    public function testRemoveConstantFromHierarchyPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        \Badoo\SoftMocks::removeConstant(ConstantRedeclareFirstPHP71TestClass::class . '::VALUE');
        \Badoo\SoftMocks::removeConstant(ConstantRedeclareThirdPHP71TestClass::class . '::VALUE');

        try {
            ConstantRedeclareBasePHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareBasePHP71TestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareFirstPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstPHP71TestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstPHP71TestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstPHP71TestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareFirstPHP71TestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareSecondPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondPHP71TestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondPHP71TestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondPHP71TestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondPHP71TestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondPHP71TestClass::getSecondParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondPHP71TestClass::getSecondSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareSecondPHP71TestClass::getSecondStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareThirdPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getSecondParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getSecondSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getSecondStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getThirdParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getThirdSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareThirdPHP71TestClass::getThirdStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }

        try {
            ConstantRedeclareForthPHP71TestClass::getBaseSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getBaseStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getFirstParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareBasePHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getFirstSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getFirstStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getSecondParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareFirstPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getSecondSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getSecondStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getThirdParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareSecondPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getThirdSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getThirdStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getForthParentValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareThirdPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getForthSelfValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
        try {
            ConstantRedeclareForthPHP71TestClass::getForthStaticValue();
            static::fail("Exception wasn't thrown");
        } catch (\Error $Error) {
            static::assertSame(
                "Undefined class constant 'Badoo\SoftMock\Tests\ConstantRedeclareForthPHP71TestClass::VALUE'",
                $Error->getMessage()
            );
        }
    }

    /**
     * @dataProvider providerWithOrWithoutMock
     *
     * @param bool $set_mock
     */
    public function testArrayDestructingPHP71($set_mock)
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/ArrayDestructingPHP71TestClass.php';

        if ($set_mock) {
            \Badoo\SoftMocks::redefineConstant(
                '\Badoo\SoftMock\Tests\ArrayDestructingPHP71TestClass::VALUES',
                ['aa', 'bb', 'cc']
            );
        }

        static::assertEquals($set_mock ? 'aa' : 'a', ArrayDestructingPHP71TestClass::getA());
        static::assertEquals($set_mock ? 'bb' : 'b', ArrayDestructingPHP71TestClass::getB());
        static::assertEquals($set_mock ? 'cc' : 'c', ArrayDestructingPHP71TestClass::getC());
    }

    public function providerRewrite()
    {
        $files = glob(__DIR__ . '/fixtures/original/*.php');
        $result = array_map(
            function ($filename) {
                return [basename($filename)];
            },
            $files
        );
        return array_combine(array_column($result, 0), $result);
    }

    /**
     * @dataProvider providerRewrite
     *
     * @param $filename
     */
    public function testRewrite($filename)
    {
        if (($filename === 'php7.php')) {
            static::markTestSkippedForPHPVersionBelow('7.0.0');
        }

        if (($filename === 'php71.php')) {
            static::markTestSkippedForPHPVersionBelow('7.1.0');
        }

        $result = \Badoo\SoftMocks::rewrite(__DIR__ . '/fixtures/original/' . $filename);
        $this->assertNotFalse($result, "Rewrite failed");

        //file_put_contents(__DIR__ . '/fixtures/expected/' . $filename, file_get_contents($result));
        $this->assertEquals(trim(file_get_contents(__DIR__ . '/fixtures/expected/' . $filename)), file_get_contents($result));
    }

    public function testCrossPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot access protected const');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        CrossSecondPHP71TestClass::getCross();
    }

    public function testDescendantGoodPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        self::assertSame(20, DescendantFirstPHP71TestClass::getDescendant());
    }

    public function testDescendantBadPHP71()
    {
        static::markTestSkippedForPHPVersionBelow('7.1.0');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Undefined class constant');

        require_once __DIR__ . '/WithRestrictedConstantsPHP71TestClass.php';

        DescendantBasePHP71TestClass::getDescendant();
    }

    public function testGetOriginalFilePath()
    {
        $original_file = __FILE__;
        $rewritten_path = \Badoo\SoftMocks::rewrite($original_file);
        static::assertSame(
            $rewritten_path,
            realpath(\Badoo\SoftMocks::getRewrittenFilePath($original_file))
        );
        static::assertSame(
            $original_file,
            \Badoo\SoftMocks::getOriginalFilePath($rewritten_path)
        );
    }

    public function testGetDeclaringTrait()
    {
        $soft_mocks = new \ReflectionClass(\Badoo\SoftMocks::class);
        /** @uses \Badoo\SoftMocks::getDeclaringTrait */
        $getDeclaringTrait = $soft_mocks->getMethod('getDeclaringTrait');
        $getDeclaringTrait->setAccessible(true);

        // No traits.
        $this->assertNull($getDeclaringTrait->invoke(null, ClassWithoutTraits::class, 'doThings'));

        // Direct trait usage.
        /** @var \ReflectionClass $result */
        $result = $getDeclaringTrait->invoke(null, ClassWithTraitB::class, 'doThings');
        $this->assertSame(TraitB::class, $result->getName());

        // Trait in trait usage.
        /** @var \ReflectionClass $result */
        $result = $getDeclaringTrait->invoke(null, ClassWithTraitA::class, 'doThings');
        $this->assertSame(TraitB::class, $result->getName());
    }

    public function providerClassWithIsCallable()
    {
        return [
            'numeric keys' => [[ClassWithIsCallable::class, 'doThings'], true],
            'assoc keys' => [['x' => ClassWithIsCallable::class, 'y' => 'doThings'], false],
        ];
    }

    /**
     * @dataProvider providerClassWithIsCallable
     *
     * @param callable $callable
     * @param bool $expected_result
     */
    public function testClassWithIsCallable($callable, $expected_result)
    {
        $this->assertSame($expected_result, ClassWithIsCallable::check($callable));
    }

    public function testClassWithCallViaVariable()
    {
        \Badoo\SoftMocks::redefineMethod(
            ClassWithCallViaVariable::class,
            'a',
            '',
            'return 2;'
        );

        self::assertSame(2, ClassWithCallViaVariable::callA());
        self::assertSame(2, ClassWithCallViaVariable::callAViaVariable());
    }
}
