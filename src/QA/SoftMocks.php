<?php
/**
 * Mocks core that rewrites code
 * @author Yuriy Nasretdinov <y.nasretdinov@corp.badoo.com>
 */

namespace QA;

// Remove this when mb_overload is no longer available for usage in PHP
if (!function_exists('mb_orig_strlen')) {
    function mb_orig_strlen($str)
    {
        return strlen($str);
    }
}

class SoftMocksFunctionCreator
{
    public function run($obj, $class, $params, $mocks)
    {
        if ($mocks['code'] instanceof \Closure) {
            $new_func = $mocks['code'];
        } else {
            $code = "return function(" . $mocks['args'] . ") use (\$params) { " . $mocks['code'] . " };";
            $func = eval($code);
            $new_func = \Closure::bind($func, $obj, $class);
        }

        return call_user_func_array($new_func, $params);
    }
}

class SoftMocksPrinter extends \PhpParser\PrettyPrinter\Standard
{
    private $cur_ln;

    /**
     * Pretty prints an array of nodes (statements) and indents them optionally.
     *
     * @param \PhpParser\Node[] $nodes  Array of nodes
     * @param bool   $indent Whether to indent the printed nodes
     *
     * @return string Pretty printed statements
     */
    protected function pStmts(array $nodes, $indent = true)
    {
        $result = '';

        foreach ($nodes as $node) {
            $row = "";

            $cur_ln = $this->cur_ln;

            $comments = $this->pComments($node->getAttribute('comments', array()));
            $this->cur_ln += substr_count($comments, "\n");

            if ($node->getLine() > $this->cur_ln) {
                $row .= str_repeat("\n", $node->getLine() - $this->cur_ln);
                $this->cur_ln += substr_count($row, "\n");
            }

            $row .= $comments
                . $this->p($node)
                . ($node instanceof \PhpParser\Node\Expr ? ';' : '');

            $this->cur_ln = $cur_ln + substr_count($row, "\n"); // get rid of cur_ln modifications in deeper context

            $result .= $row;
        }

        if ($indent) {
            return preg_replace('~\n(?!$|' . $this->noIndentToken . ')~', "\n    ", $result);
        } else {
            return $result;
        }
    }

    public function prettyPrintFile(array $stmts)
    {
        $this->cur_ln = 1;
        $this->preprocessNodes($stmts);
        return "<?php " . str_replace("\n" . $this->noIndentToken, "\n", $this->pStmts($stmts, false));
    }

    protected function p(\PhpParser\Node $node)
    {
        $prefix = '';

        if ($node->getLine() > $this->cur_ln) {
            $prefix = str_repeat("\n", $node->getLine() - $this->cur_ln);
            $this->cur_ln = $node->getLine();
        }

        return $prefix . $this->{'p' . $node->getType()}($node);
    }

    public function pExpr_Closure(\PhpParser\Node\Expr\Closure $node)
    {
        return ($node->static ? 'static ' : '')
            . 'function ' . ($node->byRef ? '&' : '')
            . '(' . $this->pCommaSeparated($node->params) . ')'
            . (!empty($node->uses) ? ' use(' . $this->pCommaSeparated($node->uses) . ')' : '')
            . (null !== $node->returnType ? ' : ' . $this->pType($node->returnType) : '')
            . ' {' . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_Namespace(\PhpParser\Node\Stmt\Namespace_ $node)
    {
        if ($this->canUseSemicolonNamespaces) {
            return 'namespace ' . $this->p($node->name) . ';' . $this->pStmts($node->stmts, false);
        } else {
            return 'namespace' . (null !== $node->name ? ' ' . $this->p($node->name) : '')
                . ' {' . $this->pStmts($node->stmts) . '}';
        }
    }

    public function pStmt_Interface(\PhpParser\Node\Stmt\Interface_ $node)
    {
        return 'interface ' . $node->name
            . (!empty($node->extends) ? ' extends ' . $this->pCommaSeparated($node->extends) : '')
            . '{' . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_Trait(\PhpParser\Node\Stmt\Trait_ $node)
    {
        return 'trait ' . $node->name
            . '{' . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_TraitUse(\PhpParser\Node\Stmt\TraitUse $node)
    {
        return 'use ' . $this->pCommaSeparated($node->traits)
            . (empty($node->adaptations) ? ';' : ' {' . $this->pStmts($node->adaptations) . '}');
    }

    public function pStmt_ClassMethod(\PhpParser\Node\Stmt\ClassMethod $node)
    {
        return $this->pModifiers($node->type)
            . 'function ' . ($node->byRef ? '&' : '') . $node->name
            . '(' . $this->pCommaSeparated($node->params) . ')'
            . (null !== $node->returnType ? ' : ' . $this->pType($node->returnType) : '')
            . (null !== $node->stmts ? '{' . $this->pStmts($node->stmts) . '}' : ';');
    }

    public function pStmt_Function(\PhpParser\Node\Stmt\Function_ $node)
    {
        return 'function ' . ($node->byRef ? '&' : '') . $node->name
            . '(' . $this->pCommaSeparated($node->params) . ')'
            . (null !== $node->returnType ? ' : ' . $this->pType($node->returnType) : '')
            . '{' . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_Declare(\PhpParser\Node\Stmt\Declare_ $node)
    {
        return 'declare (' . $this->pCommaSeparated($node->declares) . ') {'
            . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_If(\PhpParser\Node\Stmt\If_ $node)
    {
        return 'if (' . $this->p($node->cond) . ') {'
            . $this->pStmts($node->stmts) . '}'
            . $this->pImplode($node->elseifs)
            . (null !== $node->else ? $this->p($node->else) : '');
    }

    public function pStmt_ElseIf(\PhpParser\Node\Stmt\ElseIf_ $node)
    {
        return ' elseif (' . $this->p($node->cond) . ') {'
            . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_Else(\PhpParser\Node\Stmt\Else_ $node)
    {
        return ' else {' . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_For(\PhpParser\Node\Stmt\For_ $node)
    {
        return 'for ('
            . $this->pCommaSeparated($node->init) . ';' . (!empty($node->cond) ? ' ' : '')
            . $this->pCommaSeparated($node->cond) . ';' . (!empty($node->loop) ? ' ' : '')
            . $this->pCommaSeparated($node->loop)
            . ') {' . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_Foreach(\PhpParser\Node\Stmt\Foreach_ $node)
    {
        return 'foreach (' . $this->p($node->expr) . ' as '
            . (null !== $node->keyVar ? $this->p($node->keyVar) . ' => ' : '')
            . ($node->byRef ? '&' : '') . $this->p($node->valueVar) . ') {'
            . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_While(\PhpParser\Node\Stmt\While_ $node)
    {
        return 'while (' . $this->p($node->cond) . ') {'
            . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_Do(\PhpParser\Node\Stmt\Do_ $node)
    {
        return 'do {' . $this->pStmts($node->stmts)
            . '} while (' . $this->p($node->cond) . ');';
    }

    public function pStmt_Switch(\PhpParser\Node\Stmt\Switch_ $node)
    {
        return 'switch (' . $this->p($node->cond) . ') {'
            . $this->pStmts($node->cases) . '}';
    }

    public function pStmt_Case(\PhpParser\Node\Stmt\Case_ $node)
    {
        return (null !== $node->cond ? 'case ' . $this->p($node->cond) : 'default') . ':'
            . $this->pStmts($node->stmts);
    }

    public function pStmt_TryCatch(\PhpParser\Node\Stmt\TryCatch $node)
    {
        return 'try {' . $this->pStmts($node->stmts) . '}'
            . $this->pImplode($node->catches)
            . ($node->finallyStmts !== null ? ' finally {' . $this->pStmts($node->finallyStmts) . '}' : '');
    }

    public function pStmt_Catch(\PhpParser\Node\Stmt\Catch_ $node)
    {
        return ' catch (' . $this->p($node->type) . ' $' . $node->var . ') {'
            . $this->pStmts($node->stmts) . '}';
    }

    public function pStmt_Break(\PhpParser\Node\Stmt\Break_ $node)
    {
        return 'break' . ($node->num !== null ? ' ' . $this->p($node->num) : '') . ';';
    }

    public function pStmt_Continue(\PhpParser\Node\Stmt\Continue_ $node)
    {
        return 'continue' . ($node->num !== null ? ' ' . $this->p($node->num) : '') . ';';
    }

    public function pStmt_Return(\PhpParser\Node\Stmt\Return_ $node)
    {
        return 'return' . (null !== $node->expr ? ' ' . $this->p($node->expr) : '') . ';';
    }

    public function pStmt_Throw(\PhpParser\Node\Stmt\Throw_ $node)
    {
        return 'throw ' . $this->p($node->expr) . ';';
    }

    public function pStmt_Label(\PhpParser\Node\Stmt\Label $node)
    {
        return $node->name . ':';
    }

    public function pStmt_Goto(\PhpParser\Node\Stmt\Goto_ $node)
    {
        return 'goto ' . $node->name . ';';
    }

    // Other

    public function pStmt_Echo(\PhpParser\Node\Stmt\Echo_ $node)
    {
        return 'echo ' . $this->pCommaSeparated($node->exprs) . ';';
    }

    public function pStmt_Static(\PhpParser\Node\Stmt\Static_ $node)
    {
        return 'static ' . $this->pCommaSeparated($node->vars) . ';';
    }

    public function pStmt_Global(\PhpParser\Node\Stmt\Global_ $node)
    {
        return 'global ' . $this->pCommaSeparated($node->vars) . ';';
    }

    public function pStmt_StaticVar(\PhpParser\Node\Stmt\StaticVar $node)
    {
        return '$' . $node->name
            . (null !== $node->default ? ' = ' . $this->p($node->default) : '');
    }

    public function pStmt_Unset(\PhpParser\Node\Stmt\Unset_ $node)
    {
        return 'unset(' . $this->pCommaSeparated($node->vars) . ');';
    }

    public function pStmt_HaltCompiler(\PhpParser\Node\Stmt\HaltCompiler $node)
    {
        return '__halt_compiler();' . $node->remaining;
    }

    // Helpers

    protected function pType($node)
    {
        return is_string($node) ? $node : $this->p($node);
    }

    protected function pClassCommon(\PhpParser\Node\Stmt\Class_ $node, $afterClassToken)
    {
        return $this->pModifiers($node->type)
            . 'class' . $afterClassToken
            . (null !== $node->extends ? ' extends ' . $this->p($node->extends) : '')
            . (!empty($node->implements) ? ' implements ' . $this->pCommaSeparated($node->implements) : '')
            . '{' . $this->pStmts($node->stmts) . '}';
    }

    protected function pEncapsList(array $encapsList, $quote)
    {
        $bak_line = $this->cur_ln;
        $return = '';
        foreach ($encapsList as $element) {
            if (is_string($element)) {
                $return .= addcslashes($element, "\n\r\t\f\v$" . $quote . "\\");
            } else {
                $return .= '{' . trim($this->p($element)) . '}';
            }
        }
        $this->cur_ln = $bak_line + substr_count($return, "\n");

        return $return;
    }
}

class SoftMocks
{
    const MOCKS_CACHE_TOUCHTIME = 86400; /* 1 day */

    private static $rewrite_cache = [/* source => target */];
    private static $orig_paths = [/* target => source */];

    private static $version;

    private static $error_descriptions = [
        E_ERROR => "Error",
        E_WARNING => "Warning",
        E_PARSE => "Parse Error",
        E_NOTICE => "Notice",
        E_CORE_ERROR => "Core Error",
        E_CORE_WARNING => "Core Warning",
        E_COMPILE_ERROR => "Compile Error",
        E_COMPILE_WARNING => "Compile Warning",
        E_USER_ERROR => "User Error",
        E_USER_WARNING => "User Warning",
        E_USER_NOTICE => "User Notice",
        E_STRICT => "Strict Notice",
        E_RECOVERABLE_ERROR => "Recoverable Error",
    ];

    private static $ignore = [];

    public static $internal_functions = [];

    private static $mocks = [];

    private static $func_mocks = [];
    private static $internal_func_mocks = []; // internal mocks that cannot be changed

    private static $generator_mocks = []; // mocks for generators

    private static $new_mocks = []; // mocks for "new" operator
    private static $constant_mocks = [];
    private static $removed_constants = [];

    private static $debug = false;

    private static $temp_disable = false;

    private static $rewrite_internal = false;
    private static $project_path;
    private static $mocks_cache_path = "/tmp/mocks/";
    private static $phpunit_path = "/phpunit/";
    private static $php_parser_path = "/php-parser/";
    private static $lock_file_path = '/tmp/mocks/soft_mocks_rewrite.lock';

    public static function init()
    {
        if (!defined('SOFTMOCKS_ROOT_PATH')) {
            define('SOFTMOCKS_ROOT_PATH', '/');
        }

        if (!empty($_ENV['SOFT_MOCKS_DEBUG'])) {
            self::$debug = true;
        }

        self::$func_mocks['call_user_func_array'] = [
            'args' => '', 'code' => 'return \\' . self::class . '::call($params[0], $params[1]);',
        ];
        self::$func_mocks['call_user_func'] = [
            'args' => '', 'code' => '$func = array_shift($params); return \\' . self::class . '::call($func, $params);',
        ];
        self::$func_mocks['is_callable'] = [
            'args' => '$arg', 'code' => 'return \\' . self::class . '::isCallable($arg);',
        ];
        self::$func_mocks['function_exists'] = [
            'args' => '$arg', 'code' => 'return \\' . self::class . '::isCallable($arg);',
        ];
        self::$func_mocks['constant'] = [
            'args' => '$constant', 'code' => 'return \\' . self::class . '::getConst("", $constant);',
        ];
        self::$func_mocks['defined'] = [
            'args' => '$constant', 'code' => 'return \\' . self::class . '::constDefined($constant);',
        ];
        self::$func_mocks['debug_backtrace'] = [
            'args' => '',
            'code' => function() {
                $params = func_get_args();

                $limit = 0;
                $provide_object = true;
                $ignore_args = false;

                if (isset($params[0])) {
                    if ($params[0] === false) {
                        $provide_object = false;
                    } else if ($params[0] === true) {
                        $provide_object = true;
                    } else {
                        $provide_object = $params[0] & DEBUG_BACKTRACE_PROVIDE_OBJECT;
                        $ignore_args = $params[0] & DEBUG_BACKTRACE_IGNORE_ARGS;
                    }
                }
                if (isset($params[1])) {
                    $limit = $params[1];
                }

                $result = debug_backtrace();

                // remove our part of backtrace
                while (count($result) > 0) {
                    if (isset($result[0]['class']) && $result[0]['class'] === self::class
                        && isset($result[0]['function']) && $result[0]['function'] === 'callFunction') {
                        array_shift($result);
                        break;
                    }

                    array_shift($result);
                }

                // remove calls that occur inside our file
                $result = array_values(
                    array_filter(
                        $result,
                        function($el) { return !isset($el['file']) || $el['file'] !== __FILE__; }
                    )
                );

                $unset_indices = [];

                // replace paths
                foreach ($result as $i => &$p) {
                    if (isset($p["file"])) {
                        $p["file"] = self::replaceFilename($p["file"], true);
                        if ($p["file"] && $p["file"][0] !== '/') {
                            $p["file"] = SOFTMOCKS_ROOT_PATH . $p['file'];
                        }
                    }

                    if (isset($p['class']) && $p['class'] == self::class && isset($p['args'])) {
                        $args = $p['args'];

                        if ($p['function'] === 'callFunction') {
                            unset($p['class']);
                            unset($p['type']);
                            $p['function'] = $args[1];
                            $p['args'] = $args[2];
                            $unset_indices[] = $i - 1; // reflection adds another line in trace that is not needed
                        } else if ($p['function'] === 'callMethod') {
                            $p['object'] = $args[0];
                            $p['class'] = get_class($args[0]);
                            $p['function'] = $args[1];
                            $p['args'] = $args[2];
                            $p['type'] = '->';
                            $unset_indices[] = $i - 1; // reflection adds another line in trace that is not needed
                        } else if ($p['function'] === 'callStaticMethod') {
                            $p['class'] = $args[0];
                            $p['function'] = $args[1];
                            $p['args'] = $args[2];
                            $p['type'] = '::';
                            $unset_indices[] = $i - 1; // reflection adds another line in trace that is not needed
                        }
                    }

                    if ($ignore_args) {
                        unset($p['args']);
                    }

                    if (!$provide_object) {
                        unset($p['object']);
                    }
                }

                foreach ($unset_indices as $i) {
                    unset($result[$i]);
                }

                $result = array_values($result);

                if (!$limit) {
                    return $result;
                }

                $limited_result = [];
                for ($i = 0; $i < $limit; $i++) {
                    $limited_result[] = $result[$i];
                }

                return $limited_result;
            },
        ];

        self::$internal_func_mocks = [];
        foreach (self::$func_mocks as $func => $mock) {
            self::$internal_func_mocks[$func] = $mock;
        }

        $functions = get_defined_functions();
        foreach ($functions['internal'] as $func) {
            self::$internal_functions[$func] = true;
        }

        self::ignoreFiles(get_included_files());
        self::injectIntoPhpunit();
        self::initProjectPath();
    }

    protected static function initProjectPath()
    {
        $lib_path = dirname(dirname(__DIR__));
        $vendor_path = dirname(dirname($lib_path));
        if (basename($vendor_path) === 'vendor') {
            self::$project_path = dirname($vendor_path);
            return;
        }
        self::$project_path = $lib_path;
    }

    public static function setProjectPath($project_path)
    {
        if (!empty($project_path)) {
            self::$project_path = rtrim($project_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        if (!is_dir(self::$project_path)) {
            throw new \RuntimeException("Project path isn't exists");
        }
    }

    /**
     * @param bool $rewrite_internal
     */
    public static function setRewriteInternal($rewrite_internal)
    {
        self::$rewrite_internal = (bool)$rewrite_internal;
    }

    /**
     * @param string $mocks_cache_path - Path to cache of rewritten files
     */
    public static function setMocksCachePath($mocks_cache_path)
    {
        if (!empty($mocks_cache_path)) {
            self::$mocks_cache_path = rtrim($mocks_cache_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        if (!file_exists(self::$mocks_cache_path) && !mkdir(self::$mocks_cache_path, 0777)) {
            throw new \RuntimeException("Can't create cache dir for rewritten files at " . self::$mocks_cache_path);
        }
    }

    /**
     * @param $lock_file_path - Path to lock file that is used when file is rewritten
     */
    public static function setLockFilePath($lock_file_path)
    {
        if (!empty($lock_file_path)) {
            self::$lock_file_path = $lock_file_path;
        }

        if (!file_exists(self::$lock_file_path) && !touch(self::$lock_file_path)) {
            throw new \RuntimeException("Can't create lock file at " . self::$lock_file_path);
        }
    }

    /**
     * @param string $phpunit_path - Part of path to phpunit so that it can be ignored when rewriting files
     */
    public static function setPhpunitPath($phpunit_path)
    {
        if (!empty($phpunit_path)) {
            self::$phpunit_path = $phpunit_path;
        }
    }

    /**
     * @param $php_parser_path - Part of path to PHP Parser so that it can be ignored when rewriting files
     */
    public static function setPhpParserPath($php_parser_path)
    {
        if (!empty($php_parser_path)) {
            self::$php_parser_path = $php_parser_path;
        }
    }

    /**
     * @param string $class - Do not allow to mock $class
     */
    public static function ignoreClass($class)
    {
        SoftMocksTraverser::ignoreClass(ltrim($class, '\\'));
    }

    /**
     * @param string $constant - Do not allow to mock $constant
     */
    public static function ignoreConstant($constant)
    {
        SoftMocksTraverser::ignoreConstant(ltrim($constant, '\\'));
    }

    /**
     * @param string $function - Do not allow to mock $function
     */
    public static function ignoreFunction($function)
    {
        SoftMocksTraverser::ignoreFunction(ltrim($function, '\\'));
    }

    public static function errorHandler($errno, $errstr, $errfile, $errline)
    {
        if (!(error_reporting() & $errno)) return;
        $descr = isset(self::$error_descriptions[$errno]) ? self::$error_descriptions[$errno] : "Unknown error ($errno)";
        echo "\n$descr: $errstr in " . self::replaceFilename($errfile) . " on line $errline\n";
    }

    public static function printBackTrace(\Exception $e = null)
    {
        $str = $e ?: new \Exception();

        if (!empty($_ENV['REAL_BACKTRACE'])) {
            echo $str->getTraceAsString();
            return;
        }

        $trace_lines = explode("\n", $str->getFile() . '(' . $str->getLine() . ")\n" . $str->getTraceAsString());

        foreach ($trace_lines as &$ln) {
            $ln = preg_replace_callback(
                '#(/[^:(]+)([:(])#',
                function ($data) {
                    return self::replaceFilename($data[1], true) . $data[2];
                },
                $ln
            );

            $ln = str_replace('[internal function]', '', $ln);
            $ln = preg_replace('/^\\#\\d+\\s*\\:?\\s*/s', '', $ln);
        }

        echo "(use REAL_BACKTRACE=1 to get real trace) " . implode(
            "\n ",
            array_filter(
                $trace_lines,
                function ($str) {
                    $parts = explode('(', trim($str), 2);
                    if (count($parts) > 1) {
                        $filename = $parts[0];
                        if (stripos($filename, 'PHPUnit') !== false) {
                            return false;
                        }
                    }

                    return strpos($str, 'PHPUnit_Framework_ExpectationFailedException') === false
                        && strpos($str, '{main}') === false
                        && strpos($str, basename(__FILE__)) === false;
                }
            )
        ) . "\n";
    }

    public static function replaceFilename($file, $raw = false)
    {
        if (isset(self::$orig_paths[$file])) {
            if ($raw) {
                return self::$orig_paths[$file];
            }
            return $file . " (orig: " . self::$orig_paths[$file] . ")";
        }

        return $file;
    }

    private static function getVersion()
    {
        if (!isset(self::$version)) {
            self::$version = phpversion() . md5_file(__FILE__);
        }
        return self::$version;
    }

    public static function rewrite($file)
    {
        try {
            return self::doRewrite($file);
        } catch (\Exception $e) {
            echo "Could not rewrite file $file: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private static function doRewrite($file)
    {
        if ($file[0] != '/') {
            foreach (explode(':', get_include_path()) as $dir) {
                if ($dir == '.') {
                    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                    $dir = dirname(self::replaceFilename($bt[1]['file'], true));
                }

                if (file_exists($dir . '/' . $file)) {
                    $file = "$dir/$file";
                    break;
                }
            }
        } else {
            $file = realpath($file);
        }
        if (!$file) return $file;

        if (!isset(self::$rewrite_cache[$file])) {
            if (strpos($file, self::$mocks_cache_path) === 0
                || strpos($file, self::getVersion() . DIRECTORY_SEPARATOR) === 0
                || strpos($file, self::$phpunit_path) !== false
                || strpos($file, self::$php_parser_path) !== false) {
                return $file;
            }

            if (isset(self::$ignore[$file])) {
                return $file;
            }

            $md5_file = md5_file($file);
            if (!$md5_file) {
                return (self::$orig_paths[$file] = self::$rewrite_cache[$file] = $file);
            }

            $clean_filepath = $file;
            if (strpos($clean_filepath, SOFTMOCKS_ROOT_PATH) === 0) {
                $clean_filepath = substr($clean_filepath, strlen(SOFTMOCKS_ROOT_PATH));
            }

            if (self::$debug) {
                self::debug("Clean filepath for $file is $clean_filepath");
            }

            $md5 = md5($clean_filepath . ':' . $md5_file);
            if (self::$project_path && strpos($file, self::$project_path) === 0) {
                $file_in_project = substr($file, strlen(self::$project_path));
            } else {
                $file_in_project = basename($file);
            }

            $target_file = self::$mocks_cache_path . self::getVersion() . DIRECTORY_SEPARATOR . $file_in_project . "_" . $md5 . ".php";
            $target_dir = dirname($target_file);
            if (!@mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
                throw new \RuntimeException("Can't create {$target_dir}");
            }
            if (!file_exists($target_file)) {
                $old_umask = umask(0);
                self::createRewrittenFile($file, $target_file);
                umask($old_umask);
            /* simulate atime to prevent deletion files in use by SoftMocksCleaner */
            } else if (time() - filemtime($target_file) > self::MOCKS_CACHE_TOUCHTIME) {
                touch($target_file);
            }

            $target_file = realpath($target_file);

            self::$rewrite_cache[$file] = $target_file;
            self::$orig_paths[$target_file] = $file;
        }

        return self::$rewrite_cache[$file];
    }

    private static function createRewrittenFile($file, $target_file)
    {
        if (self::$debug) {
            echo "Rewriting $file => $target_file\n";
            echo new \Exception();
            ob_flush();
        }

        $contents = file_get_contents($file);
        $contents = self::rewriteContents($file, $target_file, $contents);

        $target_dir = dirname($target_file);

        if (!$fp = fopen(self::$lock_file_path, 'a')) {
            throw new \RuntimeException("Could not create lock file " . self::$lock_file_path);
        }

        if (!flock($fp, LOCK_EX)) {
            throw new \RuntimeException("Could not flock " . self::$lock_file_path);
        }

        if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true)) {
            throw new \RuntimeException("Could not create directory " . $target_dir);
        }

        $tmp_file = $target_file . ".tmp." . uniqid(getmypid());
        if (file_put_contents($tmp_file, $contents) !== mb_orig_strlen($contents)) {
            throw new \RuntimeException("Could not fully write $tmp_file");
        }

        if (DIRECTORY_SEPARATOR == '\\') {
            // You cannot atomically replace files in Windows
            if (file_exists($target_file) && !unlink($target_file)) {
                throw new \RuntimeException("Could not unlink $target_file");
            }
        }

        if (!rename($tmp_file, $target_file)) {
            throw new \RuntimeException("Could not rename $tmp_file -> $target_file");
        }

        if (!fclose($fp)) {
            throw new \RuntimeException("Could not fclose lock file descriptor for file " . self::$lock_file_path);
        }
    }

    /**
     * Generic method to call a callable, useful for proxying call_user_func* calls
     *
     * @param $callable
     * @param $args
     * @return mixed
     */
    public static function call($callable, $args)
    {
        if (is_scalar($callable) && strpos($callable, '::') === false) {
            return self::callFunction('', $callable, $args);
        }

        if (is_scalar($callable)) {
            $parts = explode('::', $callable);
            if (count($parts) != 2) throw new \RuntimeException("Invalid callable format for '$callable', expected single '::'");
            list($obj, $method) = $parts;
        } else if (is_array($callable)) {
            if (count($callable) != 2) throw new \RuntimeException("Invalid callable format, expected array of exactly 2 elements");
            list($obj, $method) = $callable;
        } else {
            return call_user_func_array($callable, $args);
        }

        if (is_object($obj)) {
            return self::callMethod($obj, null, $method, $args, true);
        } else if (is_scalar($obj)) {
            return self::callStaticMethod($obj, $method, $args, true);
        }

        throw new \RuntimeException("Invalid callable format, expected first array element to be object or scalar, " . gettype($obj) . " given");
    }

    public static function isCallable($callable)
    {
        if (empty($callable)) {
            return false;
        }

        if (is_array($callable) && sizeof($callable) === 2) {
            if (is_object($callable[0])) {
                $class = get_class($callable[0]);
            } else {
                $class = $callable[0];
            }

            if (isset(self::$mocks[$class][$callable[1]])) {
                return true;
            }

            return is_callable($callable);
        }

        if (is_scalar($callable) && isset(self::$func_mocks[$callable])) {
            return true;
        }

        return is_callable($callable);
    }

    public static function constDefined($const)
    {
        if (isset(self::$removed_constants[$const])) return false;
        return defined($const) || isset(self::$constant_mocks[$const]);
    }

    public static function callMethod($obj, $class, $method, $args, $check_mock = false)
    {
        if (!$class) $class = get_class($obj);
        if ($check_mock && isset(self::$mocks[$class][$method])) {
            if (self::$debug) self::debug("Intercepting call to $class->$method");
            return (new SoftMocksFunctionCreator())->run($obj, $class, $args, self::$mocks[$class][$method]);
        }

        try {
            $Rm = new \ReflectionMethod($class, $method);
            $Rm->setAccessible(true);

            $decl_class = $Rm->getDeclaringClass()->getName();
            if ($check_mock && isset(self::$mocks[$decl_class][$method])) {
                if (self::$debug) self::debug("Intercepting call to $class->$method");
                return (new SoftMocksFunctionCreator())->run($obj, $class, $args, self::$mocks[$decl_class][$method]);
            }
        } catch (\ReflectionException $e) {
            if (method_exists($obj, '__call')) {
                $Rm = new \ReflectionMethod($obj, '__call');
                $Rm->setAccessible(true);
                return $Rm->invokeArgs($obj, [$method, $args]);
            }

            return call_user_func_array([$obj, $method], $args); // give up, got some weird shit
        }

        return $Rm->invokeArgs($obj, $args);
    }

    public static function callStaticMethod($class, $method, $args, $check_mock = false)
    {
        if ($check_mock && isset(self::$mocks[$class][$method])) {
            if (self::$debug) self::debug("Intercepting call to $class::$method");
            return (new SoftMocksFunctionCreator())->run(null, $class, $args, self::$mocks[$class][$method]);
        }

        try {
            $Rm = new \ReflectionMethod($class, $method);
            $Rm->setAccessible(true);

            $decl_class = $Rm->getDeclaringClass()->getName();

            if ($check_mock && isset(self::$mocks[$decl_class][$method])) {
                if (self::$debug) self::debug("Intercepting call to $class::$method");
                return (new SoftMocksFunctionCreator())->run(null, $class, $args, self::$mocks[$decl_class][$method]);
            }
        } catch (\ReflectionException $e) {
            if (method_exists($class, '__callStatic')) {
                $Rm = new \ReflectionMethod($class, '__callStatic');
                $Rm->setAccessible(true);
                return $Rm->invokeArgs(null, [$method, $args]);
            }

            return call_user_func_array([$class, $method], $args);
        }

        return $Rm->invokeArgs(null, $args);
    }

    public static function callFunction($namespace, $func, $params)
    {
        if ($namespace !== '' && is_scalar($func)) {
            $ns_func = $namespace . '\\' . $func;
            if (isset(self::$func_mocks[$ns_func])) {
                if (self::$debug) self::debug("Intercepting call to $ns_func");
                if (self::$func_mocks[$ns_func]['code'] instanceof \Closure) {
                    $func_callable = self::$func_mocks[$ns_func]['code'];
                } else {
                    $func_callable = eval("return function(" . self::$func_mocks[$ns_func]['args'] . ") use (\$params) { " . self::$func_mocks[$ns_func]['code'] . " };");
                }

                return call_user_func_array($func_callable, $params);
            }

            if (is_callable($ns_func)) {
                return call_user_func_array($ns_func, $params);
            }
        }
        if (is_scalar($func)) {
            if (isset(self::$func_mocks[$func])) {
                if (self::$debug) self::debug("Intercepting call to $func");
                if (self::$func_mocks[$func]['code'] instanceof \Closure) {
                    $func_callable = self::$func_mocks[$func]['code'];
                } else {
                    $func_callable = eval("return function(" . self::$func_mocks[$func]['args'] . ") use (\$params) { " . self::$func_mocks[$func]['code'] . " };");
                }
                return call_user_func_array($func_callable, $params);
            }
        }
        return call_user_func_array($func, $params);
    }

    public static function callNew($class, $args)
    {
        if (isset(self::$new_mocks[$class])) {
            return call_user_func_array(self::$new_mocks[$class], $args);
        }

        $Rc = new \ReflectionClass($class);
        $Constructor = $Rc->getConstructor();

        if ($Constructor && !$Constructor->isPublic()) {
            $instance = $Rc->newInstanceWithoutConstructor();
            $Constructor->setAccessible(true);
            $Constructor->invokeArgs($instance, $args);
        } else {
            $instance = $Rc->newInstanceArgs($args);
        }

        return $instance;
    }

    public static function getConst($namespace, $const)
    {
        if ($namespace !== '') {
            $ns_const = $namespace . '\\' . $const;
            if (array_key_exists($ns_const, self::$constant_mocks)) {
                if (self::$debug) self::debug("Mocked $ns_const");
                return self::$constant_mocks[$ns_const];
            }

            if (defined($ns_const)) {
                return constant($ns_const);
            }
        }

        if (array_key_exists($const, self::$constant_mocks)) {
            if (self::$debug) self::debug("Mocked $const");
            return self::$constant_mocks[$const];
        }

        if (array_key_exists($const, self::$removed_constants)) {
            trigger_error('Trying to access removed constant ' . $const . ', assuming "' . $const . '"');
            return $const;
        }

        return constant($const);
    }

    public static function getClassConst($class, $const)
    {
        if (is_object($class)) $class = get_class($class);
        $const = $class . '::' . $const;

        if (isset(self::$constant_mocks[$const])) {
            if (self::$debug) self::debug("Intercepting constant $const");
            return self::$constant_mocks[$const];
        }

        if (isset(self::$removed_constants[$const])) {
            trigger_error('Trying to access removed constant ' . $const . ', assuming "' . $const . '"');
            return $const;
        }

        return constant($const);
    }

    private static function rewriteContents($orig_file, $target_file, $contents)
    {
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor(new SoftMocksTraverser($orig_file));

        $prettyPrinter = new SoftMocksPrinter();
        $parser = (new \PhpParser\ParserFactory)->create(\PhpParser\ParserFactory::PREFER_PHP7);
        $stmts = $parser->parse($contents);
        $stmts = $traverser->traverse($stmts);

        return $prettyPrinter->prettyPrintFile($stmts);
    }

    public static function ignoreFiles($files)
    {
        foreach ($files as $f) {
            if (self::$debug) self::debug("Asked to ignore $f");
            self::$ignore[$f] = true;
        }
    }

    public static function redefineFunction($func, $functionArgs, $fakeCode)
    {
        if (self::$debug) self::debug("Asked to redefine $func($functionArgs)");
        if (!self::$rewrite_internal && isset(self::$internal_func_mocks[$func])) {
            throw new \RuntimeException("Function $func is mocked internally, cannot mock");
        }
        if (SoftMocksTraverser::isFunctionIgnored($func)) {
            throw new \RuntimeException("Function $func cannot be mocked using Soft Mocks");
        }
        self::$func_mocks[$func] = ['args' => $functionArgs, 'code' => $fakeCode];
    }

    public static function restoreFunction($func)
    {
        if (isset(self::$internal_func_mocks[$func])) {
            if (!self::$rewrite_internal) {
                throw new \RuntimeException("Function $func is mocked internally, cannot unmock");
            }
            self::$func_mocks[$func] = self::$internal_func_mocks[$func];
            return;
        }

        unset(self::$func_mocks[$func]);
    }

    public static function restoreAll()
    {
        self::$mocks = [];
        self::$generator_mocks = [];
        self::$func_mocks = self::$internal_func_mocks;
        self::$temp_disable = false;
        self::restoreAllConstants();
        self::restoreAllNew();
    }

    /**
     * Redefine method $class::$method with args list specified by $functionArgs
     * (args list must be compatible with original function, you can specify only variable names)
     * by using code $fakeCode instead of original function code.
     *
     * There are two already defined variables that you can use in fake code:
     *  $mm_func_args = func_get_args();
     *  $params is array of references to supplied arguments (func_get_args() does not contain refs in PHP5)
     *
     * You can use SoftMocks::callOriginal(...) for accessing original function/method as well
     *
     * Example:
     *
     *  class A { public function b($c, &$d) { var_dump($c, $d); } }
     *
     *  SoftMocks::redefineMethod(A::class, 'b', '$e, &$f', '$f = "hello";');
     *  $a = 2;
     *  (new A())->b(1, $a); // nothing is printed here, so we intercepted the call
     *  var_dump($a); // string(5) "hello"
     *
     * @param string $class
     * @param string $method         Method of class to be intercepted
     * @param string $functionArgs   List of argument names
     * @param string $fakeCode       Code that will be eval'ed instead of function code
     * @param bool $strict           If strict=false then method of declaring class will also be mocked
     */
    public static function redefineMethod($class, $method, $functionArgs, $fakeCode, $strict = true)
    {
        if (self::$debug) self::debug("Asked to redefine $class::$method($functionArgs)");
        if (SoftMocksTraverser::isClassIgnored($class)) {
            throw new \RuntimeException("Class $class cannot be mocked using Soft Mocks");
        }

        self::$mocks[$class][$method] = ['args' => $functionArgs, 'code' => $fakeCode];

        try {
            $Rm = new \ReflectionMethod($class, $method);
            self::$mocks[$class][$method]['code'] = self::generateCode($functionArgs, $Rm) . self::$mocks[$class][$method]['code'];
            if ($strict) return;

            $Dc = $Rm->getDeclaringClass();

            if ($Dc) {
                if ($Dc->getTraits() && ($DeclaringTrait = self::getDeclaringTrait($Dc->getName(), $method))) {
                    $Dc = $DeclaringTrait;
                }

                $decl_class = $Dc->getName();

                // do not mock declaring class again if there already exists a proper mock for it
                $no_mock_for_parent = empty(self::$mocks[$decl_class][$method]);
                $installed_from_current_mock = false;
                if (isset(self::$mocks[$class][$method]['installed_by']) && self::$mocks[$class][$method]['installed_by'] === $class) {
                    $installed_from_current_mock = true;
                }
                if ($no_mock_for_parent || $installed_from_current_mock) {
                    if (self::$debug) self::debug("Redefine also $decl_class::$method($functionArgs)");
                    self::$mocks[$decl_class][$method] = self::$mocks[$class][$method] + ['installed_by' => $class];
                    self::$mocks[$class][$method]['decl_class'] = $decl_class;
                }
            }
        } catch (\Exception $e) {
            self::$mocks[$class][$method]['code'] = self::generateCode($functionArgs, null) . self::$mocks[$class][$method]['code'];

            if (self::$debug) self::debug("Could not new ReflectionMethod($class, $method), cannot accept function calls by reference: " . $e);
        }
    }

    public static function restoreMethod($class, $method)
    {
        if (self::$debug) self::debug("Restore method $class::$method");
        if (isset(self::$mocks[$class][$method]['decl_class'])) {
            if (self::$debug) self::debug("Restore also method $class::$method");
            unset(self::$mocks[self::$mocks[$class][$method]['decl_class']][$method]);
        }
        unset(self::$mocks[$class][$method]);
    }

    public static function redefineGenerator($class, $method, \Generator $replacement)
    {
        self::$generator_mocks[$class][$method] = $replacement;
    }

    public static function restoreGenerator($class, $method)
    {
        unset(self::$generator_mocks[$class][$method]);
    }

    public static function isGeneratorMocked($class, $method)
    {
        return isset(self::$generator_mocks[$class][$method]);
    }

    public static function getMockForGenerator($class, $method)
    {
        if (!isset(self::$generator_mocks[$class][$method])) {
            throw new \RuntimeException("Generator $class::$method is not mocked");
        }

        return self::$generator_mocks[$class][$method];
    }

    public static function redefineNew($class, Callable $constructorFunc)
    {
        self::$new_mocks[$class] = $constructorFunc;
    }

    public static function restoreNew($class)
    {
        unset(self::$new_mocks[$class]);
    }

    public static function restoreAllNew()
    {
        self::$new_mocks = [];
    }

    public static function redefineConstant($constantName, $value)
    {
        $constantName = ltrim($constantName, '\\');
        if (self::$debug) self::debug("Asked to redefine constant $constantName to $value");

        if (SoftMocksTraverser::isConstIgnored($constantName)) {
            throw new \RuntimeException("Constant $constantName cannot be mocked using Soft Mocks");
        }

        self::$constant_mocks[$constantName] = $value;
    }

    public static function restoreConstant($constantName)
    {
        unset(self::$constant_mocks[$constantName]);
    }

    public static function restoreAllConstants()
    {
        self::$constant_mocks = [];
        self::$removed_constants = [];
    }

    public static function removeConstant($constantName)
    {
        unset(self::$constant_mocks[$constantName]);
        self::$removed_constants[$constantName] = true;
    }

    // there can be a situation when usage of static is not suitable for mocking so we need additional checks here
    // see \QA\SoftMocks\SoftMocksTest::testParentMismatch to see when getDeclaringClass check is needed
    private static function staticContextIsOk($self, $static, $method)
    {
        try {
            $Rm = new \ReflectionMethod($static, $method);
            $Dc = $Rm->getDeclaringClass();
            if (!$Dc) {
                if (self::$debug) self::debug("Failed to get geclaring class for $static::$method");
                return false;
            }

            $decl_class = $Dc->getName();
            if ($decl_class === $self) {
                return true;
            }

            // In PHP 5.5 the declared class is actually correct class, but it never a trait.
            // So we need to find the actual trait then if it is applicable to the class
            $Dt = self::getDeclaringTrait($decl_class, $method);
            if (!$Dt) {
                if (self::$debug) self::debug("Failed to get geclaring trait for $static::$method ($decl_class::$method");
                return false;
            }

            if ($Dt->getName() === $self) {
                return true;
            }
        } catch (\ReflectionException $e) {
            if (self::$debug) self::debug("Failed to get reflection method for $static::$method: $e");
        }

        return false;
    }

    private static function getDeclaringTrait($class, $method)
    {
        $Rc = new \ReflectionClass($class);

        foreach (self::recursiveGetTraits($Rc) as $Trait) {
            if ($Trait->hasMethod($method)) {
                return $Trait;
            }
        }

        return null;
    }

    /**
     * @param \ReflectionClass $Rc
     * @return \ReflectionClass[]
     */
    private static function recursiveGetTraits(\ReflectionClass $Rc)
    {
        foreach ($Rc->getTraits() as $Trait) {
            yield $Trait;

            foreach (self::recursiveGetTraits($Trait) as $T) {
                yield $T;
            }
        }
    }

    public static function isMocked($self, $static, $method)
    {
        if (self::$temp_disable) {
            if (self::$debug) self::debug("Temporarily disabling mock check: $self::$method (static = $static)");
            self::$temp_disable = false;
            return false;
        }

        if (isset(self::$mocks[$static][$method]) && self::staticContextIsOk($self, $static, $method)) {
            return true;
        }

        // it is very hard to make "self" work incorrectly because "self" is just an alias for class name at compile time
        return isset(self::$mocks[$self][$method]);
    }

    public static function callOriginal($callable, $args, $class = null)
    {
        if (is_array($callable)) {
            if (is_object($callable[0])) {
                $obj = $callable[0];
                if (!$class) $class = get_class($obj);
            } else {
                $class = $callable[0];
            }

            $method = $callable[1];
        } else if (is_scalar($callable) && strpos($callable, '::') !== false) {
            list($class, $method) = explode("::", $callable);
        } else {
            return call_user_func_array($callable, $args);
        }

        try {
            $Rm = new \ReflectionMethod($class, $method);
            if ($Rm->isUserDefined()) {
                self::$temp_disable = true; // we can only mock and check for mocks for user defined methods
            }
        } catch (\ReflectionException $e) {
            // do nothing, it is ok in this case because it means that mock disabling is not needed
        }

        if (isset($obj)) {
            return self::callMethod($obj, $class, $method, $args);
        } else {
            return self::callStaticMethod($class, $method, $args);
        }
    }

    public static function getMockCode($self, $static, $method)
    {
        if (isset(self::$mocks[$static][$method]) && self::staticContextIsOk($self, $static, $method)) {
            if (self::$debug) self::debug("Intercepting $static::$method (static scope. self = $static)");
            $mock = &self::$mocks[$static][$method];
        } else if (isset(self::$mocks[$self][$method])) {
            if (self::$debug) self::debug("Intercepting $self::$method (self scope. static = $static)");
            $mock = &self::$mocks[$self][$method];
        } else {
            throw new \RuntimeException("No mock code for class $static::$method");
        }
        return $mock['code'];
    }

    /**
     * Generate code that parses function parameters that are specified as string $args
     *
     * @warning For internal use only, except \QA\OriginalChainCaller
     * @param $args
     * @param \ReflectionMethod|null $Rm
     * @return string
     */
    public static function generateCode($args, $Rm)
    {
        $args = trim($args);
        if (!$args) return '';

        $codeArgs = '';

        $list = token_get_all("<?php " . $args);
        $params_toks = [];
        $i = 0;
        foreach ($list as $tok) {
            if ($tok === ',') {
                $i++;
                continue;
            }
            $params_toks[$i][] = $tok;
        }

        if ($Rm) {
            $params = $Rm->getParameters();
        } else {
            $params = [];
        }

        foreach ($params_toks as $i => $toks) {
            $isRef = false;
            $varName = false;
            $haveDefault = false;
            $default = "";
            $mode = 'var';

            foreach ($toks as $tok) {
                if ($tok === '&') {
                    $isRef = true;
                    continue;
                }

                if ($tok === '=') {
                    $haveDefault = true;
                    $mode = 'default';
                    continue;
                }

                if ($mode == 'default') {
                    $default .= is_array($tok) ? $tok[1] : $tok;
                    continue;
                }

                if ($tok[0] === T_VARIABLE) {
                    $varName = $tok[1];
                }
            }

            if ($haveDefault) {
                $codeArgs .= "if (count(\$mm_func_args) > $i) {\n";
            }

            if ($isRef && isset($params[$i])) {
                $param_name = $params[$i]->getName();
                if (ltrim($varName, '$') !== $param_name) {
                    $codeArgs .= "$varName = &\$$param_name;\n";
                }
            } else {
                $codeArgs .= "$varName = \$params[$i];\n";
            }

            if ($haveDefault) {
                $codeArgs .= "} else {\n";
                $codeArgs .= "$varName = $default;\n";
                $codeArgs .= "}\n";
            }
        }

        if (self::$debug) {
            echo "Compiled code for " . $Rm->getDeclaringClass()->getName() . "::" . $Rm->getName() . " ($args)\n===========\n";
            echo $codeArgs;
            echo "===========\n";
            ob_flush();
        }

        return $codeArgs;
    }

    protected static function debug($message)
    {
        echo $message . "\n";
    }

    private static function injectIntoPhpunit()
    {
        if (!class_exists(\PHPUnit_Util_Fileloader::class, false)) {
            return;
        }

        if (!is_callable([\PHPUnit_Util_Fileloader::class, 'setFilenameRewriteCallback'])) {
            if (self::$debug) self::debug("Cannot inject into phpunit: method setFilenameRewriteCallback not found");
            return;
        }

        \PHPUnit_Util_Fileloader::setFilenameRewriteCallback([self::class, 'rewrite']);

        \PHPUnit_Util_Fileloader::setFilenameRestoreCallback(
            function ($filename) {
                return self::replaceFilename($filename, true);
            }
        );

        \PHPUnit_Util_Filter::setCustomStackTraceCallback(
            function ($e) {
                ob_start();
                self::printBackTrace($e);
                return ob_get_clean();
            }
        );
    }
}

class SoftMocksTraverser extends \PhpParser\NodeVisitorAbstract
{
    private static $ignore_functions = [
        "get_called_class" => true,
        "get_parent_class" => true,
        "get_class" => true,
        "extract" => true,
        "func_get_args" => true,
        "func_get_arg" => true,
        "func_num_args" => true,
        "parse_str" => true,
        "usort" => true,
        "uasort" => true,
        "uksort" => true,
        "array_walk_recursive" => true,
        "array_filter" => true,
        "compact" => true,
        "strtolower" => true,
        "strtoupper" => true,
        "get_object_vars" => true,
    ];

    private static $ignore_classes = [
        \ReflectionClass::class => true,
        \ReflectionMethod::class => true,
    ];

    private static $ignore_constants = [
        'false' => true,
        'true'  => true,
        'null'  => true,
    ];

    public static function isFunctionIgnored($func)
    {
        return isset(self::$ignore_functions[$func]);
    }

    public static function isClassIgnored($class)
    {
        return isset(self::$ignore_classes[$class]);
    }

    public static function isConstIgnored($const)
    {
        return isset(self::$ignore_constants[$const]);
    }

    public static function ignoreClass($class)
    {
        self::$ignore_classes[$class] = true;
    }

    public static function ignoreConstant($constant)
    {
        self::$ignore_constants[$constant] = true;
    }

    public static function ignoreFunction($function)
    {
        self::$ignore_functions[$function] = true;
    }

    private $filename;

    private $disable_const_rewrite_level = 0;

    private $in_interface = false;
    private $has_yield = false;
    private $cur_class = false;

    public function __construct($filename)
    {
        $this->filename = realpath($filename);
        if (strpos($this->filename, SOFTMOCKS_ROOT_PATH) === 0) {
            $this->filename = substr($this->filename, strlen(SOFTMOCKS_ROOT_PATH));
        }
    }

    private static function getNamespaceArg()
    {
        return new \PhpParser\Node\Arg(
            new \PhpParser\Node\Expr\ConstFetch(
                new \PhpParser\Node\Name('__NAMESPACE__')
            )
        );
    }

    public function enterNode(\PhpParser\Node $Node)
    {
        $callback = [$this, 'before' . ucfirst($Node->getType())];
        if (is_callable($callback)) {
            return call_user_func_array($callback, [$Node]);
        }
        return null;
    }

    public function leaveNode(\PhpParser\Node $Node)
    {
        $callback = [$this, 'rewrite' . ucfirst($Node->getType())];
        if (is_callable($callback)) {
            return call_user_func_array($callback, [$Node]);
        }
        return null;
    }

    // Cannot rewrite constants that are used as default values in function arguments
    public function beforeParam()
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteParam()
    {
        $this->disable_const_rewrite_level--;
    }

    // Cannot rewrite constants that are used as default values in constant declarations
    public function beforeConst()
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteConst()
    {
        $this->disable_const_rewrite_level--;
    }

    // Cannot rewrite constants that are used as default values in property declarations
    public function beforeStmt_PropertyProperty()
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteStmt_PropertyProperty()
    {
        $this->disable_const_rewrite_level--;
    }

    // Cannot rewrite constants that are used as default values in static variable declarations
    public function beforeStmt_StaticVar()
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteStmt_StaticVar()
    {
        $this->disable_const_rewrite_level--;
    }

    public function beforeStmt_Interface(\PhpParser\Node\Stmt\Interface_ $Node)
    {
        $this->cur_class = $Node->name;
        $this->in_interface = true;
    }

    public function rewriteStmt_Interface()
    {
        $this->in_interface = false;
    }

    public function rewriteScalar_MagicConst_Dir()
    {
        $String = new \PhpParser\Node\Scalar\String_(dirname($this->filename));
        if ($this->filename[0] === '/') { // absolute path
            return $String;
        }

        return new \PhpParser\Node\Expr\BinaryOp\Concat(
            new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('SOFTMOCKS_ROOT_PATH')),
            $String
        );
    }

    public function rewriteScalar_MagicConst_File()
    {
        $String = new \PhpParser\Node\Scalar\String_($this->filename);
        if ($this->filename[0] === '/') { // absolute path
            return $String;
        }

        return new \PhpParser\Node\Expr\BinaryOp\Concat(
            new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('SOFTMOCKS_ROOT_PATH')),
            $String
        );
    }

    public function rewriteExpr_Include(\PhpParser\Node\Expr\Include_ $Node)
    {
        $Node->expr = new \PhpParser\Node\Expr\StaticCall(
            new \PhpParser\Node\Name("\\" . SoftMocks::class),
            "rewrite",
            [new \PhpParser\Node\Arg($Node->expr)]
        );
    }

    public function beforeStmt_ClassMethod()
    {
        $this->has_yield = false;
    }

    public function beforeExpr_Yield()
    {
        $this->has_yield = true;
    }

    public function beforeExpr_YieldFrom()
    {
        $this->has_yield = true;
    }

    public function beforeStmt_Class(\PhpParser\Node\Stmt\Class_ $Node)
    {
        $this->cur_class = $Node->name;
    }

    public function beforeStmt_Trait(\PhpParser\Node\Stmt\Trait_ $Node)
    {
        $this->cur_class = $Node->name;
    }

    public function rewriteStmt_ClassMethod(\PhpParser\Node\Stmt\ClassMethod $Node)
    {
        if ($this->in_interface) return null;

        // if (SoftMocks::isMocked(self::class, static::class, __FUNCTION__)) {
        //     $params = [/* variables with references to them */];
        //     $mm_func_args = func_get_args();
        //     return eval(SoftMocks::getMockCode(self::class, static::class, __FUNCTION__));
        // }
        $static = new \PhpParser\Node\Arg(
            new \PhpParser\Node\Expr\ClassConstFetch(
                new \PhpParser\Node\Name("static"),
                "class"
            )
        );

        $function = new \PhpParser\Node\Expr\ConstFetch(
            new \PhpParser\Node\Name("__FUNCTION__")
        );

        $params_arr = [];
        foreach ($Node->params as $Par) {
            $params_arr[] = new \PhpParser\Node\Expr\ArrayItem(
                new \PhpParser\Node\Expr\Variable($Par->name),
                null,
                $Par->byRef
            );
        }

        $body_stmts = [
            new \PhpParser\Node\Expr\Assign(
                new \PhpParser\Node\Expr\Variable("mm_func_args"),
                new \PhpParser\Node\Expr\FuncCall(new \PhpParser\Node\Name("func_get_args"))
            ),
            new \PhpParser\Node\Expr\Assign(
                new \PhpParser\Node\Expr\Variable("params"),
                new \PhpParser\Node\Expr\Array_($params_arr)
            ),
        ];

        // generators cannot return values,
        // we need special code handling them because yield cannot be used inside eval
        // we get something like the following:
        //
        // $mm_callback = SoftMocks::getMockForGenerator();
        // foreach ($mm_callback(...) as $mm_val) { yield $mm_val; }
        if ($this->has_yield) {
            $args = [$static, $function];

            $body_stmts[] = new \PhpParser\Node\Expr\Assign(
                new \PhpParser\Node\Expr\Variable("mm_callback"),
                new \PhpParser\Node\Expr\StaticCall(
                    new \PhpParser\Node\Name("\\" . SoftMocks::class),
                    "getMockForGenerator",
                    $args
                )
            );

            $func_call_args = [];
            foreach ($Node->params as $Par) {
                $func_call_args[] = new \PhpParser\Node\Arg(new \PhpParser\Node\Expr\Variable($Par->name));
            }

            $val = new \PhpParser\Node\Expr\Variable("mm_val");

            $body_stmts[] = new \PhpParser\Node\Stmt\Foreach_(
                new \PhpParser\Node\Expr\FuncCall(
                    new \PhpParser\Node\Expr\Variable("mm_callback"),
                    $func_call_args
                ),
                $val,
                [
                    'byRef' => $Node->byRef,
                    'stmts' => [new \PhpParser\Node\Expr\Yield_($val)],
                ]
            );

            $body_stmts[] = new \PhpParser\Node\Stmt\Return_();
        } else {
            $args = [
                new \PhpParser\Node\Arg(
                    new \PhpParser\Node\Expr\ClassConstFetch(
                        new \PhpParser\Node\Name($this->cur_class),
                        "class"
                    )
                ),
                $static,
                $function,
            ];

            $body_stmts[] = new \PhpParser\Node\Stmt\Return_(
                new \PhpParser\Node\Expr\Eval_(
                    new \PhpParser\Node\Expr\StaticCall(
                        new \PhpParser\Node\Name("\\" . SoftMocks::class),
                        "getMockCode",
                        $args
                    )
                )
            );
        }

        $MockCheck = new \PhpParser\Node\Stmt\If_(
            new \PhpParser\Node\Expr\StaticCall(
                new \PhpParser\Node\Name("\\" . SoftMocks::class),
                $this->has_yield ? "isGeneratorMocked" : "isMocked",
                $args
            ),
            [
                'stmts' => $body_stmts,
            ]
        );

        if (is_array($Node->stmts)) {
            array_unshift($Node->stmts, $MockCheck, new \PhpParser\Node\Name("/** @codeCoverageIgnore */"));
        } else if (!$Node->isAbstract()) {
            $Node->stmts = [$MockCheck, new \PhpParser\Node\Name("/** @codeCoverageIgnore */")];
        }
    }

    static $can_ref = [
        'Expr_Variable' => true,
        'Expr_PropertyFetch' => true,
        'Expr_StaticPropertyFetch' => true,
    ];

    /**
     * Determines whether or not a parameter can be reference (e.g. vars can be referenced, while function calls cannot)
     *
     * @param \PhpParser\Node\Expr $value
     * @return bool
     */
    private static function canRef($value)
    {
        $type = $value->getType();
        if (isset(self::$can_ref[$type])) {
            return true;
        } else if ($value instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
            if (!self::canRef($value->var)) {
                return false;
            }

            // an ugly hack for ArrayAccess objects that are used as "$this['something']"
            if ($value->var instanceof \PhpParser\Node\Expr\Variable && $value->var->name == 'this') {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * @param $node_args
     * @param array $arg_is_ref    array(arg_idx => bool)   whether or not the specified argument accepts reference
     * @return \PhpParser\Node\Expr\Array_
     */
    private static function nodeArgsToArray($node_args, $arg_is_ref = [])
    {
        $arr_args = [];
        $i = 0;

        foreach ($node_args as $arg) {
            /** @var \PhpParser\Node\Expr\ArrayItem $arg */
            $is_ref = false;

            if (isset($arg_is_ref[$i]) && !$arg_is_ref[$i]) {
                $is_ref = false;
            } else if (self::canRef($arg->value)) {
                $is_ref = true;
            }

            $arr_args[] = new \PhpParser\Node\Expr\ArrayItem(
                $arg->value,
                null,
                $is_ref
            );

            $i++;
        }

        return new \PhpParser\Node\Expr\Array_($arr_args);
    }

    private static function nodeNameToArg($name)
    {
        if (is_scalar($name)) {
            $name = new \PhpParser\Node\Scalar\String_($name);
        } else if ($name instanceof \PhpParser\Node\Name) {
            return new \PhpParser\Node\Arg(new \PhpParser\Node\Expr\ClassConstFetch($name, 'class'));
        }

        return new \PhpParser\Node\Arg($name);
    }


    public function rewriteExpr_FuncCall(\PhpParser\Node\Expr\FuncCall $Node)
    {
        $arg_is_ref = [];

        if ($Node->name instanceof \PhpParser\Node\Name) {
            $str = $Node->name->toString();
            if (isset(self::$ignore_functions[$str])) {
                return null;
            }

            if (isset(SoftMocks::$internal_functions[$str])) {
                foreach ((new \ReflectionFunction($str))->getParameters() as $Param) {
                    $arg_is_ref[] = $Param->isPassedByReference();
                }
            }

            $name = new \PhpParser\Node\Scalar\String_($str);
        } else { // Expr
            $name = $Node->name;
        }

        $NewNode = new \PhpParser\Node\Expr\StaticCall(
            new \PhpParser\Node\Name("\\" . SoftMocks::class),
            "callFunction",
            [
                self::getNamespaceArg(),
                $name,
                self::nodeArgsToArray($Node->args, $arg_is_ref),
            ]
        );
        $NewNode->setLine($Node->getLine());

        return $NewNode;
    }

    public function rewriteExpr_ConstFetch(\PhpParser\Node\Expr\ConstFetch $Node)
    {
        if ($this->disable_const_rewrite_level > 0) {
            return null;
        }

        $name = $Node->name->toString();

        if (isset(self::$ignore_constants[strtolower($name)])) {
            return null;
        }

        $NewNode = new \PhpParser\Node\Expr\StaticCall(
            new \PhpParser\Node\Name("\\" . SoftMocks::class),
            "getConst",
            [
                self::getNamespaceArg(),
                self::nodeNameToArg($name),
            ]
        );

        $NewNode->setLine($Node->getLine());
        return $NewNode;
    }

    public function rewriteExpr_ClassConstFetch(\PhpParser\Node\Expr\ClassConstFetch $Node)
    {
        if ($this->disable_const_rewrite_level > 0 || strtolower($Node->name) == 'class') {
            return null;
        }

        $NewNode = new \PhpParser\Node\Expr\StaticCall(
            new \PhpParser\Node\Name("\\" . SoftMocks::class),
            "getClassConst",
            [
                self::nodeNameToArg($Node->class),
                self::nodeNameToArg($Node->name)
            ]
        );

        $NewNode->setLine($Node->getLine());
        return $NewNode;
    }
}
