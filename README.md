# Smart Template

A small PHP template engine for structured rendering with native PHP arrays, lazy compilation, presets, and named placeholder substitution.

`smart-template` is designed for projects that want to keep rendering logic in PHP without introducing a separate template language.

Templates are regular PHP arrays made of strings, callbacks, and nested arrays. String leaves are lazily compiled into render closures when accessed.

---

## What it is good at

- Small, reusable HTML fragments and components
- PHP-native template composition
- Dynamic rendering with named placeholders
- Preset-based template registration
- In-memory template collections
- Fine-grained control over escaping and HTML attributes

## What it is not

This package is **not** a full view framework.

It does not try to replace systems built around inheritance, blocks, macros, filters, or expression languages. It works best when you want explicit PHP control over markup and composition.

---

## Installation

```bash
composer require dalpras/smart-template
```

## Requirements

- PHP 8.3 or newer

---

## Mental model

A template collection is an array. Each entry can be:

- a **string** with placeholders such as `{title}` or `{rows}`
- a **closure**
- a **nested array** of more templates
- a lazy template-file reference

When a string leaf is accessed, the engine compiles it lazily into a closure.

Example:

```php
echo $template['card']([
    '{title}' => 'Hello',
    '{body}' => 'Welcome',
]);
```

If a placeholder value is itself a closure, the engine resolves it before substitution.

---

# Quick start

## 1) Create a template preset

```php
<?php

use DalPraS\SmartTemplate\TemplateEngine;

final class UiPreset
{
    public const NAMESPACE = 'ui';

    public static function register(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
        bool $default = true,
    ): TemplateEngine {
        return $engine->register($namespace, [
            'card' => <<<'HTML'
<section class="card">
    <h2>{title}</h2>
    <div>{body}</div>
</section>
HTML,
        ], default: $default);
    }
}
```

## 2) Register the preset

```php
use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine();

UiPreset::register($engine);
```

## 3) Render it

```php
echo $engine->renderDefault(function ($ui) {
    return $ui['card']([
        '{title}' => 'Hello',
        '{body}' => 'This is rendered with Smart Template.',
    ]);
});
```

Output:

```html
<section class="card">
    <h2>Hello</h2>
    <div>This is rendered with Smart Template.</div>
</section>
```

---

# Presets

A preset is a class that registers templates into the engine.

Presets replace automatic filesystem loading. The engine does not scan directories or resolve template names from the filesystem anymore.

```php
$engine = new TemplateEngine();

UiPreset::register($engine);
```

Then render the registered collection:

```php
echo $engine->render('ui', function ($ui) {
    return $ui['card']([
        '{title}' => 'Hello',
        '{body}' => 'Preset rendering',
    ]);
});
```

The first registered collection automatically becomes the default collection.

```php
$ui = $engine->collection();

echo $ui['card']([
    '{title}' => 'Hello',
    '{body}' => 'Default collection',
]);
```

You can also mark a collection as default explicitly:

```php
$engine->register('ui', $templates, default: true);
```

---

# Loading templates from PHP files

The engine still provides `require()` as a low-level helper for presets.

This is explicit loading, not filesystem discovery.

```php
final class UiPreset
{
    public const NAMESPACE = 'ui';

    public static function register(
        TemplateEngine $engine,
        string $templatesDir,
        string $namespace = self::NAMESPACE,
        bool $default = true,
    ): TemplateEngine {
        $templates = $engine->require(
            rtrim($templatesDir, '/\\') . DIRECTORY_SEPARATOR . 'default.php'
        );

        if (!is_array($templates)) {
            throw new RuntimeException('Preset template file must return an array.');
        }

        return $engine->register($namespace, $templates, default: $default);
    }
}
```

Example template file:

```php
<?php

return [
    'card' => <<<'HTML'
<section class="card">
    <h2>{title}</h2>
    <div>{body}</div>
</section>
HTML,

    'button' => <<<'HTML'
<button type="{type}" class="{class}">{label}</button>
HTML,
];
```

---

# Built-in HTML preset

If using the built-in HTML preset:

```php
use DalPraS\SmartTemplate\Preset\HtmlPreset;
use DalPraS\SmartTemplate\TemplateEngine;

$engine = new TemplateEngine();

HtmlPreset::register($engine);

$html = $engine->collection();

echo $html['div']([
    '{attributes}' => 'class="box"',
    '{body}' => 'Hello',
]);
```

You can register it under a custom namespace:

```php
HtmlPreset::register($engine, namespace: 'html');
```

Then render it explicitly:

```php
echo $engine->render('html', function ($html) {
    return $html['div']([
        '{attributes}' => 'class="box"',
        '{body}' => 'Hello',
    ]);
});
```

---

# Registering templates directly

You can register templates without creating a preset class.

```php
$engine = new TemplateEngine();

$engine->register('ui', [
    'badge' => '<span class="badge badge-{type}">{label}</span>',
    'button' => '<button type="{buttonType}" class="{class}">{label}</button>',
]);

$ui = $engine->collection('ui');

echo $ui['badge']([
    '{type}' => 'success',
    '{label}' => 'Saved',
]);

echo $ui['button']([
    '{buttonType}' => 'button',
    '{class}' => 'btn btn-primary',
    '{label}' => 'Continue',
]);
```

`addCustom()` is kept as a backward-compatible alias:

```php
$engine->addCustom('ui', [
    'badge' => '<span>{label}</span>',
]);
```

New code should prefer `register()`.

---

# Default collection

The first registered namespace becomes the default collection.

```php
$engine->register('ui', [
    'title' => '<h1>{text}</h1>',
]);

$ui = $engine->collection();

echo $ui['title']([
    '{text}' => 'Hello',
]);
```

You can set the default namespace manually:

```php
$engine->setDefaultNamespace('ui');
```

Or access it explicitly:

```php
$ui = $engine->defaultCollection();
```

---

# Nested templates

Nested arrays are wrapped into `RenderCollection` objects.

```php
$engine->register('mail', [
    'layout' => [
        'page' => <<<'HTML'
<html>
<body>
    {header}
    {content}
</body>
</html>
HTML,
    ],

    'partials' => [
        'title' => '<h1>{text}</h1>',
    ],
]);

$mail = $engine->collection('mail');

echo $mail['layout']['page']([
    '{header}' => $mail['partials']['title']([
        '{text}' => 'Newsletter',
    ]),
    '{content}' => '<p>Welcome.</p>',
]);
```

---

# Lazy template files

A preset file can reference another file lazily.

```php
<?php

return [
    'layout' => [
        'page' => '<main>{content}</main>',
    ],

    'partials' => $this->lazyRequire(__DIR__ . '/partials.php'),
];
```

`partials.php`:

```php
<?php

return [
    'title' => '<h1>{text}</h1>',
    'paragraph' => '<p>{text}</p>',
];
```

The lazy file is loaded only when that branch is accessed.

---

# Lazy placeholder closures

A placeholder value can be a closure.

```php
echo $engine->render('mail', function ($mail) {
    return $mail['layout']['page']([
        '{header}' => fn ($root) => $root['partials']['title']([
            '{text}' => 'Newsletter',
        ]),
        '{content}' => '<p>Welcome.</p>',
    ]);
});
```

Placeholder closures receive:

1. the root `RenderCollection`
2. the current scoped `RenderCollection`
3. the `TemplateEngine`
4. the current namespace

```php
'{content}' => function ($root, $scope, $engine, $namespace) {
    return $root['partials']['paragraph']([
        '{text}' => 'Generated lazily',
    ]);
}
```

---

# Rendering attributes

The engine includes an `attributes()` helper.

```php
echo '<input ' . $engine->attributes([
    'id' => 'user[email]',
    'name' => 'user[email]',
    'title' => 'Email address',
    'class' => 'form-control',
]) . '>';
```

Example output:

```html
<input id="user-email" name="user[email]" title="Email address" class="form-control">
```

Notes:

- `id` values are normalized into an HTML-friendly form
- `id`, `title`, `name`, and `alt` can be escaped through configured helpers
- closures are supported as attribute values

```php
echo '<button ' . $engine->attributes([
    'class' => fn () => 'btn btn-primary',
    'title' => fn () => 'Save changes',
]) . '>Save</button>';
```

---

# Custom placeholder callbacks

You can register callbacks that transform or provide placeholder values.

```php
$engine->addCustomParamCallback('{attributes}', static function ($value) use ($engine): string {
    return $value === null ? '' : $engine->attributes($value);
});
```

Then templates can use `{attributes}` consistently:

```php
$engine->register('ui', [
    'button' => '<button {attributes}>{label}</button>',
]);

echo $engine->collection('ui')['button']([
    '{attributes}' => [
        'type' => 'button',
        'class' => 'btn btn-primary',
    ],
    '{label}' => 'Save',
]);
```

---

# Cross-collection composition

Collections can compose other registered collections by using the same engine instance.

```php
$engine->register('icons', [
    'save' => '<svg>{content}</svg>',
]);

$engine->register('ui', [
    'button' => '<button>{icon}{label}</button>',
]);

echo $engine->render('ui', function ($ui) use ($engine) {
    return $ui['button']([
        '{icon}' => $engine->collection('icons')['save']([
            '{content}' => '...',
        ]),
        '{label}' => 'Save',
    ]);
});
```

---

# Return values and stringification

When placeholders are substituted, the engine converts values to strings.

Common cases are handled:

- strings
- integers and floats
- booleans
- `null`
- `DateTimeInterface`
- enums
- `Stringable`
- arrays and objects through JSON-style encoding

Example:

```php
$engine->register('demo', [
    'line' => '<p>{value}</p>',
]);

$demo = $engine->collection('demo');

echo $demo['line']([
    '{value}' => new DateTimeImmutable('2026-03-24 10:00:00'),
]);
```

For HTML output, escaping is still your application's responsibility.

---

# Recommended usage pattern

A reliable way to use this package is:

1. Register templates through presets.
2. Keep template strings focused on markup.
3. Use named placeholders consistently, including braces in keys.
4. Build complex sections by composing smaller template leaves.
5. Escape untrusted content at the application boundary.
6. Reuse a `TemplateEngine` instance instead of creating one per render.

A good style is to keep templates simple and move business decisions outside template strings.

---

# Error handling

`collection()` throws when the namespace is not registered.

```php
try {
    $tpl = $engine->collection('missing');
} catch (\Throwable $e) {
    // log or recover
}
```

`require()` throws when the explicit file path does not exist.

```php
try {
    $templates = $engine->require(__DIR__ . '/missing.php');
} catch (\Throwable $e) {
    // log or recover
}
```

Template files used by presets should return arrays.

---

# Example project structure

```text
src/
├── Preset/
│   └── UiPreset.php
└── templates/
    ├── default.php
    └── partials.php
```

`src/Preset/UiPreset.php`:

```php
<?php

use DalPraS\SmartTemplate\TemplateEngine;

final class UiPreset
{
    public const NAMESPACE = 'ui';

    public static function register(
        TemplateEngine $engine,
        string $templatesDir,
        bool $default = true,
    ): TemplateEngine {
        $templates = $engine->require($templatesDir . '/default.php');

        return $engine->register(self::NAMESPACE, $templates, default: $default);
    }
}
```

`src/templates/default.php`:

```php
<?php

return [
    'layout' => [
        'page' => '<main>{content}</main>',
    ],

    'partials' => $this->lazyRequire(__DIR__ . '/partials.php'),
];
```

`src/templates/partials.php`:

```php
<?php

return [
    'title' => '<h1>{text}</h1>',
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
- you want preset-based template registration

Consider a larger templating system when:

- your team wants strict separation between PHP and views
- you need inheritance, blocks, macros, filters, or expression languages
- you want a larger ecosystem of integrations and tooling

---

# Minimal reference

## `TemplateEngine`

```php
new TemplateEngine()
```

Main methods:

```php
render(string $namespace, Closure $callback): mixed
renderDefault(Closure $callback): mixed

collection(?string $namespace = null): RenderCollection
defaultCollection(): RenderCollection
hasCollection(string $namespace): bool

register(string $namespace, array|RenderCollection $templates, bool $default = false): static
addCustom(string $namespace, array $templates): static

setDefaultNamespace(string $namespace): static
getDefaultNamespace(): ?string

require(string $path): mixed
lazyRequire(string $path): LazyTemplateFile

attributes(array $attribs): string
addCustomParamCallback(string $name, Closure $callback): static
removeCustomParamCallback(string $name): bool
```

## `RenderCollection`

Collections behave like nested arrays with lazy wrapping and lazy compilation.

```php
$ui = $engine->collection('ui');

echo $ui['button']([
    '{label}' => 'Save',
]);
```

---

# Migration from filesystem loading

Previous versions allowed filesystem-oriented usage like:

```php
$engine = new TemplateEngine(
    directory: $templatesDir,
    default: 'default.php',
    preload: true,
);

$engine->render('default.php', ...);
```

The new model is preset-based:

```php
$engine = new TemplateEngine();

UiPreset::register($engine, $templatesDir);

$engine->renderDefault(...);
```

Or explicitly:

```php
$engine->render('ui', ...);
```

The engine no longer scans template directories or resolves template files by name. Files are loaded only when a preset explicitly calls `require()`.

---

# License

See the package metadata in `composer.json` / Packagist and keep the README aligned with that metadata.
