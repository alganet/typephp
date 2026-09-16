# Typed PHP arrays and type annotations

This document records current implementation boundaries. See [Unified Type Annotation Design](TYPE_ANNOTATIONS.md) for complete container contracts and target design, and [StdFunc / StdArgInfo](STD_FUNC_DESIGN.md) for proposed callback parameter rules (not implemented).

`std::list(T)` and `std::dict(K, V)` retain PHP array storage and copy-on-write.
Type annotations declare their key and value contracts on parameters and properties:

```php
class State
{
    #[StdList(Type::Int)] public array $values = [];
    #[StdDict(Type::Str, Type::Int)] public $counts = [];
}

function append(#[StdList(Type::Int)] array &$values): void
{
    $values[] = 42;
}
```

PHP types may be omitted or declared as compatible storage types: `array` for
`StdList` / `StdDict`, and `box` for `StdVector` / `StdMap` / `StdOrderedMap`.
Explicit `mixed`, `any`, nullable types, unions, and incompatible types are rejected.

List keys are integers, including negative and sparse keys; no bounds checks
are inserted. Only lists allow `[]` append. Dicts require an explicit int or
string key. Dynamic `any` / `var` keys get internal strict type checks, not coercion.
Values require matching static types, except for `Type::Any` values.

Local typed arrays cannot escape to dynamic PHP through `std::ref()`, element
references, or mutable/by-reference array functions. Matching annotated native
parameters may accept references. String-key dict iteration converts numeric
PHP keys back to strings without changing PHPX or HashTable storage.

Property annotations currently check first-level direct element assignments.
They do not provide complete protection against whole-property replacement,
dynamic PHP object mutation, or object/property reference escapes. Property reads
are not automatically promoted to closed-contract local typed arrays.
