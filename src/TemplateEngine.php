<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use DalPraS\SmartTemplate\Collection\RenderCollection;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TemplateEngine
{
    /**
     * Compose the key:value pair using intennal escaping functions
     */
    private Closure $attributeComposer;

    /**
     * Render the attribute key:value pair using composer
     */ 
    private Closure $attributeRender;

    private bool $uglify = false;

    /**
     * Queste sono funzioni anonime associate ad ogni elemento del template per il rendering.
     * Ogni funzione anonima incorpora la parte del template cui si riferisce.
     */
    private array $renders = [];

    private RecursiveIteratorIterator $directoryIterator;

    /**
     * Inizializza la directory dove trovare i templates.
     */
    public function __construct(string $directory)
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException('Templates directory is invalid');
        }
        $this->directoryIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY // Iterate only over files, excluding directories
        );

        $this->attributeComposer = fn($name, $value) => sprintf('%s="%s"', self::escapeSprintf((string) $name), self::escapeSprintf((string) $value));
        $this->attributeRender   = fn($name, $value) => ($this->attributeComposer)($name, $value);
    }

    /**
     * Renderizza il 'template' sostituendo le variabili mediante array o callback.
     *
     * Se il template è composto da più parti sono ricorsivamente sostituite le variabili usando la callback o la semplice sostituzione.
     * La prima parte del template è il contenitore dell'elaborazione delle parti.
     *
     * Per ogni parte del template viene fornito un "renderer" nella funzione di callback.
     * Il renderer è invocato con un array di parametri da sostituire nel template e da una stringa $carry che accumula i rendering fino a quel momento svolti dal renderer.
     * I parametri da sostituire possono essere stringhe o callback (che vengono invocate prima della sostituzione).
     */
    public function render(string $template, Closure $callback): string
    {
        if ( isset($this->renders[$template]) ) {
            return $callback($this->renders[$template], $this) ?? '';
        }
        // ritorna il percorso assoluto del template cercato
        $namespace = $this->find($template);

        if ($namespace === null) {
            $collection = new RenderCollection([]);
        } else {
            $collection = new RenderCollection(require($namespace));
            self::convertValuesToClosures($collection, $this->invokeArgs($namespace), $this->uglify);
        }
        $this->renders[$namespace ?? $template] = $collection;
        return $callback($this->renders[$namespace ?? $template], $this) ?? '';
    }

    private function invokeArgs(string $namespace): Closure
    {
        return fn() => [$this->renders[$namespace], $this];
    }

    /**
     * Add a template directly not fetched from file
     */
    public function addCustom(string $namespace, array $templates): self
    {
        if ( isset($this->renders[$namespace]) && $this->renders[$namespace] instanceof RenderCollection) {
            $this->renders[$namespace]->merge($templates);
        } else {
            $this->renders[$namespace] = new RenderCollection($templates);
        }
        self::convertValuesToClosures($this->renders[$namespace], $this->invokeArgs($namespace), $this->uglify);
        return $this;
    }

    /**
     * Convert values to callback
     */
    private static function closure(string $value, callable $invokeArgs): callable
    {
        return function(array $args = []) use ($value, $invokeArgs): string {
            return self::vnsprintf( (string) $value, $args, $invokeArgs);
        };
    }

    /**
     * Trova il file più indicato e ne ritorna il percorso assoluto.
     * Se non lo trova ritorna semplicemente il nome passato.
     */
    private function find(string $name): ?string
    {
        /** @var \SplFileInfo $fileInfo */
        foreach ($this->directoryIterator as $fileInfo) {
            if ($fileInfo->isDir()) continue;
            $realPath = $fileInfo->getRealPath();
            // cerco il percorso come parte del namespace
            if ( preg_match('~' . preg_quote($name, '~') . '$~', $realPath, $matches) ) {
                return $realPath;
            }
        }
        return null;
    }

    /**
     * Apply the callback to each value of array attribs.
     * At each loop, the final result is added to previos one.
     * 
     * This function can be used inside templates as follow: self::attributes(['class' => 'world'])
     */
    public function attributes(array $attribs, bool $clean = true, string $separator = ' '): string
    {
        // remove empty values
        $filtered = $clean ? array_filter($attribs, fn($value) => $value !== null || $value !== "") : $attribs;
        $result = [];
        foreach ($filtered as $name => $value) {
            $result[$name] = ($this->attributeRender)($name, $value);
        }
        return implode($separator, array_values($result));
    }

    private static function convertValuesToClosures(RenderCollection &$collection, callable $invokeArgs, bool $uglify = false): void
    {
        // recursive walk templates and convert key/value to anonymous functions
        $collection->walk(function(&$value) use ($invokeArgs, $uglify) {
            if (($value instanceof Closure) === false) {
                // converto in stringa
                $value = (string) $value;
                // rimuovo commenti e spazi
                $value = $uglify ? self::toUglify($value) : $value;
                // converto in closure
                $value = self::closure($value, $invokeArgs);
            }
        });
    }

    private static function toUglify(string $output): string
    {
        // Use regular expression to remove PHP single-line and multi-line comments
        $output = preg_replace("/\/\/.*|\/\*[\s\S]*?\*\//", "", $output);
        // Use regular expressions to remove blank lines and extra whitespace
        $output = preg_replace("/^\s+|\s+$/m", "", $output);
        $output = preg_replace("/\s+/", " ", $output);
        return $output;
    }

    /**
     * Named-Param vsprintf()
     *
     * positional-params based on key name, much the same as positional in sprintf()
     *
     * This method takes format strings, values, and optional callback functions, and
     * generates a formatted string by replacing placeholders in the format strings with
     * corresponding values from the arguments array.
     * It also allows for additional processing of the format strings and values using callback functions if provided.
     *
     * @link http://php.net/manual/en/function.sprintf.php
     * @link http://www.php.net/manual/en/function.vsprintf.php
     */
    private static function vnsprintf(string $format, array $args, callable $invokeArgs): string
    {
        $count = count($args);
        if ($count === 0) {
            return $format;
        }

        // Generate placeholders without escaping %
        $replace = array_map(function ($index) {
            return "%{$index}\$s";
        }, range(1, $count));

        // Escape % in the format string except for placeholders
        // $format = preg_replace('/(?<!%)%/', '%%', $format);

        // Replace placeholders in the format string
        $format = str_replace(array_keys($args), $replace, self::escapeSprintf($format));

        // Apply the callback to each value
        foreach ($args as &$value) {
            if ($value instanceof Closure) {
                $value = $value(...$invokeArgs());
            }
        }

        // Combine placeholders with corresponding values
        $values = array_combine($replace, $args);

        // Use vsprintf with the prepared values
        return vsprintf($format, $values);
    }

    /**
     * Escape % in the format string except for placeholders
     */
    public static function escapeSprintf(string $value): string
    {
        return preg_replace('/(?<!%)%/', '%%', $value);
    }

    // private static function vnsprintf(string $format, array $args, callable $invokeArgs): string
    // {
    //     $count = count($args);
    //     if ($count === 0) {
    //         return $format;
    //     }
    //     // Escape "%" in the format string
    //     $format = str_replace('%', '%%', $format);

    //     $replace = preg_filter('~^(.*)$~', '%\1$s', range(1, $count));

    //     $format = str_replace(array_keys($args), $replace, $format);

    //     // applicazione della callback su ogni valore da sostituire
    //     foreach ($args as &$value) {
    //         if ($value instanceof Closure) {
    //             $value = $value(...$invokeArgs());
    //         }
    //     }

    //     $values = array_combine($replace, $args);
    //     return vsprintf($format, $values);
    // }

    /**
     * Get the value of uglify
     */
    public function isUglify(): bool
    {
        return $this->uglify;
    }

    /**
     * Set the value of uglify
     */
    public function setUglify(bool $uglify): self
    {
        $this->uglify = $uglify;
        return $this;
    }


    /**
     * Set the value of attributeRender
     */
    public function setAttributeRender(Closure $attributeRender): self
    {
        $this->attributeRender = $attributeRender;
        return $this;
    }

    /**
     * Set the value of attributeComposer
     */
    public function setAttributeComposer(Closure $attributeComposer): self
    {
        $this->attributeComposer = $attributeComposer;
        return $this;
    }

    /**
     * Get the value of attributeComposer
     */
    public function getAttributeComposer(): Closure
    {
        return $this->attributeComposer;
    }
}
