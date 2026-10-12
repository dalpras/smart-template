<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate;

use Closure;
use InvalidArgumentException;

/**
 * HTML attribute serialization for Smart Template call-site |join modifiers.
 *
 * Kept outside TemplateEngine so the engine only handles parameter replacement.
 * An attribute is processed once; only the completed fragments are imploded.
 */
final class HtmlAttributes
{
    private const DEFAULT_ESCAPED_NAMES = ['id', 'title', 'name', 'alt', 'aria-label'];
    private const DEFAULT_OMIT_EMPTY_NAMES = ['id', 'name'];

    private readonly Closure $escape;

    /** @var array<string, true> */
    private readonly array $escapedNames;

    /** @var array<string, true> */
    private readonly array $omitEmptyNames;

    /**
     * @param null|Closure(string): string $escape Escaper for configured attribute values.
     * @param list<string> $escapedNames Only these attribute names use $escape.
     * @param list<string> $omitEmptyNames These names are omitted when null or empty.
     */
    public function __construct(
        ?Closure $escape = null,
        array $escapedNames = self::DEFAULT_ESCAPED_NAMES,
        array $omitEmptyNames = self::DEFAULT_OMIT_EMPTY_NAMES,
    ) {
        $this->escape = $escape ?? static fn(string $value): string =>
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->escapedNames = array_fill_keys($escapedNames, true);
        $this->omitEmptyNames = array_fill_keys($omitEmptyNames, true);
    }

    /**
     * Register one |join modifier for HTML attributes and ordinary value lists.
     * Also provide an empty default when a template omits {attributes}.
     *
     * Re-register to change the attribute escaping policy on an existing engine.
     */
    public function register(TemplateEngine $engine): TemplateEngine
    {
        $engine->addCustomParamModifier('join', function (?array $values, string $placeholder): string {
            if ($placeholder === '{attributes}') {
                return $this->join($values);
            }

            return implode(' ', array_filter(
                $values ?? [],
                static fn(mixed $value): bool => $value !== null && $value !== ''
            ));
        });

        // This callback no longer renders arrays: callers must use {attributes}|join.
        // Keep the default for optional attributes in built-in HTML templates.
        $engine->addCustomParamCallback('{attributes}', static function (mixed $value): string {
            if ($value === null) {
                return '';
            }

            if (is_string($value) || $value instanceof \Stringable) {
                return (string) $value;
            }

            throw new InvalidArgumentException(
                'Attribute arrays must be passed with the {attributes}|join modifier.'
            );
        });

        return $engine;
    }

    /**
     * Serialize attributes in one pass. A true boolean adds a bare attribute;
     * false omits it. Lazy values (closures) are evaluated once.
     */
    public function join(?array $attributes): string
    {
        if ($attributes === null || $attributes === []) {
            return '';
        }

        $fragments = [];
        $escape = $this->escape;

        foreach ($attributes as $name => $value) {
            if ($value instanceof Closure) {
                $value = $value();
            }

            $name = (string) $name;

            if (isset($this->omitEmptyNames[$name]) && ($value === null || $value === '')) {
                continue;
            }

            if (is_bool($value)) {
                if ($value) {
                    $fragments[] = $name;
                }
                continue;
            }

            if ($name === 'id') {
                $value = self::normalizeId((string) $value);
            }

            if (isset($this->escapedNames[$name])) {
                $value = $escape((string) $value);
            }

            // All values are quoted: escape a quote even for names not in
            // $escapedNames to prevent breaking out of the attribute value.
            $fragments[] = $name . '="' . str_replace('"', '&quot;', (string) $value) . '"';
        }

        return implode(' ', $fragments);
    }

    /** Normalize form bracket notation to a HTML-friendly id. */
    public static function normalizeId(string $value): string
    {
        return trim(strtr($value, ['[' => '-', ']' => '']), '-');
    }
}
