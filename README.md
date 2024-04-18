Smart Template
===============

## Introduction

Stop using **template engines** with new languages and new tricks to learn!  
This is the right place: `smart-template` have the potential for building an **engine** based on callbacks that is lightning fast.  

This is a full rendering system in a few lines of code.  
`smart-template` library is designed for **fast** and **efficient** rendering without the **mess** of complicated libraries.   

**There are no dependencies!!**  

You will only use what you really need.  

## Features

- key->value substitutions.
- Nested templates files in a structered folders to access anything you need.
- Deep nested rendering.
- Customized attributes rendering for HTML elements.
- Callbacks for customized rendering.
- ...

## Installation

As usual, composer make the job for you:

```bash
composer require dalpras/smart-template
```

## Examples
Let's take a look to an example ...  
Just some lines of code in different files.  

```php

/** ./mywebpage.php */
$templateEngine = new TemplateEngine(__DIR__ . '/templates');

echo $templateEngine->render('table.php', function ($render) {
        return $render['table']([
            '{class}' => 'text-right',
            '{cols}' => $render['row'](['{text}' => 'hello datum!'])
        ]);
    });

// or you can begin to nest everything you need

echo $templateEngine->render('table.php', function ($render) {
        return $render['table']([
            '{class}' => 'text-right',
            '{rows}' => function($render) {
                return $render['row'](['{text}' => 'hello nested!']);
            },
        ]);
    });


// or just use another file for rendering

echo $templateEngine->render('toolbar.php', function ($render) {
        return $render['header']([
            '{text}' => 'hello toolbar!'
        ]);
    });

// also you can use many files as you want

echo $templateEngine->render('table.php', function ($render) {
        return $render['table']([
            '{class}' => 'text-right',
            '{rows}' => function($render, TemplateEngine $template) {
                return $template->render('toolbar.php', fn($render) => $render['header']([
                    '{text}' => 'hello different template toolbar'
                ]));
            },
        ]);
    });

// or custom specific functions for rendering

echo $templateEngine->render('table.php', function ($render) {
        return $render['table']([
            '{class}' => 'text-right',
            '{rows}' => $render['myfunc']('hello func!'),
        ]);
    });


// GREAT POWER INVOLVES GREAT RESPONSIBILITY!
```


```php
/** ./templates/table.php */
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
        <tr><td>{text}</tr></td>
        html,
];
```


```php
/** ./templates/nesteddir/toolbar.php */
return [
    'header' => <<<html
        <div class="toolbar">{text}</div>
        html,
        
    'myfunc' => fn($text) => 'This is your ' . $text
];
```