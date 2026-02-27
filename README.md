# Smart Template

Lightning-fast, dependency-free PHP template engine based on **lazy
closures** and **named placeholder substitution**.

No DSL.\
No new syntax to learn.\
No parser overhead.\
Just pure PHP and structured rendering.

------------------------------------------------------------------------

## 🚀 Introduction

Stop using template engines with new languages and new tricks to learn!

`smart-template` is a minimal yet powerful rendering system built
entirely around:

-   PHP arrays
-   Closures
-   Named placeholders
-   Lazy evaluation

Designed for **speed**, **clarity**, and **full control**.

### No:

-   ❌ Dependencies
-   ❌ Custom template language
-   ❌ Runtime parser
-   ❌ Compilation step

Everything is explicit, predictable, and fast.

------------------------------------------------------------------------

## ✨ Features

-   Named `{key}` substitutions
-   Deep nested rendering
-   Lazy compilation (compile-on-access)
-   Nested template folders
-   Custom attribute rendering
-   Custom placeholder callbacks
-   `$this->require()` support inside templates
-   Fully PHP-native
-   OPcache-friendly
-   Zero dependencies

------------------------------------------------------------------------

## 📦 Installation

``` bash
composer require dalpras/smart-template
```

------------------------------------------------------------------------

## 🧠 Basic Usage

``` php
use DalPraS\SmartTemplate\TemplateEngine;

$templateEngine = new TemplateEngine(__DIR__ . '/templates');

echo $templateEngine->render('table.php', function ($render) {
    return $render['table']([
        '{class}' => 'text-end',
        '{rows}'  => $render['row'](['{text}' => 'hello datum!'])
    ]);
});
```

------------------------------------------------------------------------

## 🧩 Nested Rendering

``` php
echo $templateEngine->render('table.php', function ($render) {
    return $render['table']([
        '{class}' => 'text-end',
        '{rows}'  => function ($render) {
            return $render['row'](['{text}' => 'hello nested!']);
        },
    ]);
});
```

Closures inside placeholders are resolved automatically.

------------------------------------------------------------------------

## 📂 Rendering Different Templates

``` php
echo $templateEngine->render('toolbar.php', function ($render) {
    return $render['header']([
        '{text}' => 'hello toolbar!'
    ]);
});
```

------------------------------------------------------------------------

## 🔁 Cross-Template Rendering

``` php
echo $templateEngine->render('table.php', function ($render) use ($templateEngine) {
    return $render['table']([
        '{class}' => 'text-end',
        '{rows}'  => function () use ($templateEngine) {
            return $templateEngine->render('toolbar.php', fn($render) =>
                $render['header']([
                    '{text}' => 'hello different template toolbar'
                ])
            );
        },
    ]);
});
```

------------------------------------------------------------------------

## 🛠 Custom Functions Inside Templates

``` php
echo $templateEngine->render('table.php', function ($render) {
    return $render['table']([
        '{class}' => 'text-end',
        '{rows}'  => $render['myfunc']('hello func!'),
    ]);
});
```

------------------------------------------------------------------------

## 📄 Example Template Files

### ./templates/table.php

``` php
return [
    'table' => <<<html
        <div class="table-responsive">
            <table class="table table-sm {class}">
                <tbody>
                    {rows}
                </tbody>
            </table>
        </div>
    html,

    'row' => <<<html
        <tr><td>{text}</td></tr>
    html,
];
```

### ./templates/nesteddir/toolbar.php

``` php
return [
    'header' => <<<html
        <div class="toolbar">{text}</div>
    html,

    'myfunc' => fn($text) => 'This is your ' . $text
];
```

------------------------------------------------------------------------

## 🧬 How It Works

1.  Templates return arrays.
2.  Arrays are wrapped in `RenderCollection`.
3.  Strings are lazily converted into closures.
4.  Placeholders are replaced using fast `strtr()`.
5.  Closures inside arguments are resolved automatically.
6.  Nested collections resolve recursively.

There is no parsing stage.

------------------------------------------------------------------------

## 🏎 Performance Notes

-   Uses `strtr()` instead of `vsprintf()` for speed.
-   Lazy compilation avoids unnecessary processing.
-   Designed for PHP OPcache.
-   No reflection.
-   No regex-based parsing.

With proper usage, performance is comparable or faster than traditional
engines in component-based rendering.

------------------------------------------------------------------------

## ⚠ Responsibility

With great power comes great responsibility.

This engine does not automatically protect against:

-   XSS (use escapers properly)
-   Logical complexity
-   Over-nesting closures

Use responsibly.

------------------------------------------------------------------------

## 📜 License

MIT License
