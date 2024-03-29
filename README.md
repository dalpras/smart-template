Smart Template
===============

## Introduction

Tired of template engines that create new languages that transpile to PHP and need custom service injectings or other tricks?  
Well PHP 8 have the potential for building an engine based on callbacks lightning fast.  
Just PHP! with a builtin tag replacing system and rendering functions in a very small code.  
The class `TemplateEngine` is a part of the smart-template library, designed for efficient template rendering, it's the core of the system.   
It allows you to manage templates stored in a directory that mute to callbacks rendering functions with placeholders.  
Additionally, you can customize attribute rendering for HTML elements to suit your specific needs.  

## Features

- Render templates by replacing placeholders with values.
- Support for template parts and nested rendering.
- Customize attribute rendering for HTML elements.
- Manage templates stored in a directory or add custom templates.
- Create custom callabacks that can work with your callbacks.

## Installation

As usual, composer make the job for you:

```bash

composer require dalpras/smart-template

```

## Constructor

The constructor takes a directory path as an argument and initializes the $directoryIterator to iterate through the files in that directory.

## Methods

### render()

This method is used to render a specific template part.  
It takes the name of the template part and a callback function as arguments.  
If the template has not been fetched yet, it uses the fetch() method to load the template from the directory and store it in the `$renders` array.  
The callback function is then invoked with the template's rendering function and the TemplateEngine instance as arguments. The callback is expected to provide an array of parameters to be replaced in the template.
The method returns the rendered template.


### addCustom()

This method allows adding a custom template directly without fetching it from a file.
It takes a namespace and an array of templates as arguments.
If a renderer for the specified namespace already exists, it merges the new templates into the existing renderer. Otherwise, it creates a new renderer.
The templates' values are converted to closures using the convertValuesToClosures() method.

### toClosure()

This method is used to convert a template value to a closure (anonymous function) that can be invoked with arguments to render the template.

### fetch()

This method is used to load a template from the directory based on its name.
It uses a regular expression to match the template's filename and loads the template from the corresponding file.
The loaded template is returned as a `RenderCollection object`, which is a custom collection class.

### convertValuesToClosures()

This method recursively walks through the templates and converts their values to closures using the toClosure() method.

### vnsprintf()

This method is a custom implementation of a named-param vsprintf().
It takes a format string, an array of arguments, and a callback function as arguments.
It replaces placeholders in the format string with corresponding values from the arguments array.
If an argument is a closure, it is invoked using the provided callback function before being replaced in the format string.

