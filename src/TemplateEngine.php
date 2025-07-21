<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use DalPraS\SmartTemplate\Collection\RenderCollection;
use DalPraS\SmartTemplate\Exception\TemplateNotFoundException;
use DalPraS\SmartTemplate\Plugins\HelpersInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Main template engine for loading, parsing, and rendering templates.
 */
class TemplateEngine
{
    /**
     * Store discovered templates as [templateName => SplFileInfo[]].
     *
     * @var array<string, SplFileInfo[]>
     */
    private array $proxies = [];

    /**
     * Custom parameter callbacks (key => function).
     *
     * @var array<string, Closure>
     */
    private array $customParamCallbacks = [];

    /**
     * Compose the attribute key:value pair (escaping logic).
     */
    private Closure $attributeComposer;

    /**
     * Render the attribute by name and value.
     */
    protected Closure $attributeRender;

    /**
     * Manage all helper utilities needed by TemplateEngine (escaper, etc.).
     */
    protected ?HelpersInterface $helpers = null;

    /**
     * Store compiled template parts as [namespace => RenderCollection].
     *
     * @var array<string, RenderCollection>
     */
    private array $renders = [];

    /**
     * A directory iterator for scanning template files (optional).
     */
    private ?RecursiveIteratorIterator $directoryIterator = null;

    /**
     * @param string|null $directory  Base directory for scanning templates.
     * @param string|null $default    Default template name to preload.
     * @param bool        $preload    Whether to immediately load the default template.
     */
    public function __construct(
        ?string $directory = null,
        private ?string $default = null,
        bool $preload = true
    ) {
        if ($directory && is_dir($directory)) {
            $this->directoryIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            // Preload default template
            if ($preload && $default !== null) {
                $files = $this->find($default);
                foreach ($files as $fileInfo) {
                    $this->addCustom($default, require $fileInfo->getRealPath());
                }
            }
        }

        // Default closures for attributes
        $this->attributeComposer = fn($name, $value)
            => $name . '="' . str_replace('"', '&quot;', (string)$value) . '"';

        $this->attributeRender = function ($name, $value) {
            // Format value by attribute name
            $value = match ($name) {
                'id'    => $this->helpers?->escaper()?->escapeHtmlAttr($this->normalizeid($value)) ?? $value,
                'title', 'name', 'alt'
                       => $this->helpers?->escaper()?->escapeHtmlAttr($value) ?? $value,
                default => $value
            };
            return ($this->attributeComposer)($name, $value);
        };
    }

    /**
     * Render the specified template via a callback.
     */
    public function render(string $name, Closure $callback): mixed
    {
        $collection = $this->getCollection($name);

        // Execute user callback
        return $callback($collection, $this, $name) ?? '';
    }

    public function getCollection(string $name): ?RenderCollection
    {
        $collection = $this->renders[$name] ?? null;
        if ($collection === null) {
            if ($this->directoryIterator === null) {
                throw new TemplateNotFoundException(
                    'Template not found: no search directory set.'
                );
            }

            // Try to load from the filesystem
            $files = $this->find($name);
            foreach ($files as $fileInfo) {
                $this->addCustom($name, require $fileInfo->getRealPath());
            }
            // Fetch again now that it’s loaded
            $collection = $this->renders[$name] ?? null;;
        }

        // If still null, it means not found
        if ($collection === null) {
            throw new TemplateNotFoundException("Template not found: $name");
        }

        return $collection;
    }

    /**
     * Add a custom template (bypassing directory scanning).
     */
    public function addCustom(string $namespace, array $templates): self
    {
        if (isset($this->renders[$namespace])) {
            $this->renders[$namespace]->merge($templates);
        } else {
            $this->renders[$namespace] = new RenderCollection($templates);
        }
        $this->convertValuesToClosures($namespace, $this->renders[$namespace]);
        return $this;
    }

    /**
     * Find the template by name or partial path in the filesystem.
     *
     * @return SplFileInfo[]
     */
    private function find(string $name): array
    {
        if (!isset($this->proxies[$name])) {
            $realpath = realpath($name);
            // If $name is a direct file path
            if ($realpath && is_file($realpath)) {
                $this->proxies[$name][] = new SplFileInfo($realpath);
            } elseif ($this->directoryIterator !== null) {
                /** @var SplFileInfo $fileInfo */
                foreach ($this->directoryIterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }
                    // Search by partial path
                    $pattern = '~' . preg_quote(DIRECTORY_SEPARATOR . $name, '~') . '$~';
                    if (preg_match($pattern, $fileInfo->getRealPath())) {
                        $this->proxies[$name][] = $fileInfo;
                    }
                }
            }
        }

        if (empty($this->proxies[$name])) {
            throw new TemplateNotFoundException("Could not find template '$name' in templates folder.");
        }
        return $this->proxies[$name];
    }

    /**
     * Generate HTML attributes from an array of name => value.
     */
    public function attributes(array $attribs): string
    {
        $result = [];
        foreach ($attribs as $name => $value) {
            if ($value instanceof Closure) {
                $value = $value();
            }
            $result[$name] = ($this->attributeRender)($name, $value);
        }
        return implode(' ', $result);
    }

    /**
     * Convert string values into closure-based "renders".
     */
    private function convertValuesToClosures(string $namespace, RenderCollection $collection): void
    {
        $invokeArgs = [$collection, $this, $namespace];
        $collection->walk(function (&$value) use ($invokeArgs) {
            $value = match (gettype($value)) {
                'string' => fn(array $args = []) => self::vnsprintf(
                    $invokeArgs,
                    $value,
                    $args,
                    function (array $args): array {
                        // Convert any known tags via callbacks
                        foreach ($args as $tag => &$arg) {
                            if (isset($this->customParamCallbacks[$tag])) {
                                $arg = $this->customParamCallbacks[$tag]($arg);
                            }
                        }
                        // For callbacks not in $args, assign them null
                        foreach (array_diff_key($this->customParamCallbacks, $args) as $k => $cb) {
                            $args[$k] = $cb(null);
                        }
                        return $args;
                    }
                ),
                'object' => $value, // e.g. existing Closure or object
                default  => fn() => $value
            };
        });
    }

    /**
     * Named-Param vsprintf() that calls any closures before substitution.
     */
    public static function vnsprintf(
        array $invokeArgs,
        string $value,
        array $args,
        ?Closure $resolve = null
    ): string {
        // Resolve argument closures
        foreach ($args as &$arg) {
            if ($arg instanceof Closure) {
                $arg = $arg(...$invokeArgs);
            }
        }
        // Additional argument resolution if provided
        if ($resolve !== null) {
            $args = $resolve($args);
        }

        // Prepare placeholders
        $replace = [];
        for ($i = 1; $i <= count($args); $i++) {
            $replace[] = "%{$i}\$s";
        }

        // Replace named placeholders with indexed placeholders
        $value = str_replace(array_keys($args), $replace, self::escapeSprintf($value));

        // Combine placeholders with corresponding values
        $values = array_combine($replace, $args);

        return vsprintf($value, $values);
    }

    /**
     * Escape raw '%' in a format string to '%%' (so vsprintf won't interpret them).
     */
    public static function escapeSprintf(string $value): string
    {
        return preg_replace('/(?<!%)%/', '%%', $value);
    }

    /**
     * Set custom closure for rendering attributes.
     */
    public function setAttributeRender(Closure $attributeRender): self
    {
        $this->attributeRender = $attributeRender;
        return $this;
    }

    /**
     * Set custom closure for composing attribute pairs (key => value).
     */
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
     * Convert bracket-based arrays in 'id' attributes into dash-based (HTML-friendly).
     */
    public function normalizeid(string $value): string
    {
        return trim(strtr($value, ['[' => '-', ']' => '']), '-');
    }

    /**
     * Register a custom callback for param placeholders (e.g. :name => function).
     */
    public function addCustomParamCallback(string $name, Closure $callback): self
    {
        $this->customParamCallbacks[$name] = $callback;
        return $this;
    }

    /**
     * Remove a previously registered custom param callback.
     */
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

    public function getHelpers(): ?HelpersInterface
    {
        return $this->helpers;
    }

    public function setHelpers(?HelpersInterface $helpers): self
    {
        $this->helpers = $helpers;
        return $this;
    }
}
