# Smart Template

A small PHP template engine for structured rendering with native PHP arrays, lazy compilation, and named placeholder substitution.

`smart-template` is designed for projects that want to keep rendering logic in PHP without introducing a separate template language. Templates are regular PHP files that return arrays of strings and callbacks. When accessed, string leaves are lazily compiled into render closures and nested arrays are wrapped into `RenderCollection` objects.

## What it is good at

- Small, reusable HTML fragments and components
- PHP-native template composition
- Dynamic rendering with named placeholders
- Rendering from filesystem templates or in-memory templates
- Fine-grained control over escaping and HTML attributes

## What it is not

This package is **not** a full view framework. It does not try to replace systems built around inheritance, blocks, macros, or expression languages. It works best when you want explicit PHP control over markup and composition.

---

## Installation

```bash
composer require dalpras/smart-template
```

## Requirements

- PHP 8.3 or newer

---

## Mental model

A template file returns an array.

Each array entry can be:

- a **string** with placeholders such as `{title}` or `{rows}`
- a **closure**
- a **nested array** of more templates

When a string leaf is accessed, the engine compiles it lazily into a closure. When you call that closure with an associative array, placeholders are replaced by key.

Example:

```php
$template['card']([
    '{title}' => 'Hello',
    '{body}'  => 'Welcome',
]);
```

If a placeholder value is itself a closure, the engine resolves it before substitution.

---

# Quick start

## 1) Create a template file

`templates/card.php`

```php
<?php

return [
    'card' => <<<HTML
<article class="card">
    <h2>{title}</h2>
    <div class="card__body">{body}</div>
</article>
HTML,
];
```

## 2) Render it

```php
<?php

use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine(__DIR__ . '/templates');

echo $engine->render('card.php', function ($tpl) {
    return $tpl['card']([
        '{title}' => 'Hello',
        '{body}'  => 'This is rendered with Smart Template.',
    ]);
});
```

Output:

```html
<article class="card">
    <h2>Hello</h2>
    <div class="card__body">This is rendered with Smart Template.</div>
</article>
```

---

# Working with nested template parts

You can keep related fragments in the same template file.

`templates/table.php`

```php
<?php

return [
    'table' => <<<HTML
<table class="{class}">
    <tbody>
        {rows}
    </tbody>
</table>
HTML,

    'row' => <<<HTML
<tr>
    <td>{text}</td>
</tr>
HTML,
];
```

Render it like this:

```php
<?php

use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine(__DIR__ . '/templates');

echo $engine->render('table.php', function ($tpl) {
    $rows = '';
    $rows .= $tpl['row'](['{text}' => 'First row']);
    $rows .= $tpl['row'](['{text}' => 'Second row']);

    return $tpl['table']([
        '{class}' => 'table table-striped',
        '{rows}'  => $rows,
    ]);
});
```

---

# Lazy placeholder closures

A placeholder value can be a closure. The engine resolves it before doing the string substitution. This is useful when a placeholder depends on other template fragments.

```php
<?php

echo $engine->render('table.php', function ($tpl) {
    return $tpl['table']([
        '{class}' => 'table',
        '{rows}'  => function ($root, $scope, $engine, $namespace) use ($tpl) {
            return
                $tpl['row'](['{text}' => 'Lazy row A']) .
                $tpl['row'](['{text}' => 'Lazy row B']);
        },
    ]);
});
```

Placeholder closures receive:

1. the root `RenderCollection`
2. the current scoped `RenderCollection`
3. the `TemplateEngine`
4. the current template namespace

This makes it possible to build more advanced compositions without resolving everything up front.

---

# Using `getCollection()` directly

`render()` is the simplest API, but you can also access a template collection directly.

```php
<?php

$tpl = $engine->getCollection('table.php');

echo $tpl['table']([
    '{class}' => 'table',
    '{rows}'  => $tpl['row'](['{text}' => 'Direct collection access']),
]);
```

This is useful when you want to reuse a template collection across several rendering operations.

---

# Register in-memory templates with `addCustom()`

You do not need filesystem templates for every use case. You can register templates programmatically.

```php
<?php

use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine();

$engine->addCustom('ui', [
    'badge' => '<span class="badge badge-{type}">{label}</span>',
    'button' => '<button type="{buttonType}" class="{class}">{label}</button>',
]);

$ui = $engine->getCollection('ui');

echo $ui['badge']([
    '{type}'  => 'success',
    '{label}' => 'Saved',
]);

echo $ui['button']([
    '{buttonType}' => 'button',
    '{class}'      => 'btn btn-primary',
    '{label}'      => 'Continue',
]);
```

---

# Organizing nested templates

Because nested arrays are wrapped into `RenderCollection` objects, you can group fragments by feature.

```php
<?php

$engine->addCustom('mail', [
    'layout' => [
        'page' => <<<HTML
<div class="mail-layout">
    <header>{header}</header>
    <main>{content}</main>
</div>
HTML,
    ],
    'partials' => [
        'title' => '<h1>{text}</h1>',
    ],
]);

$mail = $engine->getCollection('mail');

echo $mail['layout']['page']([
    '{header}' => $mail['partials']['title']([
        '{text}' => 'Newsletter',
    ]),
    '{content}' => '<p>Welcome.</p>',
]);
```

---

# Rendering attributes

The engine includes an `attributes()` helper for turning arrays into HTML attributes.

```php
<?php

use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine();

$attrs = $engine->attributes([
    'id'    => 'user[profile][email]',
    'title' => 'Email address',
    'class' => 'form-control',
]);

echo '<input ' . $attrs . ' />';
```

Example output:

```html
<input id="user-profile-email" title="Email address" class="form-control" />
```

Notes:

- `id` values are normalized into an HTML-friendly form
- some attribute values such as `id`, `title`, `name`, and `alt` can be escaped through the configured helpers
- closures are supported as attribute values and are resolved before rendering

Example with a lazy value:

```php
<?php

echo '<button ' . $engine->attributes([
    'class' => fn () => 'btn btn-primary',
    'title' => fn () => 'Save changes',
]) . '>Save</button>';
```

---

# Cross-template composition

Templates can compose other templates by using the same engine instance.

`templates/toolbar.php`

```php
<?php

return [
    'header' => '<div class="toolbar">{text}</div>',
];
```

```php
<?php

echo $engine->render('table.php', function ($tpl) use ($engine) {
    return $tpl['table']([
        '{class}' => 'table',
        '{rows}'  => function () use ($engine) {
            return $engine->render('toolbar.php', fn ($toolbar) => $toolbar['header']([
                '{text}' => 'Rendered from another template',
            ]));
        },
    ]);
});
```

This approach is useful when you want to keep components in separate files but still assemble them in one render operation.

---

# Return values and stringification

When placeholders are substituted, the engine converts values to strings.

Common cases are handled for you:

- strings
- integers and floats
- booleans
- `null`
- `DateTimeInterface`
- enums
- `Stringable`
- arrays and objects through JSON-style encoding

That means simple values usually work out of the box:

```php
<?php

$engine->addCustom('demo', [
    'line' => '<p>{value}</p>',
]);

$demo = $engine->getCollection('demo');

echo $demo['line']([
    '{value}' => new DateTimeImmutable('2026-03-24 10:00:00'),
]);
```

Even so, for HTML output, you should still decide explicitly where escaping belongs in your application.

---

# Recommended usage pattern

A reliable way to use this package is:

1. Keep template files focused on markup fragments
2. Use named placeholders consistently, including the braces in the keys
3. Build complex sections by composing smaller template leaves
4. Escape untrusted content at the application boundary
5. Reuse a `TemplateEngine` instance instead of creating a new one per render

A good style is to keep templates simple and move business decisions outside the template strings.

---

# Error handling

`getCollection()` throws when the template cannot be found.

That means this pattern is safe:

```php
<?php

try {
    $tpl = $engine->getCollection('missing.php');
} catch (\Throwable $e) {
    // log or recover
}
```

If you render from the filesystem, template files must return an array. Returning any other type from a template file is an error.

---

# Filesystem lookup

When a directory is provided, the engine scans it and can resolve templates by name or partial path suffix.

Example:

```php
<?php

$engine = new TemplateEngine(__DIR__ . '/templates');

$engine->getCollection('table.php');
$engine->getCollection('emails/newsletter.php');
```

Internally, template files are indexed by basename and matched by suffix, so keep filenames clear and avoid ambiguous duplicates where possible.

---

# Example project structure

```text
templates/
├── card.php
├── table.php
└── emails/
    └── newsletter.php
```

Example template file:

```php
<?php

return [
    'layout' => [
        'page' => '<div class="page">{content}</div>',
    ],
    'partials' => [
        'title' => '<h1>{text}</h1>',
    ],
];
```

---

# Security notes

This package gives you control, but it does not automatically make HTML safe.

Be careful with:

- user-controlled HTML
- attribute values
- URLs
- inline JavaScript
- mixed trusted and untrusted content

Treat output escaping as an application concern. Use your escaper/helpers consistently when rendering untrusted data.

---

# When to choose this package

Choose Smart Template when:

- you want a small PHP-native renderer
- you prefer arrays and closures over a custom template language
- you are rendering many small reusable fragments
- you want explicit control over composition

Consider a larger templating system when:

- your team wants a strict separation between PHP and views
- you need inheritance, blocks, macros, or filters
- you want a larger ecosystem of integrations and tooling

---

# Minimal reference

## `TemplateEngine`

```php
new TemplateEngine(?string $directory = null, ?string $default = null, bool $preload = true)
```

Main methods:

- `render(string $name, Closure $callback): mixed`
- `getCollection(string $name): RenderCollection`
- `addCustom(string $namespace, array $templates): static`
- `attributes(array $attribs): string`

## `RenderCollection`

Collections behave like nested arrays with lazy wrapping and lazy compilation.

Typical usage:

```php
<?php

$tpl = $engine->getCollection('card.php');

echo $tpl['card']([
    '{title}' => 'Hi',
    '{body}'  => 'Example',
]);
```

---

# License

See the package metadata in `composer.json` / Packagist and keep the README aligned with that metadata.
