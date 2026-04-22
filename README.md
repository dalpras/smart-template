# Smart Template

Lightning-fast, dependency-free PHP template engine based on lazy closures, structured collections, and named placeholder substitution.

No DSL.  
No parser.  
No compilation pipeline.  
Just PHP arrays, closures, and direct rendering.

---

## Introduction

`smart-template` is designed for projects that want:

- native PHP template files
- explicit structure
- low overhead
- reusable fragments
- lazy loading for large template trees

Templates are plain PHP files that return arrays.  
String leaves are compiled only when needed, nested arrays become scoped collections, and external template files can be loaded eagerly or lazily.

This makes the library a good fit for:

- component-style HTML rendering
- structured UI fragments
- large template trees split across multiple files
- performance-sensitive rendering where only a small subset of templates is used per request

---

## Features

- Named `{key}` substitutions
- Nested template collections
- Lazy compilation of string templates
- Lazy placeholder closures
- Eager template inclusion with `$this->require()`
- Lazy template inclusion with `$this->lazyRequire()`
- Strict path lookup with `at()`
- PHP-native template composition
- OPcache-friendly design
- Zero dependencies

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

- a string template
- a closure
- a nested array of templates
- a required external template file
- a lazy-required external template file

Example:

```php
return [
    'card' => <<<HTML
        <article class="card">
            <h2>{title}</h2>
            <div>{body}</div>
        </article>
        HTML,
];
```

When a string leaf is accessed, it is compiled lazily into a render closure.  
When you call that closure with an associative array, placeholders are replaced by key.

```php
echo $tpl['card']([
    '{title}' => 'Hello',
    '{body}'  => 'Welcome',
]);
```

If a placeholder value is itself a closure, Smart Template resolves it before substitution.

---

## Quick start

### 1) Create a template file

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

### 2) Render it

```php
<?php

use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine(__DIR__ . '/templates');

echo $engine->render('card.php', function ($tpl) {
    return $tpl['card']([
        '{title}' => 'Hello',
        '{body}'  => 'Rendered with Smart Template.',
    ]);
});
```

---

## Working with nested template parts

You can group related fragments in one file.

`templates/table.php`

```php
<?php

return [
    'table' => <<<HTML
        <table class="{class}">
            <tbody>{rows}</tbody>
        </table>
        HTML,

    'row' => <<<HTML
        <tr>
            <td>{text}</td>
        </tr>
        HTML,
];
```

Usage:

```php
echo $engine->render('table.php', function ($tpl) {
    $rows  = $tpl['row'](['{text}' => 'First row']);
    $rows .= $tpl['row'](['{text}' => 'Second row']);

    return $tpl['table']([
        '{class}' => 'table table-striped',
        '{rows}'  => $rows,
    ]);
});
```

---

## Lazy placeholder closures

A placeholder value can be a closure.  
The engine resolves it before string substitution.

```php
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

This allows advanced composition without resolving everything up front.

---

## `getCollection()` and `at()`

`render()` is the simplest API, but you can also work directly with the collection.

```php
$tpl = $engine->getCollection('table.php');

echo $tpl['table']([
    '{class}' => 'table',
    '{rows}'  => $tpl['row'](['{text}' => 'Direct collection access']),
]);
```

### Strict access with `at()`

Use `at()` when the path must exist.

```php
$row = $tpl->at('row');
echo $row(['{text}' => 'Required template fragment']);
```

Use `at()` when:

- the template path is part of your contract
- missing entries should fail fast
- you want explicit, strict access

```php
$layout = $tpl->at('layout.page');
```

### Safe access

If you also expose a nullable lookup such as `find()`, use it when the path is optional.

```php
$maybeDebug = $tpl->find('layout.debug');
```

Use `at()` for required paths.  
Use a nullable lookup for optional paths.

---

## Why `lazyRequire()` matters

For large template systems, `lazyRequire()` is one of the most important features.

It lets you split templates into many files without paying the cost of loading and building every file on every request.

### Eager inclusion with `require()`

`require()` loads the referenced template file immediately.

Use it when the sub-template is always needed for the current template.

```php
return [
    'icons' => $this->require(__DIR__ . '/_icons.php'),
];
```

### Lazy inclusion with `lazyRequire()`

`lazyRequire()` defers loading the referenced file until that branch is actually accessed.

Use it when the sub-template group is large, optional, or rarely used.

```php
return [
    'article' => $this->lazyRequire(__DIR__ . '/_articles.php'),
    'layout'  => $this->lazyRequire(__DIR__ . '/_layouts.php'),
    'module'  => $this->lazyRequire(__DIR__ . '/_modules.php'),
];
```

### When to prefer `lazyRequire()`

Prefer `lazyRequire()` when:

- you have many template groups
- only a subset is used per request
- you want a clean folder structure without slowing down common paths
- you want to grow the template library over time

### Rule of thumb

- use `require()` for always-needed fragments
- use `lazyRequire()` for optional or large branches

---

## Recommended structure for large projects

A very effective pattern is to have one small default entry file that exposes the main groups, and then split each group into its own file.

This keeps the API organized while preserving speed.

### Example default template file

```php
<?php

return [
    'article' => $this->lazyRequire(__DIR__ . '/_articles.php'),
    'form'    => $this->lazyRequire(__DIR__ . '/../../vendor/dalpras/form-zero/src/Template/form.inc.php'),
    'module'  => $this->lazyRequire(__DIR__ . '/_modules.php'),
    'layout'  => $this->lazyRequire(__DIR__ . '/_layouts.php'),
    'service' => $this->lazyRequire(__DIR__ . '/_services.php'),

    'picture' => [
        'picture' => <<<HTML
            <picture>{sources}{image}</picture>
            HTML,
        'image' => <<<HTML
            <img class="{class}" src="{src}" alt="{alt}" role="img" focusable="false" {attributes}>
            HTML,
        'source' => <<<HTML
            <source srcset="{src}" media="{media}">
            HTML,
    ],
];
```

### Why this structure works well

- the root file stays small and readable
- each domain has its own file
- new domains can be added without touching existing branches much
- unused branches are never loaded when they are behind `lazyRequire()`
- the public API stays predictable

For example:

```php
$tpl = $engine->getCollection('default.php');

echo $tpl->at('picture.image')([
    '{class}'      => 'img-fluid',
    '{src}'        => '/img/demo.jpg',
    '{alt}'        => 'Demo image',
    '{attributes}' => '',
]);
```

And when needed:

```php
echo $tpl->at('article.card')([
    '{title}' => 'Hello',
    '{body}'  => 'Loaded only when article templates are accessed.',
]);
```

In this setup, adding a new `_marketing.php` or `_emails.php` file does not affect the speed of requests that never touch those branches, as long as they are wired with `lazyRequire()`.

---

## Suggested file layout

```text
templates/
├── default.php
├── _articles.php
├── _layouts.php
├── _modules.php
├── _services.php
└── partials/
```

A good convention is:

- `default.php` as the entry collection
- `_*.php` files for grouped template branches
- inline arrays only for very small always-used fragments
- `lazyRequire()` for large groups
- `require()` only where eager loading is intentional

---

## Example usage pattern

```php
use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine(__DIR__ . '/templates');
$tpl = $engine->getCollection('default.php');

$card = $tpl->at('article.card')([
    '{title}' => 'News title',
    '{body}'  => 'Article body',
]);

$image = $tpl->at('picture.image')([
    '{class}'      => 'img-fluid',
    '{src}'        => '/img/example.jpg',
    '{alt}'        => 'Example',
    '{attributes}' => 'loading="lazy"',
]);

echo $tpl->at('layout.page')([
    '{content}' => $card . $image,
]);
```

This scales well because:

- `picture.*` is immediately available in the root file
- `article.*` is loaded only when requested
- `layout.*` is loaded only when requested

---

## Cross-template rendering

You can render another template file from inside a placeholder closure when needed.

```php
echo $engine->render('table.php', function ($tpl) use ($engine) {
    return $tpl['table']([
        '{class}' => 'text-end',
        '{rows}'  => function () use ($engine) {
            return $engine->render('toolbar.php', fn($toolbar) =>
                $toolbar['header']([
                    '{text}' => 'hello different template toolbar',
                ])
            );
        },
    ]);
});
```

---

## Performance notes

Smart Template is built around low-overhead rendering:

- direct named substitution
- lazy compilation
- lazy branch loading
- PHP-native structures
- OPcache-friendly execution

For best performance:

- keep the root template file small
- split large groups into separate files
- use `lazyRequire()` for optional branches
- use `require()` only for always-needed dependencies
- use `at()` for strict access in application code

With this structure, you can keep a large, well-organized template library without slowing down common rendering paths.

---

## Responsibility

This engine does not automatically solve:

- XSS protection
- output escaping strategy
- excessive presentation complexity

Use proper escaping and keep template contracts explicit.

---

## Summary

Smart Template works best when you treat templates as structured PHP collections.

Recommended approach:

- one small entry file
- grouped template files by domain
- `lazyRequire()` for optional branches
- `require()` for required branches
- `at()` for strict path access

That combination gives you:

- readable template organization
- easy growth over time
- fast common-case rendering
- explicit access semantics
