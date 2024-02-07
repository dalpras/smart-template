<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use DalPraS\SmartTemplate\Collection\RenderCollection;
use DalPraS\SmartTemplate\Exception\TemplateNotFoundException;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TemplateEngine
{
    /**
     * Keep information about the name used for searching a template file and the phisical file name.
     * 
     * @var SplFileInfo[]
     */
    private array $proxies = [];

    /**
     * Compose the key:value pair using intennal escaping functions
     */
    private Closure $attributeComposer;

    /**
     * Render the attribute key:value pair using composer
     */ 
    private Closure $attributeRender;

    /**
     * Queste sono funzioni anonime associate ad ogni elemento del template per il rendering.
     * Ogni funzione anonima incorpora la parte del template cui si riferisce.
     */
    private array $renders = [];

    /**
     * Directory where templates are stored
     */
    private ?RecursiveIteratorIterator $directoryIterator = null;

    /**
     * Inizializza la directory dove trovare i templates.
     */
    public function __construct(string $directory = null)
    {
        if ( $directory !== null ) {
            if ( is_dir($directory) === false ) {
                throw new InvalidArgumentException('Templates directory is invalid');
            }
            $this->directoryIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY // Iterate only over files, excluding directories
            );
        }
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
    public function render(string $template, Closure $callback): mixed
    {
        if ( isset($this->renders[$template]) ) {
            return $callback($this->renders[$template], $this) ?? '';
        }
        // cerchiamo i template nel filesystem
        $fileInfo = $this->find($template);

        $namespace = $fileInfo->getRealPath();
        $collection = new RenderCollection(require($namespace));
        $this->convertValuesToClosures($namespace, $collection);
        $this->renders[$namespace] = $collection;
        return $callback($this->renders[$namespace], $this) ?? '';
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
        $this->convertValuesToClosures($namespace, $this->renders[$namespace]);
        return $this;
    }

    /**
     * Associa a tutte le chiavi che sono chiamate il riferimento fisico su filesystem oppure null.
     */
    private function find(string $name): SplFileInfo
    {
        // if not setted, search in filesystem
        if ( isset($this->proxies[$name]) === false ) {
            if ($this->directoryIterator !== null) {
                /** @var \SplFileInfo $fileInfo */
                foreach ($this->directoryIterator as $fileInfo) {
                    if ($fileInfo->isFile() === false) continue;
                    // search for file
                    if ( preg_match('~' . preg_quote($name, '~') . '$~', $fileInfo->getRealPath(), $matches) ) {
                        $found = $fileInfo;
                        break;
                    }
                }
            }
            $this->proxies[$name] = $found ?? throw new TemplateNotFoundException("Could not find template in templates folder");
        }
        return $this->proxies[$name];
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

    /**
     * Process the collection by changing the values in callbacks.
     */
    private function convertValuesToClosures(string $namespace, RenderCollection &$collection): void
    {
        // recursive walk templates and convert key/value to anonymous functions
        $collection->walk(function(&$value) use ($namespace):void {
            $value = match (gettype($value)) {
                'string' => fn(array $args = []) => empty($args) ? $value : $this->vnsprintf($namespace, $value, $args),
                'object' => $value, // in case of "closure" keep the function
                default  => fn() => $value
            };
        });
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
    private function vnsprintf(string $namespace, string $value, array $args): string
    {
        // Generate placeholders without escaping %
        $replace = array_map(fn($index) => "%{$index}\$s", range(1, count($args)));

        // Replace placeholders in the format string
        $value = str_replace(array_keys($args), $replace, self::escapeSprintf($value));

        $invokeArgs = $this->invokeArgs($namespace);
        // Apply the callback to each value
        foreach ($args as &$arg) {
            if ($arg instanceof Closure) {
                $arg = $arg(...$invokeArgs());
            }
        }

        // Combine placeholders with corresponding values
        $values = array_combine($replace, $args);

        // Use vsprintf with the prepared values
        return vsprintf($value, $values);
    }

    /**
     * Escape % in the format string except for placeholders
     */
    public static function escapeSprintf(string $value): string
    {
        return preg_replace('/(?<!%)%/', '%%', $value);
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
