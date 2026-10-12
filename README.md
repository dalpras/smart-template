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
- Exact custom parameter callbacks
- Call-site token modifiers
- Configurable modifier separator

## What it is not

This package is **not** a full view framework.

It does not try to replace systems built around inheritance, blocks, macros, filters, or expression languages.

It works best when you want explicit PHP control over markup and composition.

---

## Installation

```bash
composer require dalpras/smart-template
```

## Requirements

- PHP 8.3 or newer

---

## Mental model

A template collection is an array registered under a namespace.

Each entry can be:

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

## 1) Create a preset

```php
<?php

namespace App\Template;

use DalPraS\SmartTemplate\PresetInterface;
use DalPraS\SmartTemplate\TemplateEngine;

final class UiPreset implements PresetInterface
{
    public const NAMESPACE = 'ui';

    public static function register(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
        array $overrides = [],
        bool $default = true,
    ): TemplateEngine {
        $engine->register($namespace, [
            'card' => <<<'HTML'
<div class="card">
    <h2>{title}</h2>
    <div>{body}</div>
</div>
HTML,
        ], default: $default);

        if ($overrides !== []) {
            $engine->register($namespace, $overrides);
        }

        return $engine;
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
<div class="card">
    <h2>Hello</h2>
    <div>This is rendered with Smart Template.</div>
</div>
```

---

# Presets

A preset is a class that registers templates into the engine.

Presets replace automatic filesystem loading. The engine does not scan directories or resolve template names from the filesystem.

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

# PresetInterface

Presets can implement `PresetInterface`.

```php
use DalPraS\SmartTemplate\PresetInterface;
use DalPraS\SmartTemplate\TemplateEngine;
use DalPraS\SmartTemplate\Collection\RenderCollection;

final class HtmlPreset implements PresetInterface
{
    public const NAMESPACE = 'html';

    public static function register(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
        array $overrides = [],
        bool $default = true,
    ): TemplateEngine {
        $templates = $engine->require(self::path());

        if (!is_array($templates)) {
            throw new \RuntimeException('HtmlPreset root template must return an array.');
        }

        $engine->register($namespace, $templates, default: $default);

        if ($overrides !== []) {
            $engine->register($namespace, $overrides);
        }

        return $engine;
    }

    public static function collection(
        TemplateEngine $engine,
        ?string $namespace = self::NAMESPACE,
    ): RenderCollection {
        return $engine->collection($namespace);
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/resources/templates/html.php';
    }
}
```

Usage:

```php
$engine = new TemplateEngine();

HtmlPreset::register($engine);

$html = $engine->collection();

echo $html['div']([
    '{attributes}' => 'class="box"',
    '{body}' => 'Hello',
]);
```

Register it under a custom namespace:

```php
HtmlPreset::register($engine, namespace: 'html', default: false);
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

# Application UI preset example

An application preset can register the built-in HTML preset and merge application templates into the same namespace.

```php
final class UiPreset implements PresetInterface
{
    public const NAMESPACE = 'ui';

    public static function register(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
        array $overrides = [],
        bool $default = true,
    ): TemplateEngine {
        $templates = $engine->require(self::path());

        if (!is_array($templates)) {
            throw new \RuntimeException(
                'UiPreset template file must return an array: ' . self::path()
            );
        }

        $engine->register($namespace, $templates, default: $default);

        if ($overrides !== []) {
            $engine->register($namespace, $overrides);
        }

        return $engine;
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/templates/default.php';
    }

    public static function collection(
        TemplateEngine $engine,
        ?string $namespace = self::NAMESPACE,
    ): RenderCollection {
        return $engine->collection($namespace);
    }
}
```

Usage:

```php
$engine = new TemplateEngine();

UiPreset::register($engine);

$ui = $engine->collection();

echo $ui['button']([
    '{label}' => 'Save',
]);
```

---

# Loading templates from PHP files

The engine provides `require()` as a low-level helper for presets.

This is explicit loading, not filesystem discovery.

```php
$templates = $engine->require(__DIR__ . '/templates/default.php');

if (!is_array($templates)) {
    throw new RuntimeException('Template file must return an array.');
}

$engine->register('ui', $templates);
```

Example template file:

```php
<?php

return [
    'card' => <<<'HTML'
<div class="card">
    <h2>{title}</h2>
    <div>{body}</div>
</div>
HTML,

    'button' => <<<'HTML'
<button>{label}</button>
HTML,
];
```

---

# Registering and extending templates

Use `register()` to create a namespace or merge templates into an existing namespace.

Create a namespace:

```php
$engine->register('ui', [
    'button' => '<button>{label}</button>',
]);
```

Merge more templates into the same namespace:

```php
$engine->register('ui', [
    'card' => '<div class="card">{body}</div>',
]);
```

Override an existing template:

```php
$engine->register('ui', [
    'button' => '<button class="btn">{label}</button>',
]);
```

Result:

```php
$ui = $engine->collection('ui');

echo $ui['button']([
    '{label}' => 'Save',
]);

echo $ui['card']([
    '{body}' => 'Hello',
]);
```

The rule is:

```php
register('new_namespace', $templates);      // create
register('existing_namespace', $templates); // merge or override
```

`addCustom()` may remain as a backward-compatible alias, but new code should use `register()`.

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

Set the default namespace manually:

```php
$engine->setDefaultNamespace('ui');
```

Access it explicitly:

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
{header}
{content}
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

A template file can reference another file lazily.

```php
<?php

return [
    'layout' => [
        'page' => '{content}',
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

# Rendering HTML attributes with `|join`

HTML attributes are serialized by `HtmlAttributes`, **not** by `TemplateEngine`.
Registering the `HtmlPreset` automatically installs the `join` modifier and an
empty default for optional `{attributes}` placeholders:

```php
use DalPraS\SmartTemplate\TemplateEngine;
use DalPraS\SmartTemplate\Preset\HtmlPreset;

$engine = new TemplateEngine();
HtmlPreset::register($engine);

// Templates still contain {attributes}; modifiers belong to the argument key.
echo $engine->collection('html')['input']([
    '{type}' => 'email',
    '{value}' => '',
    '{attributes}|join' => [
        'id' => 'user[email]',
        'name' => 'user[email]',
        'title' => 'Email address',
        'class' => 'form-control',
    ],
]);
```

An attribute list is traversed **once**, and the resulting fragments are
combined with `implode(' ', ...)`. `id` uses normalized bracket notation,
`id`/`name` are omitted when empty, `true` creates a bare boolean attribute,
`false` omits it, and closures are evaluated before rendering.
All values are quoted, including the values of attributes not in the escaping
list (quotes in those values are replaced with `&quot;`).

**Configure value escaping in one place:**

```php
use DalPraS\SmartTemplate\HtmlAttributes;

$escaper = $helpers->escaper();

(new HtmlAttributes(
    escape: static fn(string $value): string => $escaper->escapeHtml($value),
    escapedNames: ['id', 'title', 'name', 'alt', 'aria-label'],
    omitEmptyNames: ['id', 'name'],
))->register($engine);
```

This replaces the default `join` configuration for that engine, retaining
ordinary joining of lists. Only names in `escapedNames` invoke `escape`;
choose additional names (or all relevant names) based on your trust boundary.
The default policy uses `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE,
'UTF-8')` for these names, even without application helpers.
`HtmlAttributes::normalizeId()` is available if the same normalization is
needed outside templates.

You can also install this modifier without registering the HTML preset:

```php
(new HtmlAttributes())->register($engine);
```

For generic arrays, `{content}|join` joins non-null, non-empty elements
with spaces. **Do not use `{attributes}` with a raw array**: it must be
`{attributes}|join` so the HTML serializer runs. The `escapeTitles` modifier
is not needed; escaping is performed once, inside the serializer.

**Migrating from the old HTML-specific `TemplateEngine` API:** replace
`$engine->attributes($values)` with `(new HtmlAttributes())->join($values)`
when composing HTML directly outside a template. `setAttributeRender()` and
`setAttributeComposer()` are replaced by `HtmlAttributes` constructor options;
`$engine->normalizeId($id)` becomes `HtmlAttributes::normalizeId($id)`.
The engine deliberately no longer exposes those HTML-specific methods.

---

# Custom parameter callbacks

You can register callbacks that transform or provide exact token values before final substitution.

Callbacks are useful for optional tokens, fallbacks, or application-specific
transformations. For HTML attributes, prefer the `|join` modifier described
above, rather than a custom parameter callback that renders arrays.

```php
$engine->addCustomParamCallback(
    '{class}',
    static fn ($value): string => $value === null ? '' : trim((string) $value)
);
```

Example:

```php
$engine->register('ui', [
    'button' => '<button class="{class}">{label}</button>',
]);

echo $engine->collection('ui')['button']([
    '{class}' => 'btn btn-primary',
    '{label}' => 'Save',
]);
```

Custom parameter callbacks are matched by exact token name.

---

# Call-site token modifiers

Call-site token modifiers let the caller transform a value while keeping the template unchanged.

The modifier is written in the argument key, not inside the template.

Template:

```php
$engine->register('ui', [
    'title' => '<h2 class="{class}">{content}</h2>',
]);
```

Register a modifier:

```php
$engine->addCustomParamModifier(
    'upperCase',
    static fn (mixed $value): string => mb_strtoupper((string) $value, 'UTF-8')
);
```

Usage:

```php
echo $engine->collection('ui')['title']([
    '{class}' => 'fs-3',
    '{content}|upperCase' => 'hello world',
]);
```

Output:

```html
<h2 class="fs-3">HELLO WORLD</h2>
```

Internally, this:

```php
[
    '{content}|upperCase' => 'hello world',
]
```

is resolved before rendering as:

```php
[
    '{content}' => 'HELLO WORLD',
]
```

The template still contains only:

```text
{content}
```

## Chained modifiers

Modifiers can be chained.

```php
$engine->addCustomParamModifier(
    'trim',
    static fn (mixed $value): string => trim((string) $value)
);

$engine->addCustomParamModifier(
    'upperCase',
    static fn (mixed $value): string => mb_strtoupper((string) $value, 'UTF-8')
);

echo $engine->collection('ui')['title']([
    '{class}' => 'fs-3',
    '{content}|trim|upperCase' => ' hello world ',
]);
```

Output:

```html
<h2 class="fs-3">HELLO WORLD</h2>
```

## Modifiers run before parameter callbacks

Modifiers resolve a value before exact-token callbacks receive it.
For the `join` modifier, this means the callback for `{attributes}` (provided
by `HtmlAttributes::register()`) sees a **rendered string**, not an array.
A missing `{attributes}` is replaced with the empty string automatically
when `HtmlAttributes` is registered.

To customize attribute rendering, configure `HtmlAttributes` instead of
registering a second array-rendering callback:

```php
use DalPraS\SmartTemplate\HtmlAttributes;

(new HtmlAttributes())->register($engine);
$engine->register('ui', ['button' => '<button {attributes}>{content}</button>']);

echo $engine->collection('ui')['button']([
    '{attributes}|join' => ['type' => 'submit', 'class' => 'btn btn-primary'],
    '{content}' => 'Save',
]);
```

Output:

```html
<button type="submit" class="btn btn-primary">Save</button>
```

---

# Custom token styles

Smart Template does not require `{...}` tokens.

The token used in the caller only has to match the token used in the template.

For example, this template:

```php
$engine->register('ui', [
    'title' => '<h2>%content%</h2>',
]);
```

can be rendered with:

```php
echo $engine->collection('ui')['title']([
    '%content%|upperCase' => 'hello world',
]);
```

The engine resolves `%content%|upperCase` into `%content%`, then replaces `%content%` in the template.

These token styles are all valid:

```php
'{content}|upperCase'
'%content%|upperCase'
'[[content]]|upperCase'
':content:|upperCase'
'{{ content }}|upperCase'
```

The base token must not contain the configured modifier separator.

---

# Configurable modifier separator

The default modifier separator is:

```php
|
```

So this works by default:

```php
echo $engine->collection('ui')['title']([
    '{content}|upperCase' => 'hello world',
]);
```

You can choose a different separator:

```php
$engine->setCustomParamModifierSeparator('::');
```

Then use it in call-site arguments:

```php
echo $engine->collection('ui')['title']([
    '{content}::upperCase' => 'hello world',
]);
```

Chaining also uses the configured separator:

```php
echo $engine->collection('ui')['title']([
    '{content}::trim::upperCase' => ' hello world ',
]);
```

Choose a separator that does not appear inside your tokens or modifier names.

---

# Cross-collection composition

Collections can compose other registered collections by using the same engine instance.

```php
$engine->register('icons', [
    'save' => '<span>{content}</span>',
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
2. Use semantic namespaces such as `html`, `ui`, or `mail`.
3. Use `register()` again to merge into or override an existing namespace.
4. Keep template strings focused on markup.
5. Use named tokens consistently.
6. Use call-site token modifiers for caller-side transformations.
7. Escape untrusted content at the application boundary.
8. Reuse a `TemplateEngine` instance instead of creating one per render.

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
|-- Template/
|   `-- UiPreset.php
`-- templates/
    |-- default.php
    `-- partials.php
```

`src/Template/UiPreset.php`:

```php
final class UiPreset implements PresetInterface
{
    public const NAMESPACE = 'ui';

    public static function register(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
        array $overrides = [],
        bool $default = true,
    ): TemplateEngine {
        $templatesDir = dirname(__DIR__) . '/templates';

        $templates = $engine->require($templatesDir . '/default.php');

        return $engine->register($namespace, $templates, default: $default);
    }
}
```

`src/templates/default.php`:

```php
<?php

return [
    'layout' => [
        'page' => '{content}',
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

Treat output escaping as an application concern.

Use your escaper/helpers consistently when rendering untrusted data.

---

# When to choose this package

Choose Smart Template when:

- you want a small PHP-native renderer
- you prefer arrays and closures over a custom template language
- you are rendering many small reusable fragments
- you want explicit control over composition
- you want preset-based template registration
- you want caller-side transformations without template-side expressions

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
setDefaultNamespace(string $namespace): static
getDefaultNamespace(): ?string

require(string $path): mixed
lazyRequire(string $path): LazyTemplateFile

attributes(array $attributes): string

addCustomParamCallback(string $name, Closure $callback): static
removeCustomParamCallback(string $name): bool
getCustomParamCallbacks(): array

addCustomParamModifier(string $name, Closure $callback): static
removeCustomParamModifier(string $name): bool
getCustomParamModifiers(): array

setCustomParamModifierSeparator(string $separator): static
getCustomParamModifierSeparator(): string
```

## `PresetInterface`

```php
public static function register(
    TemplateEngine $engine,
    string $namespace = '',
    array $overrides = [],
    bool $default = true,
): TemplateEngine;
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

UiPreset::register($engine);

$engine->renderDefault(...);
```

Or explicitly:

```php
$engine->render('ui', ...);
```

The engine no longer scans template directories or resolves template files by name.

Files are loaded only when a preset explicitly calls `require()`.

Avoid using filenames as namespaces:

```php
// Avoid
public const NAMESPACE = 'default.php';

// Prefer
public const NAMESPACE = 'ui';
```

---

# License

See the package metadata in `composer.json` / Packagist and keep the README aligned with that metadata.
