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

    /** @var array<string, SplFileInfo[]> */
    private array $indexByBasename = [];
    
    /** @var bool */
    private bool $fsIndexed = false;

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
                'id'
                    => ($this->helpers?->escaper()?->escapeHtmlAttr($this->normalizeId((string)$value))) ?? (string)$value,
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
            $collection = $this->renders[$name] ?? null;
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
     */
    private function find(string $name): array
    {
        if (!isset($this->proxies[$name])) {
            $realpath = realpath($name);
            if ($realpath && is_file($realpath)) {
                $this->proxies[$name][] = new SplFileInfo($realpath);
            } elseif ($this->directoryIterator !== null) {
                $this->buildFsIndex();

                // Normalize separator in $name and extract trailing basename
                $needle = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
                $basename = basename($needle);

                // Candidate set from basename index, then filter by exact suffix match
                if (!empty($this->indexByBasename[$basename])) {
                    $suffix = DIRECTORY_SEPARATOR . $needle;
                    $len = strlen($suffix);
                    foreach ($this->indexByBasename[$basename] as $fi) {
                        $path = $fi->getRealPath();
                        // fast "endsWith" check
                        if (strlen($path) >= $len && substr_compare($path, $suffix, -$len) === 0) {
                            $this->proxies[$name][] = $fi;
                        }
                    }
                }
            }
        }

        if (empty($this->proxies[$name])) {
            throw new TemplateNotFoundException("Could not find template '$name' in templates folder.");
        }
        return $this->proxies[$name];
    }

    private function buildFsIndex(): void
    {
        if ($this->fsIndexed || $this->directoryIterator === null) {
            return;
        }
        /** @var SplFileInfo $fileInfo */
        foreach ($this->directoryIterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $base = $fileInfo->getBasename();               // ex: "card.php"
            $this->indexByBasename[$base][] = $fileInfo;
        }
        $this->fsIndexed = true;
    }

    /**
     * Generate HTML attributes from an array of name => value.
     */
    public function attributes(array $attribs): string
    {
        if (empty($attribs)) {
            return '';
        }
        $render = $this->attributeRender; // local var is faster than property access
        $out = [];
        foreach ($attribs as $name => $value) {
            if ($value instanceof Closure) {
                $value = $value(); // consider passing context if you want parity
            }
            $out[] = $render($name, $value);
        }
        return implode(' ', $out);
    }

    /**
     * Convert a single value into a render callable without registering it.
     */
    protected function asRender(mixed $value, string $namespace, RenderCollection $collection): Closure
    {
        $invokeArgs = [$collection, $this, $namespace];

        if (is_string($value)) {
            return function (array $args = []) use ($invokeArgs, $value) {
                return self::vnsprintf(
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
                        unset($arg);
                        // For callbacks not in $args, assign them null
                        foreach (array_diff_key($this->customParamCallbacks, $args) as $k => $cb) {
                            $args[$k] = $cb(null);
                        }
                        return $args;
                    }
                );
            };
        }
        if (is_object($value) && is_callable($value)) {
            return $value;
        }
        return fn() => $value;
    }

    /**
     * Public helper: compile a single template item into a callable without storing it.
     * Unlike addCustom(), this does not register the result in $renders
     */
    public function makeRender(mixed $value, ?string $namespace = null): callable
    {
        // Ephemeral collection gives the closure its usual context
        $collection = new RenderCollection([]);
        $ns = $namespace ?? '__inline_item_' . spl_object_id($collection);
        return $this->asRender($value, $ns, $collection);
    }

    /**
     * Public helper: compile an array of template items into a RenderCollection
     * without merging into $this->renders.
     * Unlike addCustom(), this does not register the result in $renders.
     */
    public function makeRenderCollection(array $templates, ?string $namespace = null): RenderCollection
    {
        $collection = new RenderCollection($templates);
        $ns = $namespace ?? '__inline_' . spl_object_id($collection);

        // Reuse existing batch conversion, but don’t store anywhere.
        $this->convertValuesToClosures($ns, $collection);
        return $collection;
    }

    // --- adjust existing method to use asRender() internally ---
    private function convertValuesToClosures(string $namespace, RenderCollection $collection): void
    {
        $collection->walk(function (&$value) use ($namespace, $collection) {
            $value = $this->asRender($value, $namespace, $collection);
        });
    }

    /**
     * Named-Param vsprintf() that calls any closures before substitution,
     * and supports objects via a pluggable $stringify closure.
     */
    public static function vnsprintf(
        array $invokeArgs,
        string $value,
        array $args,
        ?Closure $resolve = null,
        ?Closure $stringify = null,           // <— NEW: per-value stringifier
        array $options = []                   // e.g. ['date_format' => DATE_ATOM]
    ): string {
        // Resolve closures in args
        foreach ($args as &$arg) {
            if ($arg instanceof Closure) {
                $arg = $arg(...$invokeArgs);
            }
        }
        unset($arg);

        // Optional argument post-processing
        if ($resolve !== null) {
            $args = $resolve($args);
        }

        // Placeholder mapping (named → indexed)
        $replace = [];
        $i = 1;
        $orderedValues = [];

        $stringify ??= fn($v, $key) => self::defaultStringify($v, $key, $options);

        foreach ($args as $k => $v) {
            $replace[$k] = "%" . $i++ . "\$s";
            // Convert ANY value (scalars, arrays, objects…) to a string once, here
            $orderedValues[] = $stringify($v, $k);
        }

        $escaped  = self::escapeSprintf($value);   // your existing percent-escaper
        $indexed  = strtr($escaped, $replace);

        return vsprintf($indexed, $orderedValues);
    }

    /**
     * Sensible defaults for turning values into strings for %s.
     * You can override per-call via the $stringify closure.
     */
    private static function defaultStringify(mixed $v, string|int $key, array $options): string
    {
        // Fast-path scalars
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string) $v;
        if (is_bool($v)) return $v ? '1' : '0';
        if ($v === null) return '';

        // Datetime
        if ($v instanceof \DateTimeInterface) {
            $fmt = $options['date_format'] ?? \DATE_ATOM;
            return $v->format($fmt);
        }

        // Enums
        if ($v instanceof \BackedEnum) {
            return (string)$v->value;
        }
        if ($v instanceof \UnitEnum) {
            return $v->name;
        }

        // Stringable / __toString
        if ($v instanceof \Stringable) {
            return (string)$v;
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return (string)$v;
        }

        // Json-serializable / arrays / traversables → compact JSON
        if ($v instanceof \JsonSerializable) {
            return self::json($v->jsonSerialize());
        }
        if ($v instanceof \Traversable) {
            return self::json(iterator_to_array($v));
        }
        if (is_array($v)) {
            return self::json($v);
        }
        if (is_object($v)) {
            // Best-effort: public props only
            return self::json(get_object_vars($v));
        }

        // Fallback
        return (string)$v;
    }

    private static function json(mixed $v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Last-resort dump if JSON fails
            return var_export($v, true);
        }
        return $json;
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
    public function normalizeId(string $value): string
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
