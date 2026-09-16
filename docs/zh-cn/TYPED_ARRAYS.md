# 强类型 PHP 数组与类型注解

本文记录当前实现边界。完整的容器契约与目标设计见 [类型注解统一设计](TYPE_ANNOTATIONS.md)，作为回调参数的规则见 [StdFunc / StdArgInfo](STD_FUNC_DESIGN.md)（尚未实现）。

`std::list(T)` / `std::dict(K, V)` 保留普通 PHP 数组存储与写时复制，使用类型注解声明参数或属性的键和值类型：

局部值也可由非空数组直接初始化并推导类型，例如 `std::list([9, 3, 5])` 和 `std::dict(['v1' => 999, 'v2' => 1000])`。表达式不限于字面量，但所有静态 value 类型必须完全一致；dict 的显式 key 也必须全部为同一种 Int 或 Str。任何 var/any/mixed key/value 都因无法可靠推导而编译报错。

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

PHP 类型可以省略或声明为兼容类型：`StdList` / `StdDict` 对应 `array`，`StdVector` / `StdMap` / `StdOrderedMap` 对应 `box`。不允许显式 `mixed`、`any`、可空类型、联合类型和其他不兼容类型。

list 支持负数、稀疏整数键和空洞，不做边界检查；只有 list 允许 `[]` 追加。dict 必须显式提供 int 或 str 键。动态 `any` / `var` 键插入内部严格检查，不做隐式转换。值要求静态类型匹配，`Type::Any` 值除外。

局部强类型数组禁止通过 `std::ref()`、元素引用、可修改或引用传递的数组函数逃逸到动态 PHP。类型一致的原生参数可以按引用传递。字符串键 dict 遍历时将 PHP 数字键恢复为字符串，不修改 phpx 或底层 HashTable。

当前属性类型注解检查第一层直接元素赋值，并不完整保护整属性替换、动态 PHP 对象修改或对象/属性引用逃逸。属性读取不会自动升级为受封闭契约保护的局部强类型数组。
