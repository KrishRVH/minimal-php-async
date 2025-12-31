<?php

declare(strict_types=1);

return [
    'target_php_version' => '8.5',
    'minimum_target_php_version' => '8.5',

    'directory_list' => [
        'src/',
        'tests/',
        'vendor/',
    ],

    'exclude_analysis_directory_list' => [
        'vendor/',
    ],
    'exclude_file_list' => [
        'vendor/rector/rector/stubs-rector/PHPUnit/Framework/TestCase.php',
        'vendor/jetbrains/phpstorm-stubs/SPL/SPL.php',
    ],

    // Maximum strictness
    'strict_method_checking' => true,
    'strict_object_checking' => true,
    'strict_param_checking' => true,
    'strict_property_checking' => true,
    'strict_return_checking' => true,

    'analyze_signature_compatibility' => true,
    'allow_missing_properties' => false,
    'null_casts_as_any_type' => false,
    'null_casts_as_array' => false,
    'array_casts_as_null' => false,
    'scalar_implicit_cast' => false,
    'scalar_implicit_partial' => [],
    'ignore_undeclared_variables_in_global_scope' => false,
    'ignore_undeclared_functions_with_known_signatures' => false,

    // Redundancy detection
    'redundant_condition_detection' => true,
    'assume_real_types_for_internal_functions' => true,
    'check_docblock_signature_return_type_match' => true,
    'check_docblock_signature_param_type_match' => true,
    'prefer_narrowed_phpdoc_param_type' => true,
    'prefer_narrowed_phpdoc_return_type' => true,

    // Dead code
    'dead_code_detection' => true,
    'unused_variable_detection' => true,
    'assume_no_external_class_overrides' => true,

    // Be aggressive
    'quick_mode' => false,
    'should_visit_all_nodes' => true,
    'analyze_all_method_bodies' => true,
    'backward_compatibility_checks' => false, // PHP 8.5 only, no BC needed
    'simplify_ast' => true,

    // Generic/template support
    'generic_types_enabled' => true,

    // Maximum error level (0 = strictest)
    'minimum_severity' => 0,

    // Plugins - all the strict ones
    'plugins' => [
        'AlwaysReturnPlugin',
        'DollarDollarPlugin',
        'DuplicateArrayKeyPlugin',
        'DuplicateExpressionPlugin',
        'EmptyStatementListPlugin',
        'InvalidVariableIssetPlugin',
        'LoopVariableReusePlugin',
        'NoAssertPlugin',
        'NonBoolBranchPlugin',
        'NonBoolInLogicalArithPlugin',
        'NumericalComparisonPlugin',
        'PHPUnitAssertionPlugin',
        'PHPUnitNotDeadCodePlugin',
        'PossiblyStaticMethodPlugin',
        'PreferNamespaceUsePlugin',
        'PregRegexCheckerPlugin',
        'PrintfCheckerPlugin',
        'RedundantAssignmentPlugin',
        'ShortArrayPlugin',
        'SimplifyExpressionPlugin',
        'StrictComparisonPlugin',
        'StrictLiteralComparisonPlugin',
        'SuspiciousParamOrderPlugin',
        'UnknownClassElementAccessPlugin',
        'UnknownElementTypePlugin',
        'UnreachableCodePlugin',
        'UnsafeCodePlugin',
        'UnusedSuppressionPlugin',
        'UseReturnValuePlugin',
        'WhitespacePlugin',
    ],

    // Suppress nothing globally - fix issues instead
    'suppress_issue_types' => [],

    // File-level suppressions only where absolutely necessary
    'file_list' => [],

    // Autoload
    'autoload_internal_extension_signatures' => [],
];
