<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use DalPraS\SmartTemplate\Cache\CacheInterface;
use DalPraS\SmartTemplate\Cache\CacheKey;
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
     * Store compiled template parts as [namespace => RenderCollection].
     *
     * @var array<string, RenderCollection>
     */
    private array $renders = [];

    /**
     * Basename index to speed up suffix matching.
     *
     * @var array<string, SplFileInfo[]>
     */
    private array $indexByBasename = [];

    private bool $fsIndexed = false;

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
        bool $preload = true,
        private ?CacheInterface $cache = null,
        private int $templateCacheTtl = 86400
        // private bool $cacheStrictMtime = false
    ) {
        if ($directory && is_dir($directory)) {
            $this->directoryIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            if ($preload && $default !== null) {
                $this->loadFromFilesystem($default);
            }
        }

        $this->attributeComposer = static fn($name, $value): string
        => $name . '="' . str_replace('"', '&quot;', (string) $value) . '"';

        $this->attributeRender = function ($name, $value): string {
            $value = match ($name) {
                'id' => ($this->helpers?->escaper()?->escapeHtmlAttr($this->normalizeId((string) $value))) ?? (string) $value,
                'title', 'name', 'alt' => $this->helpers?->escaper()?->escapeHtmlAttr((string) $value) ?? (string) $value,
                default => $value,
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
        return $callback($collection, $name) ?? '';
    }

    public function getCollection(string $name): RenderCollection
    {
        $collection = $this->renders[$name] ?? null;

        if ($collection === null) {
            if ($this->directoryIterator === null) {
                throw new TemplateNotFoundException('Template not found: no search directory set.');
            }

            $this->loadFromFilesystem($name);
            $collection = $this->renders[$name] ?? null;
        }

        if ($collection === null) {
            throw new TemplateNotFoundException("Template not found: {$name}");
        }

        return $collection;
    }

    /**
     * Add a custom template (bypassing directory scanning).
     */
    public function addCustom(string $namespace, array $templates): static
    {
        if (isset($this->renders[$namespace])) {
            $this->renders[$namespace]->merge($templates);
        } else {
            $this->renders[$namespace] = new RenderCollection($templates);
        }

        // Lazy compile only when a key is accessed
        $collection = $this->renders[$namespace];
        $collection->setRoot($collection);
        $collection->setLazyCompiler(fn(mixed $value, string|int $key, RenderCollection $self) => $this->compileLazy($namespace, $value, $self));
        return $this;
    }

    private function compileLazy(string $namespace, mixed $value, RenderCollection $self): mixed
    {
        if ($value instanceof LazyTemplateFile) {
            $loaded = $this->require($value->path);

            if (is_array($loaded)) {
                $nested = new RenderCollection($loaded);
                $nested->setRoot($self->getRoot());
                $nested->setLazyCompiler(
                    fn(mixed $nestedValue, string|int $nestedKey, RenderCollection $nestedSelf)
                    => $this->compileLazy($namespace, $nestedValue, $nestedSelf)
                );

                return $nested;
            }

            return $loaded;
        }

        if ($value instanceof \Closure) {
            return $value;
        }

        if (is_object($value) && is_callable($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return $this->asRender($value, $namespace, $self->getRoot());
    }

    public function require(string $path): mixed
    {
        $real = realpath($path);
        if ($real === false) {
            throw new \RuntimeException("Template include not found: {$path}");
        }

        $key = CacheKey::templateFile($real);

        if ($this->cache) {
            $cached = $this->cache->get($key);
            if (is_array($cached) && array_key_exists('data', $cached)) {
                return $cached['data'];
            }
        }

        $loaded = (function () use ($real) {
            return require $real;
        })->call($this);

        // Optional: guard against unexpected nulls if you expect arrays
        // if ($loaded === null) { ...log... }

        if ($this->cache && $this->isCacheable($loaded)) {
            $this->cache->set($key, ['data' => $loaded], $this->templateCacheTtl);
        }

        return $loaded;
    }

    public function lazyRequire(string $path): LazyTemplateFile
    {
        return new LazyTemplateFile($path);
    }

    private function isCacheable(mixed $v): bool
    {
        if ($v instanceof \Closure) return false;
        if (is_resource($v)) return false;
        if (is_object($v)) return false; // safest

        if (is_array($v)) {
            foreach ($v as $item) {
                if (!$this->isCacheable($item)) return false;
            }
        }

        return true;
    }

    /**
     * Load a template namespace from filesystem by name.
     */
    private function loadFromFilesystem(string $name): void
    {
        $files = $this->find($name);

        foreach ($files as $fileInfo) {
            $path = $fileInfo->getRealPath();
            if ($path === false) continue;

            $templates = $this->require($path);
            if (!is_array($templates)) {
                throw new \RuntimeException("Template file must return array: {$path}");
            }

            $this->addCustom($name, $templates);
        }
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
            if ($realpath && is_file($realpath)) {
                $this->proxies[$name] = [new SplFileInfo($realpath)];
            } elseif ($this->directoryIterator !== null) {
                $this->buildFsIndex();

                $needle = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
                $basename = basename($needle);

                $matches = [];
                if (!empty($this->indexByBasename[$basename])) {
                    $suffix = DIRECTORY_SEPARATOR . $needle;
                    $suffixLen = strlen($suffix);

                    foreach ($this->indexByBasename[$basename] as $fi) {
                        $path = $fi->getRealPath();
                        if ($path !== false && strlen($path) >= $suffixLen && substr_compare($path, $suffix, -$suffixLen) === 0) {
                            $matches[] = $fi;
                        }
                    }
                }

                if ($matches) {
                    $this->proxies[$name] = $matches;
                }
            }
        }

        if (empty($this->proxies[$name])) {
            throw new TemplateNotFoundException("Could not find template '{$name}' in templates folder.");
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
            if (!$fileInfo->isFile()) {
                continue;
            }
            $this->indexByBasename[$fileInfo->getBasename()][] = $fileInfo;
        }

        $this->fsIndexed = true;
    }

    /**
     * Generate HTML attributes from an array of name => value.
     */
    public function attributes(array $attribs): string
    {
        if ($attribs === []) {
            return '';
        }

        $render = $this->attributeRender; // local var is faster than property access
        $out = [];

        foreach ($attribs as $name => $value) {
            if ($value instanceof Closure) {
                $value = $value();
            }
            $out[] = $render($name, $value);
        }

        return implode(' ', $out);
    }

    /**
     * Convert a single value into a render callable without registering it.
     */
    protected function asRender(mixed $value, string $namespace, RenderCollection $root): Closure
    {
        $invokeArgs = [$root, $this, $namespace];

        if (is_string($value)) {
            return function (array $args = []) use ($invokeArgs, $value): string {
                return self::vnsprintf(
                    $invokeArgs,
                    $value,
                    $args,
                    function (array $args): array {
                        foreach ($args as $tag => &$arg) {
                            if (isset($this->customParamCallbacks[$tag])) {
                                $arg = $this->customParamCallbacks[$tag]($arg);
                            }
                        }
                        unset($arg);

                        foreach (array_diff_key($this->customParamCallbacks, $args) as $k => $cb) {
                            $args[$k] = $cb(null);
                        }

                        return $args;
                    }
                );
            };
        }

        if (is_object($value) && is_callable($value)) {
            /** @var Closure $value */
            return $value;
        }

        return static fn() => $value;
    }

    /**
     * Named-Param vsprintf() that calls any closures before substitution,
     * and supports objects via a pluggable $stringify closure.
     */
    public static function vnsprintf(
        array $invokeArgs,
        string $template,
        array $args,
        ?Closure $resolve = null,
        ?Closure $stringify = null,
        array $options = []
    ): string {
        foreach ($args as $k => $v) {
            if ($v instanceof Closure) {
                $v = $v(...$invokeArgs);
            }
            $args[$k] = $v;
        }

        if ($resolve !== null) {
            $args = $resolve($args);
        }

        $stringify ??= static fn($v, $key): string => self::defaultStringify($v, $key, $options);

        // stringify once, then plain replace
        foreach ($args as $k => $v) {
            $args[$k] = $stringify($v, $k);
        }

        return strtr($template, $args);
    }

    private static function defaultStringify(mixed $v, string|int $key, array $options): string
    {
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string) $v;
        if (is_bool($v)) return $v ? '1' : '0';
        if ($v === null) return '';

        if ($v instanceof \DateTimeInterface) {
            $fmt = $options['date_format'] ?? \DATE_ATOM;
            return $v->format($fmt);
        }

        if ($v instanceof \BackedEnum) {
            return (string) $v->value;
        }
        if ($v instanceof \UnitEnum) {
            return $v->name;
        }

        if ($v instanceof \Stringable) {
            return (string) $v;
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return (string) $v;
        }

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
            return self::json(get_object_vars($v));
        }

        return (string) $v;
    }

    private static function json(mixed $v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return var_export($v, true);
        }
        return $json;
    }

    public function setAttributeRender(Closure $attributeRender): static
    {
        $this->attributeRender = $attributeRender;
        return $this;
    }

    public function setAttributeComposer(Closure $attributeComposer): static
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

    public function addCustomParamCallback(string $name, Closure $callback): static
    {
        $this->customParamCallbacks[$name] = $callback;
        return $this;
    }

    public function removeCustomParamCallback(string $name): bool
    {
        if (!isset($this->customParamCallbacks[$name])) {
            return false;
        }
        unset($this->customParamCallbacks[$name]);
        return true;
    }

    /**
     * @return array<string, Closure>
     */
    public function getCustomParamCallbacks(): array
    {
        return $this->customParamCallbacks;
    }

    public function getHelpers(): ?HelpersInterface
    {
        return $this->helpers;
    }

    public function setHelpers(?HelpersInterface $helpers): static
    {
        $this->helpers = $helpers;
        return $this;
    }
}
