<?php

namespace TypePhp\Build;

/** Project-level incremental planning kept separate from the language parser. */
trait IncrementalCompilationTrait
{
    private ?IncrementalBuildState $incrementalBuildState = null;
    private string $incrementalBuildStateFile = '';
    /** @var array<string, string> */
    private array $incrementalSourceHashes = [];
    /** @var array<string, list<string>> */
    private array $incrementalDependencies = [];
    /** @var array<string, true> */
    private array $incrementalDirtyFiles = [];
    /** @var array<string, array<string, mixed>> */
    private array $incrementalFileMetadata = [];
    /** @var array<string, bool> */
    private array $incrementalTranslationUnits = [];
    private string $incrementalGeneratorFingerprint = '';
    private bool $incrementalPlanInitialized = false;

    /** @param list<string> $files */
    protected function initializeIncrementalCompilation(array $files): void
    {
        $this->incrementalPlanInitialized = false;
        $this->incrementalFileMetadata = [];
        $this->incrementalTranslationUnits = [];
        $this->incrementalDirtyFiles = [];

        $phpFiles = [];
        foreach ($files as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $path = realpath($file);
            if ($path !== false) {
                $phpFiles[] = $path;
            }
        }
        sort($phpFiles, SORT_STRING);

        $this->incrementalSourceHashes = [];
        foreach ($phpFiles as $file) {
            $hash = hash_file('sha256', $file);
            if (!is_string($hash)) {
                throw new \RuntimeException('Cannot hash PHP source: ' . $file);
            }
            $this->incrementalSourceHashes[$file] = $hash;
        }
        $state = $this->getIncrementalBuildState();
        $this->restoreGlobalDependencyOwnersForPlanning($phpFiles, $state);
        $this->incrementalDependencies = $this->buildPhpFileDependencyGraph($phpFiles);
        $this->incrementalGeneratorFingerprint = $this->getIncrementalGeneratorFingerprint();
        $this->incrementalDirtyFiles = $state->dirtyFiles(
            $this->incrementalSourceHashes,
            $this->incrementalDependencies,
            $this->incrementalGeneratorFingerprint,
            function (string $source, ?array $metadata): bool {
                if (!is_array($metadata)
                    || !is_file($this->getDeclarationHeaderFile($source))
                    || !is_file($this->getArgInfoHeaderFile($source))) {
                    return false;
                }
                $emits = $metadata['emitsTranslationUnit'] ?? null;
                if (!is_bool($emits)) {
                    return false;
                }
                return !$emits || is_file($this->getCppFile($source));
            },
            $this->climate->arguments->defined('force'),
        );
        $this->incrementalPlanInitialized = true;
    }

    protected function shouldRegeneratePhpFile(string $file): bool
    {
        if (!$this->incrementalPlanInitialized) {
            return true;
        }
        $path = realpath($file) ?: $file;
        return isset($this->incrementalDirtyFiles[$path]);
    }

    protected function incrementalTranslationUnitWasEmitted(string $file): bool
    {
        $path = realpath($file) ?: $file;
        return $this->incrementalTranslationUnits[$path]
            ?? (bool) ($this->getIncrementalBuildState()->metadata($path)['emitsTranslationUnit'] ?? false);
    }

    protected function registerGeneratedProjectSource(string $source): void
    {
        $this->generatedProjectSources[$source] = true;
    }

    /**
     * Restore conversion outputs which are consumed by whole-program generators.
     *
     * @param list<string> $files
     */
    protected function restoreCleanIncrementalMetadata(array $files): void
    {
        if (!$this->incrementalPlanInitialized) {
            return;
        }
        foreach ($files as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $file = realpath($file) ?: $file;
            if ($this->shouldRegeneratePhpFile($file)) {
                continue;
            }
            $metadata = $this->getIncrementalBuildState()->metadata($file);
            if (!is_array($metadata)) {
                continue;
            }
            $this->incrementalFileMetadata[$file] = $metadata;
            $this->incrementalTranslationUnits[$file] = (bool) ($metadata['emitsTranslationUnit'] ?? false);
            $statistics = $metadata['statistics'] ?? [];
            if (is_array($statistics)) {
                $this->compilationStatistics->merge($statistics);
            }
            $globals = $metadata['globals'] ?? [];
            if (is_array($globals)) {
                foreach ($globals as $name => $type) {
                    if (is_string($name) && is_string($type)) {
                        $this->globalVarsInFile[$file][$name] = $type;
                    }
                }
            }
            $initializers = $metadata['nativeStaticInitializers'] ?? [];
            if (is_array($initializers)) {
                foreach ($initializers as $name) {
                    if (is_string($name)) {
                        $this->nativeStaticInitializersInFile[$file][$name] = true;
                    }
                }
            }
            $this->registerExistingArgInfoHeader($file);
        }
        $this->rebuildIncrementalGlobalState();
    }

    /** @param array<string, array<string, int>> $statistics */
    protected function recordIncrementalConversion(
        string $file,
        bool $emitsTranslationUnit,
        array $statistics,
    ): void {
        $path = realpath($file) ?: $file;
        $this->incrementalTranslationUnits[$path] = $emitsTranslationUnit;
        $this->incrementalFileMetadata[$path]['statistics'] = $statistics;
    }

    protected function rebuildIncrementalGlobalState(): void
    {
        foreach ($this->globalVarDeclInFile as $name => $_file) {
            unset($this->symbolDeclInFile[$this->getGlobalDependencySymbol($name)]);
        }
        $this->globalVarDeclInFile = [];
        $this->nativeStaticInitializerDeclInFile = [];
        foreach ($this->globalVarsInFile as $file => $globals) {
            ksort($globals, SORT_STRING);
            foreach ($globals as $name => $type) {
                if (!isset($this->nativeGlobalObjects[$name])) {
                    $this->globalVars[$name] = $type;
                }
                if (!isset($this->globalVarDeclInFile[$name])) {
                    $this->globalVarDeclInFile[$name] = $file;
                    $this->symbolDeclInFile[$this->getGlobalDependencySymbol($name)] = $file;
                }
                $this->symbolCallInFile[$file][] = $this->getGlobalDependencySymbol($name);
            }
        }
        foreach ($this->nativeStaticInitializersInFile as $file => $initializers) {
            foreach ($initializers as $name => $_) {
                $this->nativeStaticInitializers[$name] = true;
                $this->nativeStaticInitializerDeclInFile[$name] = $file;
            }
        }
    }

    /** @param list<string> $files */
    protected function finalizeIncrementalConversionMetadata(array $files): void
    {
        if (!$this->incrementalPlanInitialized) {
            return;
        }
        $this->rebuildIncrementalGlobalState();
        $phpFiles = [];
        foreach ($files as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $path = realpath($file);
            if ($path !== false) {
                $phpFiles[] = $path;
            }
        }
        $this->incrementalDependencies = $this->buildPhpFileDependencyGraph($phpFiles);
    }

    /** @param list<string> $files */
    protected function saveIncrementalCompilationState(array $files): void
    {
        if (!$this->incrementalPlanInitialized) {
            return;
        }
        $this->rebuildIncrementalGlobalState();
        $stateFiles = [];
        foreach ($files as $file) {
            if (!FileScanner::isPhpFile($file)) {
                continue;
            }
            $path = realpath($file);
            if ($path === false || !isset($this->incrementalSourceHashes[$path])) {
                continue;
            }
            $previous = $this->incrementalFileMetadata[$path]
                ?? $this->getIncrementalBuildState()->metadata($path)
                ?? [];
            $declared = [];
            foreach ($this->symbolDeclInFile as $symbol => $declaringFile) {
                if ($declaringFile === $path) {
                    $declared[] = $symbol;
                }
            }
            sort($declared, SORT_STRING);
            $used = array_values(array_unique($this->symbolCallInFile[$path] ?? []));
            sort($used, SORT_STRING);
            $dependencies = $this->incrementalDependencies[$path] ?? [];
            sort($dependencies, SORT_STRING);
            $initializers = array_keys($this->nativeStaticInitializersInFile[$path] ?? []);
            sort($initializers, SORT_STRING);
            $stateFiles[$path] = [
                'hash' => $this->incrementalSourceHashes[$path],
                'dependencies' => $dependencies,
                'symbolsDeclared' => $declared,
                'symbolsUsed' => $used,
                'emitsTranslationUnit' => $this->incrementalTranslationUnits[$path]
                    ?? (bool) ($previous['emitsTranslationUnit'] ?? false),
                'header' => $this->getDeclarationHeaderFile($path),
                'cpp' => $this->getCppFile($path),
                'statistics' => $this->incrementalFileMetadata[$path]['statistics']
                    ?? ($previous['statistics'] ?? []),
                'globals' => $this->globalVarsInFile[$path] ?? [],
                'nativeStaticInitializers' => $initializers,
            ];
        }
        $this->getIncrementalBuildState()->save(
            $this->incrementalGeneratorFingerprint,
            $stateFiles,
        );
    }

    /** @param list<string> $files @return array<string, list<string>> */
    private function buildPhpFileDependencyGraph(array $files): array
    {
        $known = array_fill_keys($files, true);
        $dependencies = [];
        foreach ($files as $file) {
            $requirements = [];
            foreach ($this->symbolCallInFile[$file] ?? [] as $symbol) {
                $dependency = $this->symbolDeclInFile[$symbol] ?? null;
                if (is_string($dependency)
                    && $dependency !== $file
                    && isset($known[$dependency])) {
                    $requirements[$dependency] = true;
                }
            }
            $list = array_keys($requirements);
            sort($list, SORT_STRING);
            $dependencies[$file] = $list;
        }
        return $dependencies;
    }

    private function getIncrementalBuildState(): IncrementalBuildState
    {
        $file = $this->getBuildDir() . '/cache/incremental/' . $this->targetName . '/build-state.json';
        if ($this->incrementalBuildState === null || $this->incrementalBuildStateFile !== $file) {
            $this->incrementalBuildState = new IncrementalBuildState($file);
            $this->incrementalBuildStateFile = $file;
        }
        return $this->incrementalBuildState;
    }

    /** @param list<string> $files */
    private function restoreGlobalDependencyOwnersForPlanning(
        array $files,
        IncrementalBuildState $state,
    ): void {
        $knownFiles = array_fill_keys($files, true);
        foreach ($files as $file) {
            $metadata = $state->metadata($file);
            if (!is_array($metadata)) {
                continue;
            }
            foreach ($metadata['symbolsDeclared'] ?? [] as $symbol) {
                if (is_string($symbol) && str_starts_with($symbol, 'global:')) {
                    $this->symbolDeclInFile[$symbol] = $file;
                }
            }
        }
        foreach ($files as $file) {
            foreach ($this->symbolCallInFile[$file] ?? [] as $symbol) {
                if (!str_starts_with($symbol, 'global:')
                    || isset($this->symbolDeclInFile[$symbol])) {
                    continue;
                }
                // A newly introduced global has no previous owner. Assign one
                // deterministically for this planning pass; conversion will
                // record the actual first declaration owner in sorted order.
                $this->symbolDeclInFile[$symbol] = $file;
            }
        }
        foreach ($this->symbolDeclInFile as $symbol => $file) {
            if (str_starts_with($symbol, 'global:') && !isset($knownFiles[$file])) {
                unset($this->symbolDeclInFile[$symbol]);
            }
        }
    }

    private function getIncrementalGeneratorFingerprint(): string
    {
        $context = hash_init('sha256');
        hash_update($context, serialize([
            'translator' => self::VERSION,
            'php' => $this->phpVersion,
            'mode' => $this->buildMode,
            'nano' => $this->isNanoMode(),
            'nanoPolicy' => $this->isNanoPolicyMode(),
            'wasi' => $this->isWasiTarget(),
            'platform' => $this->targetPlatform,
            'literalStrings' => !$this->noLiteralStrings,
            'debug' => $this->debug,
        ]));

        // A compiled tpc executable is an immutable snapshot of the generator.
        // Walking and hashing every compiler PHP source on each consumer build
        // is both unnecessary and disproportionately expensive through the AOT
        // Zend bridge. Fingerprint the executable snapshot with one native hash
        // operation instead. The interpreted development entry keeps the source
        // walk below so edits invalidate generated-code caches immediately.
        if (!defined('TYPEPHP_PHP_SCRIPT_ENTRY')
            && defined('TYPEPHP_COMPILER_EXECUTABLE')) {
            $executable = constant('TYPEPHP_COMPILER_EXECUTABLE');
            if (is_string($executable) && is_file($executable)) {
                hash_update($context, str_replace('\\', '/', $executable) . "\0");
                if (!hash_update_file($context, $executable)) {
                    throw new \RuntimeException(
                        'Cannot fingerprint TypePHP compiler executable: ' . $executable,
                    );
                }
                return hash_final($context);
            }
        }

        $sourceDirectory = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS),
        );
        $sources = [];
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $sources[] = $entry->getPathname();
            }
        }
        sort($sources, SORT_STRING);
        foreach ($sources as $source) {
            hash_update($context, str_replace('\\', '/', $source) . "\0");
            hash_update_file($context, $source);
        }
        return hash_final($context);
    }
}
