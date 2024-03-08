<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use DalPraS\SmartTemplate\Collection\RenderCollection;
use DalPraS\SmartTemplate\Exception\TemplateNotFoundException;
use DalPraS\SmartTemplate\Plugins\BaseEscaper;
use DalPraS\SmartTemplate\Plugins\BaseTranslator;
use DalPraS\SmartTemplate\Plugins\EscaperInterface;
use DalPraS\SmartTemplate\Plugins\TranslatorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TemplateEngine
{
    /**
     * Keep information about the name used for searching a template file and the phisical file name.
     *
     * @var SplFileInfo[][]
     */
    private array $proxies = [];

    /**
     * Custom parameters defined for automated parameters substitutions.
     */
    private array $customParamCallbacks = [];

    /**
     * Compose the key:value pair using internal escaping functions
     */
    private Closure $attributeComposer;

    /**
     * Render the attribute key:value pair using composer
     */
    protected Closure $attributeRender;

    /**
     * Translate the text passed with a custom translator function.
     */
    private ?TranslatorInterface $translator = null;

    /**
     * Escaper used for escaping values.
     */
    private ?EscaperInterface $escaper = null;

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
    public function __construct(string $directory = null, private ?string $default = null) {
        if ( $directory !== null ) {
            if ( is_dir($directory) ) {
                $this->directoryIterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY // Iterate only over files, excluding directories
                );
            }
        }

        $this->attributeComposer = fn($name, $value) => sprintf('%s="%s"', self::escapeSprintf((string) $name), self::escapeSprintf((string) $value));
        $this->attributeRender   = function ($name, $value) {
            // format value by name
            $value = match ($name) {
                'id' => $this->escaper->escapeHtmlAttr($this->normalizeid($value)),
                'title', 'name', 'alt' => $this->escaper->escapeHtmlAttr($value),
                default => $value
            };
            return ($this->attributeComposer)($name, $value);
        };
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
    public function render(string $name, Closure $callback): mixed
    {
        $collection = $this->getRenderCollection($name);
        if ($collection === null) {
            // If it is not there, we look for the name in the filesystem
            if ($this->directoryIterator === null) {
                throw new TemplateNotFoundException('Template not found because search directory was not set');
            }
            $files = $this->find($name);
            // merge all files in one template
            foreach ($files as $fileInfo) {
                $this->addCustom($name, require($fileInfo->getRealPath()));
            }
            // try again to fecth the namespace
            $collection = $this->getRenderCollection($name);
        }
        return $callback($collection, $this) ?? '';
    }

    private function invokeArgs(string $namespace): array
    {
        return [$this->renders[$namespace], $this];
    }

    public function getRenderCollection(string $namespace): ?RenderCollection
    {
        return $this->renders[$namespace] ?? null;
    }

    /**
     * Add a custom template without scanning directories
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
     * The template returns the SplFileInfo with the name or with the partial path indicated found in the filesystem.
     *
     * @return SplFileInfo[]
     */
    private function find(string $name): array
    {
        // if the name of the RenderCollection was not setted, search in filesystem
        if ( isset($this->proxies[$name]) === false ) {
            // if its provided a real path that is a valida filepath, it's founded
            $realpath = realpath($name);
            if ( $realpath !== false && is_file($realpath) ) {
                $this->proxies[$name][] = new SplFileInfo($realpath);
            } elseif ($this->directoryIterator !== null) {
                // otherwise add all files with relative path into the specified folder
                /** @var \SplFileInfo $fileInfo */
                foreach ($this->directoryIterator as $fileInfo) {
                    if ($fileInfo->isFile() === false) continue;
                    // search for all files
                    if ( preg_match('~' . preg_quote($name, '~') . '$~', $fileInfo->getRealPath(), $matches) ) {
                        $this->proxies[$name][] = $fileInfo;
                    }
                }
            }
        }
        return $this->proxies[$name] ?? throw new TemplateNotFoundException("Could not find template {$name} in templates folder.");
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
        $filtered = $clean ? array_filter($attribs, fn($value) => ($value !== null) || ($value !== "") ) : $attribs;
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
        $invokeArgs = $this->invokeArgs($namespace);
        $collection->walk(function(&$value) use ($invokeArgs):void {
            $value = match (gettype($value)) {
                'string' => fn(array $args = []) => $this->vnsprintf($invokeArgs, $value, $args),
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
    private function vnsprintf(array $invokeArgs, string $value, array $args): string
    {
        // Apply the callback to each value
        foreach ($args as $tag => &$arg) {
            if ($arg instanceof Closure) {
                $arg = $arg(...$invokeArgs);
            }
            if (array_key_exists($tag, $this->customParamCallbacks)) {
                $arg = $this->customParamCallbacks[$tag]($arg);
            }
        }
        // Add the callabacks of the remaining custom tags
        foreach (array_diff_key($this->customParamCallbacks, $args) as $key => $customCallback) {
            $args[$key] = $customCallback(null);
        }

        // Generate placeholders without escaping %
        $replace = [];
        for ($i = 1; $i <= count($args); $i++) {
            $replace[] = "%{$i}\$s";
        }

        // Replace placeholders in the format string
        $value = str_replace(array_keys($args), $replace, self::escapeSprintf($value));        

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

    public function setAttributeRender(Closure $attributeRender): self
    {
        $this->attributeRender = $attributeRender;
        return $this;
    }

    public function setAttributeComposer(Closure $attributeComposer): self
    {
        $this->attributeComposer = $attributeComposer;
        return $this;
    }

    public function getAttributeComposer(): Closure
    {
        return $this->attributeComposer;
    }

    /**
     * Translate text using the callback tranlator function
     */
    public function trans(...$params): string
    {
        return $this->translator->trans(...$params);
    }

    /**
     * Normalizer function for "id" attribute in html Context
     */
    public function normalizeid(string $value): string
    {
        return trim(strtr($value, ['[' => '-', ']' => '']), '-');
    }

    public function getEscaper(): EscaperInterface
    {
        if ($this->escaper === null) {
            $this->escaper = new BaseEscaper();
        }
        return $this->escaper;
    }

    public function setEscaper(EscaperInterface $escaper): self
    {
        $this->escaper = $escaper;
        return $this;
    }

    public function getTranslator(): TranslatorInterface
    {

        if ($this->translator === null) {
            $this->translator = new BaseTranslator();
        }
        return $this->translator;
    }

    public function setTranslator(TranslatorInterface $translator): self
    {
        $this->translator = $translator;
        return $this;
    }

    public function addCustomParamCallback(string $name, Closure $callback): self
    {
        $this->customParamCallbacks[$name] = $callback;
        return $this;
    }

    public function removeCustomParamCallback(string $name): bool
    {
        if (isset($this->customParamCallbacks[$name])) {
            unset($this->customParamCallbacks[$name]);
            return true;
        }
        return false;
    }

    public function getCustomParamCallbacks(): array
    {
        return $this->customParamCallbacks;
    }

    public function getDefault(): ?string
    {
        return $this->default;
    }
}
