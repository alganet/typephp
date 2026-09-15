<?php

namespace TypePhp\Tests\Build;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TypePhp\CompilerTest;

final class IncrementalDeclarationTest extends TestCase
{
    private string $directory;
    private string $buildDirectory;
    private string $provider;
    private string $consumer;
    private string $independent;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/typephp_incremental_' . bin2hex(random_bytes(8));
        $this->buildDirectory = $this->directory . '/build';
        mkdir($this->directory, 0777, true);
        $this->provider = $this->directory . '/provider.php';
        $this->consumer = $this->directory . '/consumer.php';
        $this->independent = $this->directory . '/independent.php';
        file_put_contents($this->provider, <<<'PHP'
<?php
namespace Incremental;

const LIMIT = 42;

function answer(): int
{
    global $shared;
    $shared = LIMIT;
    return LIMIT;
}
PHP);
        file_put_contents($this->independent, <<<'PHP'
<?php

function independent(): string
{
    return 'stable literal';
}
PHP);
        file_put_contents($this->consumer, <<<'PHP'
<?php

function main(): int
{
    global $shared;
    return \Incremental\answer() + \Incremental\LIMIT;
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testDeclarationsAreSplitBySourceAndDependenciesAreIncluded(): void
    {
        $compiler = $this->convertProject();
        $providerHeader = $compiler->getDeclarationHeaderFile($this->provider);
        $consumerHeader = $compiler->getDeclarationHeaderFile($this->consumer);
        $consumerCpp = $this->invoke($compiler, 'getCppFile', $this->consumer);

        self::assertFileExists($providerHeader);
        self::assertFileExists($consumerHeader);
        self::assertFileExists($this->buildDirectory . '/include/php_incremental_runtime_decl.h');
        self::assertFileExists($this->buildDirectory . '/include/php_incremental_all_decl.h');
        self::assertFileDoesNotExist($this->buildDirectory . '/include/php_incremental_func_decl.h');
        self::assertFileDoesNotExist($this->buildDirectory . '/include/php_incremental_data_decl.h');

        $providerDeclarations = (string) file_get_contents($providerHeader);
        $consumerDeclarations = (string) file_get_contents($consumerHeader);
        $runtimeDeclarations = (string) file_get_contents(
            $this->buildDirectory . '/include/php_incremental_runtime_decl.h',
        );
        $consumerCode = (string) file_get_contents($consumerCpp);
        self::assertStringContainsString('php_incremental__answer(', $providerDeclarations);
        self::assertStringContainsString('_const_var_Incremental__LIMIT', $providerDeclarations);
        self::assertStringContainsString('_global_var_shared', $providerDeclarations);
        self::assertStringNotContainsString('_global_var_shared', $consumerDeclarations);
        self::assertStringNotContainsString('_global_var_shared', $runtimeDeclarations);
        self::assertStringNotContainsString('php_main(', $providerDeclarations);
        self::assertStringContainsString('php_main(', $consumerDeclarations);
        self::assertStringContainsString(
            '#include <' . basename($providerHeader) . '>',
            $consumerDeclarations,
        );
        self::assertStringContainsString(
            '#include <' . basename($consumerHeader) . '>',
            $consumerCode,
        );

        $symbols = $this->property($compiler, 'symbolDeclInFile');
        self::assertSame($this->provider, $symbols['function:incremental\\answer']);
        self::assertSame($this->provider, $symbols['constant:Incremental\\LIMIT']);
    }

    public function testUnchangedGeneratedCppKeepsItsTimestamp(): void
    {
        $first = $this->convertProject();
        $consumerCpp = $this->invoke($first, 'getCppFile', $this->consumer);
        $oldTimestamp = 1_600_000_000;
        touch($consumerCpp, $oldTimestamp);
        clearstatcache(true, $consumerCpp);

        $this->convertProject();

        clearstatcache(true, $consumerCpp);
        self::assertSame($oldTimestamp, filemtime($consumerCpp));
        self::assertStringContainsString(
            'get_str(uint32_t index)',
            (string) file_get_contents($this->buildDirectory . '/include/php_incremental_runtime_decl.h'),
        );
    }

    public function testChangedDeclarationRegeneratesItsTransitiveConsumersOnly(): void
    {
        $first = $this->convertProject();
        $providerCpp = $this->invoke($first, 'getCppFile', $this->provider);
        $consumerCpp = $this->invoke($first, 'getCppFile', $this->consumer);
        $independentCpp = $this->invoke($first, 'getCppFile', $this->independent);
        $oldTimestamp = 1_600_000_000;
        foreach ([$providerCpp, $consumerCpp, $independentCpp] as $cppFile) {
            touch($cppFile, $oldTimestamp);
        }
        file_put_contents($this->provider, "\n// changed provider\n", FILE_APPEND);
        clearstatcache();

        $this->convertProject();

        clearstatcache();
        self::assertGreaterThan($oldTimestamp, filemtime($providerCpp));
        self::assertGreaterThan($oldTimestamp, filemtime($consumerCpp));
        self::assertSame($oldTimestamp, filemtime($independentCpp));
    }

    public function testGeneratedObjectCacheRequiresAnObjectNotOlderThanItsCppSource(): void
    {
        $compiler = $this->convertProject();
        $consumerCpp = $this->invoke($compiler, 'getCppFile', $this->consumer);
        $consumerObject = $compiler->getObjectFile($consumerCpp);
        $sourceTimestamp = 1_600_000_000;
        $objectTimestamp = $sourceTimestamp + 10;

        file_put_contents($consumerObject, 'object');
        touch($consumerCpp, $sourceTimestamp);
        touch($consumerObject, $objectTimestamp);
        clearstatcache();
        $this->invoke($compiler, 'writeGeneratedObjectCacheMetadata', $consumerCpp, $consumerObject);

        self::assertTrue(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        touch($consumerCpp, $objectTimestamp);
        clearstatcache();
        self::assertTrue(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        touch($consumerCpp, $objectTimestamp + 1);
        clearstatcache();
        self::assertFalse(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        touch($consumerCpp, $sourceTimestamp);
        unlink($consumerObject);
        clearstatcache();
        self::assertFalse(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );

        file_put_contents($consumerObject, 'object');
        touch($consumerObject, $objectTimestamp);
        unlink($consumerCpp);
        clearstatcache();
        self::assertFalse(
            $this->invoke($compiler, 'hasGeneratedObjectFileCache', $consumerCpp, $consumerObject),
        );
    }

    public function testLinkCacheRequiresEveryObjectAndAnUpToDateTarget(): void
    {
        $compiler = $this->newCompiler();
        $target = $this->directory . '/incremental-bin';
        $firstObject = $this->directory . '/first.o';
        $secondObject = $this->directory . '/second.o';
        $timestamp = 1_600_000_000;
        foreach ([$target, $firstObject, $secondObject] as $file) {
            file_put_contents($file, 'artifact');
            touch($file, $timestamp);
        }
        clearstatcache();

        $objects = [$firstObject, $secondObject];
        $this->invoke($compiler, 'writeLinkCache', $objects, $target);
        self::assertTrue($this->invoke($compiler, 'hasLinkCache', $objects, $target));

        touch($secondObject, $timestamp + 1);
        clearstatcache();
        self::assertFalse($this->invoke($compiler, 'hasLinkCache', $objects, $target));

        unlink($secondObject);
        clearstatcache();
        self::assertFalse($this->invoke($compiler, 'hasLinkCache', $objects, $target));
    }

    public function testForceCacheClearRemovesAstAndTargetIncrementalState(): void
    {
        $compiler = $this->newCompiler();
        $astDirectory = $this->buildDirectory . '/cache/ast';
        $incrementalDirectory = $this->buildDirectory . '/cache/incremental/incremental';
        mkdir($astDirectory, 0777, true);
        mkdir($incrementalDirectory, 0777, true);
        file_put_contents($astDirectory . '/entry.ast', 'ast');
        file_put_contents($incrementalDirectory . '/stable-ids.json', '{}');

        $this->invoke($compiler, 'clearIncrementalBuildCache');

        self::assertDirectoryDoesNotExist($astDirectory);
        self::assertDirectoryDoesNotExist($incrementalDirectory);
    }

    private function convertProject(): CompilerTest
    {
        global $translator;

        $compiler = $this->newCompiler();
        $translator = $compiler;
        $files = [$this->provider, $this->consumer, $this->independent];
        $compiler->addFiles([$this->directory]);
        foreach ($files as $file) {
            $compiler->prepareFile($file);
        }
        $files = $compiler->getSortedFiles($files);
        $this->invoke($compiler, 'initializeIncrementalCompilation', $files);
        $compiler->convert($files);
        return $compiler;
    }

    private function newCompiler(): CompilerTest
    {
        $compiler = CompilerTest::create($this->directory);
        // Exercise the same on-disk stable-ID registry used by real builds.
        $reflection = new ReflectionClass($compiler);
        $forTest = $reflection->getProperty('forTest');
        $forTest->setAccessible(true);
        $forTest->setValue($compiler, false);
        $compiler->setTargetName('incremental');
        $this->invoke($compiler, 'setBuildDir', $this->buildDirectory);
        return $compiler;
    }

    private function invoke(CompilerTest $compiler, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionClass($compiler);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);
        return $methodReflection->invoke($compiler, ...$arguments);
    }

    private function property(CompilerTest $compiler, string $name): mixed
    {
        $reflection = new ReflectionClass($compiler);
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        return $property->getValue($compiler);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
