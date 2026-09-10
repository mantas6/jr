<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\CombineIfRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfElseToTernaryRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\MethodCall\RemoveNullArgOnNullDefaultParamRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\PHPUnit\CodeQuality\Rector\StmtsAwareInterface\DeclareStrictTypesTestsRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap/app.php',
        __DIR__ . '/database',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
        __DIR__ . '/config',
        __DIR__ . '/public',
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class,
        ClosureToArrowFunctionRector::class,
        NullToStrictStringFuncCallArgRector::class,
        RemoveNullArgOnNullDefaultParamRector::class,
        // ReturnTypeFromStrictTypedCallRector::class,
        EncapsedStringsToSprintfRector::class,
        CombineIfRector::class,
        SimplifyIfElseToTernaryRector::class,
        ExplicitBoolCompareRector::class,

        DeclareStrictTypesTestsRector::class,
        DeclareStrictTypesRector::class,
        PostIncDecToPreIncDecRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        // typeDeclarations: true,
        // typeDeclarationDocblocks: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,

    )
    ->withImportNames(removeUnusedImports: true)
    ->withPhpSets()
    ->withComposerBased(laravel: true);
