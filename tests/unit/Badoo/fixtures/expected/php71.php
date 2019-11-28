<?php
/**
 * This file contains php71 code
 */
class SomeClass{
    
    protected const VALUES = ['a', 'b', 'c'];
    
    public static function getAB(){if (false !== ($__softmocksvariableforcode = \Badoo\SoftMocks::isMocked(SomeClass::class, static::class, __FUNCTION__))) {$mm_func_args = func_get_args();$params = [];$variadic_params_idx = '';return eval($__softmocksvariableforcode);/** @codeCoverageIgnore */}
        
        [
            $a,
            ,
            $b,] = \Badoo\SoftMocks::getClassConst(self::class, 'VALUES', self::class);
        
        return [$a, $b];}}