<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use DalPraS\SmartTemplate\Collection\RenderCollection;
use DalPraS\SmartTemplate\Exception\TemplateNotFoundException;
use DalPraS\SmartTemplate\Plugins\HelpersInterface;

class TemplateEngine
{
    /**
     * Registered template collections by namespace.
     *
     * @var array<string, RenderCollection>
     */
    private array $renders = [];

    /**
     * Default namespace used by collection() and renderDefault().
     */
    private ?string $defaultNamespace = null;

    /**
     * Placeholder callbacks.
     *
     * @var array<string, Closure>
     */
    private array $customParamCallbacks = [];

    /**
     * Builds a single attribute string.
     */
    private Closure $attributeComposer;

    /**
     * Renders an attribute after normalization/escaping.
     */
    protected Closure $attributeRender;

    protected ?HelpersInterface $helpers = null;

    public function __construct()
    {
        $this->attributeComposer = static fn($name, $value): string
            => $name . '="' . str_replace('"', '&quot;', (string) $value) . '"';

        $this->attributeRender = function ($name, $value): string {
            $value = match ($name) {
                'id' => ($this->helpers?->escaper()?->escapeHtmlAttr(
                    $this->normalizeId((string) $value)
                )) ?? (string) $value,

                'title', 'name', 'alt' => $this->helpers?->escaper()?->escapeHtmlAttr(
                    (string) $value
                ) ?? (string) $value,

                default => $value,
            };

            return ($this->attributeComposer)($name, $value);
        };
    }

    /**
     * Render a named collection.
     */
    public function render(string $namespace, Closure $callback): mixed
    {
        return $callback($this->collection($namespace), $namespace) ?? '';
    }

    /**
     * Render the default collection.
     */
    public function renderDefault(Closure $callback): mixed
    {
        $namespace = $this->getDefaultNamespaceOrFail();

        return $callback($this->collection($namespace), $namespace) ?? '';
    }

    /**
     * Get a collection, or the default one when omitted.
     */
    public function collection(?string $namespace = null): RenderCollection
    {
        $namespace ??= $this->getDefaultNamespaceOrFail();

        return $this->renders[$namespace]
            ?? throw new TemplateNotFoundException(
                "Template collection '{$namespace}' is not registered."
            );
    }

    /**
     * Get the default collection.
     */
    public function defaultCollection(): RenderCollection
    {
        return $this->collection();
    }

    public function hasCollection(string $namespace): bool
    {
        return isset($this->renders[$namespace]);
    }

    /**
     * Register or merge templates under a namespace.
     */
    public function register(
        string $namespace,
        array|RenderCollection $templates,
        bool $default = false,
    ): static {
        if ($templates instanceof RenderCollection) {
            $collection = $templates;
        } elseif (isset($this->renders[$namespace])) {
            $collection = $this->renders[$namespace];
            $collection->merge($templates);
        } else {
            $collection = new RenderCollection($templates);
        }

        $collection->setRoot($collection);

        $collection->setLazyCompiler(
            fn(mixed $value, string|int $key, RenderCollection $self)
                => $this->compileLazy($namespace, $value, $self)
        );

        $this->renders[$namespace] = $collection;

        if ($default || $this->defaultNamespace === null) {
            $this->defaultNamespace = $namespace;
        }

        return $this;
    }

    /**
     * Backward-compatible alias for register().
     */
    public function addCustom(string $namespace, array $templates): static
    {
        return $this->register($namespace, $templates);
    }

    public function setDefaultNamespace(string $namespace): static
    {
        if (!isset($this->renders[$namespace])) {
            throw new TemplateNotFoundException(
                "Cannot set default namespace '{$namespace}' because it is not registered."
            );
        }

        $this->defaultNamespace = $namespace;

        return $this;
    }

    public function getDefaultNamespace(): ?string
    {
        return $this->defaultNamespace;
    }

    private function getDefaultNamespaceOrFail(): string
    {
        return $this->defaultNamespace
            ?? throw new TemplateNotFoundException(
                'No default template collection is registered.'
            );
    }

    /**
     * Compile lazy template values on first access.
     */
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

        if ($value instanceof Closure) {
            return $value;
        }

        if (is_object($value) && is_callable($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return $this->asRender($value, $namespace, $self->getRoot(), $self);
    }

    /**
     * Explicitly include a template file.
     */
    public function require(string $path): mixed
    {
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            throw new \RuntimeException("Template include not found: {$path}");
        }

        return (function () use ($real) {
            return require $real;
        })->call($this);
    }

    /**
     * Create a lazy template-file reference.
     */
    public function lazyRequire(string $path): LazyTemplateFile
    {
        return new LazyTemplateFile($path);
    }

    /**
     * Render HTML attributes.
     */
    public function attributes(array $attribs): string
    {
        if ($attribs === []) {
            return '';
        }

        $render = $this->attributeRender;
        $out = [];

        foreach ($attribs as $name => $value) {
            if ($value instanceof Closure) {
                $value = $value();
            }

            $rendered = $render($name, $value);

            if ($rendered !== '') {
                $out[] = $rendered;
            }
        }

        return implode(' ', $out);
    }

    /**
     * Convert a template value into a renderer.
     */
    protected function asRender(
        mixed $value,
        string $namespace,
        RenderCollection $root,
        ?RenderCollection $scope = null
    ): Closure {
        $scope ??= $root;

        $invokeArgs = [$root, $scope, $this, $namespace];

        if (is_string($value)) {
            return function (array $args = []) use ($invokeArgs, $value): string {
                $resolver = null;

                if ($this->customParamCallbacks !== []) {
                    $resolver = function (array $args): array {
                        foreach ($args as $tag => &$arg) {
                            if (isset($this->customParamCallbacks[$tag])) {
                                $arg = $this->customParamCallbacks[$tag]($arg);
                            }
                        }

                        unset($arg);

                        foreach ($this->customParamCallbacks as $k => $cb) {
                            if (!array_key_exists($k, $args)) {
                                $args[$k] = $cb(null);
                            }
                        }

                        return $args;
                    };
                }

                return self::vnsprintf($invokeArgs, $value, $args, $resolver);
            };
        }

        if (is_object($value) && is_callable($value)) {
            return $value;
        }

        return static fn() => $value;
    }

    /**
     * Replace named placeholders.
     */
    public static function vnsprintf(
        array $invokeArgs,
        string $template,
        array $args,
        ?Closure $resolve = null,
        ?Closure $stringify = null,
        array $options = []
    ): string {
        $hasClosure = false;

        foreach ($args as $k => $v) {
            if ($v instanceof Closure) {
                $args[$k] = $v(...$invokeArgs);
                $hasClosure = true;
            }
        }

        if ($resolve !== null) {
            $args = $resolve($args);
        }

        $stringify ??= static fn($v, $key): string => self::defaultStringify($v, $key, $options);

        $allSimple = !$hasClosure;

        if ($allSimple) {
            foreach ($args as $v) {
                if (
                    !is_string($v)
                    && !is_int($v)
                    && !is_float($v)
                    && !is_bool($v)
                    && $v !== null
                ) {
                    $allSimple = false;
                    break;
                }
            }
        }

        if ($allSimple) {
            foreach ($args as $k => $v) {
                $args[$k] = match (true) {
                    is_string($v) => $v,
                    is_int($v), is_float($v) => (string) $v,
                    is_bool($v) => $v ? '1' : '0',
                    $v === null => '',
                };
            }

            return strtr($template, $args);
        }

        foreach ($args as $k => $v) {
            $args[$k] = $stringify($v, $k);
        }

        return strtr($template, $args);
    }

    private static function defaultStringify(mixed $v, string|int $key, array $options): string
    {
        if (is_string($v)) {
            return $v;
        }

        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        if ($v === null) {
            return '';
        }

        if ($v instanceof \DateTimeInterface) {
            return $v->format($options['date_format'] ?? \DATE_ATOM);
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
     * Normalize bracket notation for IDs.
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