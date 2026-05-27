<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use DalPraS\SmartTemplate\Collection\RenderCollection;
use DalPraS\SmartTemplate\Exception\TemplateNotFoundException;
use DalPraS\SmartTemplate\Plugins\HelpersInterface;

/**
 * Small PHP-native template engine based on collections, lazy compilation and named placeholders.
 */
class TemplateEngine
{
    /**
     * Registered template collections by namespace.
     *
     * @var array<string, RenderCollection>
     */
    private array $renders = [];

    /**
     * Default namespace used when no namespace is explicitly passed.
     */
    private ?string $defaultNamespace = null;

    /**
     * Exact placeholder callbacks, for example {attributes} or {class}.
     *
     * @var array<string, Closure>
     */
    private array $customParamCallbacks = [];

    /**
     * Call-site token modifiers, for example TOKEN|upperCase.
     *
     * @var array<string, Closure>
     */
    private array $customParamModifiers = [];

    /**
     * Separator used in call-site modifiers, e.g. {content}|upperCase.
     */
    private string $customParamModifierSeparator = '|';

    /**
     * Builds a single HTML attribute string.
     */
    private Closure $attributeComposer;

    /**
     * Normalizes and renders one attribute.
     */
    protected Closure $attributeRender;

    /**
     * Optional application helpers.
     */
    protected ?HelpersInterface $helpers = null;

    /**
     * Configure default attribute rendering.
     */
    public function __construct()
    {
        $this->attributeComposer = static fn($name, $value): string =>
            $name . '="' . str_replace('"', '&quot;', (string) $value) . '"';

        $this->attributeRender = function ($name, $value): string {
            $value = match ($name) {
                'id' => $this->helpers?->escaper()?->escapeHtml(
                    $this->normalizeId((string) $value)
                ) ?? $this->normalizeId((string) $value),

                'title',
                'name',
                'alt',
                'aria-label' => $this->helpers?->escaper()?->escapeHtml(
                    (string) $value
                ) ?? (string) $value,

                default => $value,
            };

            return ($this->attributeComposer)($name, $value);
        };
    }

    /**
     * Render a named template collection.
     */
    public function render(string $namespace, Closure $callback): mixed
    {
        return $callback($this->collection($namespace), $namespace) ?? '';
    }

    /**
     * Render the default template collection.
     */
    public function renderDefault(Closure $callback): mixed
    {
        $namespace = $this->getDefaultNamespaceOrFail();

        return $callback($this->collection($namespace), $namespace) ?? '';
    }

    /**
     * Return a template collection by namespace, or the default collection when omitted.
     */
    public function collection(?string $namespace = null): RenderCollection
    {
        $namespace ??= $this->getDefaultNamespaceOrFail();

        return $this->renders[$namespace] ?? throw new TemplateNotFoundException(
            "Template collection '{$namespace}' is not registered."
        );
    }

    /**
     * Return the default template collection.
     */
    public function defaultCollection(): RenderCollection
    {
        return $this->collection();
    }

    /**
     * Check whether a namespace is registered.
     */
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
            fn(mixed $value, string|int $key, RenderCollection $self) =>
            $this->compileLazy($namespace, $value, $self)
        );

        $this->renders[$namespace] = $collection;

        if ($default || $this->defaultNamespace === null) {
            $this->defaultNamespace = $namespace;
        }

        return $this;
    }

    /**
     * Set the default collection namespace.
     */
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

    /**
     * Return the current default namespace, if any.
     */
    public function getDefaultNamespace(): ?string
    {
        return $this->defaultNamespace;
    }

    /**
     * Return the current default namespace or fail clearly.
     */
    private function getDefaultNamespaceOrFail(): string
    {
        return $this->defaultNamespace ?? throw new TemplateNotFoundException(
            'No default template collection is registered.'
        );
    }

    /**
     * Compile lazy template values only when they are first accessed.
     */
    private function compileLazy(string $namespace, mixed $value, RenderCollection $self): mixed
    {
        if ($value instanceof LazyTemplateFile) {
            $loaded = $this->require($value->path);

            if (is_array($loaded)) {
                $nested = new RenderCollection($loaded);
                $nested->setRoot($self->getRoot());

                $nested->setLazyCompiler(
                    fn(mixed $nestedValue, string|int $nestedKey, RenderCollection $nestedSelf) =>
                    $this->compileLazy($namespace, $nestedValue, $nestedSelf)
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
     * Explicitly include a PHP template file.
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
     * Create a lazy reference to a PHP template file.
     */
    public function lazyRequire(string $path): LazyTemplateFile
    {
        return new LazyTemplateFile($path);
    }

    /**
     * Render an associative array as HTML attributes.
     */
    public function attributes(array $attributes): string
    {
        if ($attributes === []) {
            return '';
        }

        $render = $this->attributeRender;
        $out = [];

        foreach ($attributes as $name => $value) {
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
     * Convert a template value into a render callable.
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

                if ($this->customParamCallbacks !== [] || $this->customParamModifiers !== []) {
                    $resolver = function (array $args): array {
                        if ($this->customParamModifiers !== []) {
                            $args = $this->resolveCustomParamModifiers($args);
                        }

                        if ($this->customParamCallbacks !== []) {
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
     * Replace named placeholders in a template string.
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

        $stringify ??= static fn($v, $key): string =>
            self::defaultStringify($v, $key, $options);

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
                    is_int($v),
                    is_float($v) => (string) $v,
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

    /**
     * Convert non-scalar placeholder values to strings.
     */
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

    /**
     * Encode a value as JSON with a safe fallback.
     */
    private static function json(mixed $v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return var_export($v, true);
        }

        return $json;
    }

    /**
     * Replace the attribute render callback.
     */
    public function setAttributeRender(Closure $attributeRender): static
    {
        $this->attributeRender = $attributeRender;

        return $this;
    }

    /**
     * Replace the low-level attribute composer callback.
     */
    public function setAttributeComposer(Closure $attributeComposer): static
    {
        $this->attributeComposer = $attributeComposer;

        return $this;
    }

    /**
     * Return the low-level attribute composer callback.
     */
    public function getAttributeComposer(): Closure
    {
        return $this->attributeComposer;
    }

    /**
     * Normalize bracket notation into a safe HTML id.
     */
    public function normalizeId(string $value): string
    {
        return trim(strtr($value, ['[' => '-', ']' => '']), '-');
    }

    /**
     * Register an exact placeholder callback.
     */
    public function addCustomParamCallback(string $name, Closure $callback): static
    {
        $this->customParamCallbacks[$name] = $callback;

        return $this;
    }

    /**
     * Remove an exact placeholder callback.
     */
    public function removeCustomParamCallback(string $name): bool
    {
        if (!isset($this->customParamCallbacks[$name])) {
            return false;
        }

        unset($this->customParamCallbacks[$name]);

        return true;
    }

    /**
     * Return all exact placeholder callbacks.
     *
     * @return array<string, Closure>
     */
    public function getCustomParamCallbacks(): array
    {
        return $this->customParamCallbacks;
    }

    /**
     * Register a call-site placeholder modifier.
     */
    public function addCustomParamModifier(string $name, Closure $callback): static
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Custom parameter modifier name cannot be empty.');
        }

        if (str_contains($name, $this->customParamModifierSeparator)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Custom parameter modifier name cannot contain "%s".',
                    $this->customParamModifierSeparator
                )
            );
        }

        $this->customParamModifiers[$name] = $callback;

        return $this;
    }

    /**
     * Remove a call-site placeholder modifier.
     */
    public function removeCustomParamModifier(string $name): bool
    {
        if (!isset($this->customParamModifiers[$name])) {
            return false;
        }

        unset($this->customParamModifiers[$name]);

        return true;
    }

    /**
     * Return all call-site placeholder modifiers.
     *
     * @return array<string, Closure>
     */
    public function getCustomParamModifiers(): array
    {
        return $this->customParamModifiers;
    }

    /**
     * Resolve keys like TOKEN|modifier into the base TOKEN.
     */
    private function resolveCustomParamModifiers(array $args): array
    {
        $separator = $this->customParamModifierSeparator;

        foreach ($args as $rawKey => $value) {
            if (!is_string($rawKey) || !str_contains($rawKey, $separator)) {
                continue;
            }

            [$placeholder, $modifierChain] = explode($separator, $rawKey, 2);

            $placeholder = trim($placeholder);
            $modifierChain = trim($modifierChain);

            if (!$this->isValidCustomParamToken($placeholder) || $modifierChain === '') {
                continue;
            }

            $modifiers = array_values(
                array_filter(
                    array_map('trim', explode($separator, $modifierChain)),
                    static fn(string $modifier): bool => $modifier !== ''
                )
            );

            if ($modifiers === []) {
                continue;
            }

            foreach ($modifiers as $modifier) {
                if (!isset($this->customParamModifiers[$modifier])) {
                    continue;
                }

                $value = $this->customParamModifiers[$modifier](
                    $value,
                    $placeholder,
                    $modifier,
                    $rawKey,
                    $args,
                    $this
                );
            }

            $args[$placeholder] = $value;

            unset($args[$rawKey]);
        }

        return $args;
    }

    /**
     * Set the separator used for call-site modifiers.
     */
    public function setCustomParamModifierSeparator(string $separator): static
    {
        if ($separator === '') {
            throw new \InvalidArgumentException(
                'Custom parameter modifier separator cannot be empty.'
            );
        }

        foreach (array_keys($this->customParamModifiers) as $modifier) {
            if (str_contains($modifier, $separator)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Custom parameter modifier separator "%s" conflicts with registered modifier "%s".',
                        $separator,
                        $modifier
                    )
                );
            }
        }

        $this->customParamModifierSeparator = $separator;

        return $this;
    }

    /**
     * Return the separator used for call-site modifiers.
     */
    public function getCustomParamModifierSeparator(): string
    {
        return $this->customParamModifierSeparator;
    }

    /**
     * Validate the base token used in template replacement.
     */
    private function isValidCustomParamToken(string $token): bool
    {
        return $token !== ''
            && !str_contains($token, $this->customParamModifierSeparator);
    }

    /**
     * Return the current helpers instance, if any.
     */
    public function getHelpers(): ?HelpersInterface
    {
        return $this->helpers;
    }

    /**
     * Set the helpers instance used by the engine.
     */
    public function setHelpers(?HelpersInterface $helpers): static
    {
        $this->helpers = $helpers;

        return $this;
    }
}