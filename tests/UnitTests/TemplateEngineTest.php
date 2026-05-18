<?php

declare(strict_types=1);

namespace DalPraS\UnitTests;

use DalPraS\SmartTemplate\Collection\RenderCollection;
use DalPraS\SmartTemplate\Exception\TemplateNotFoundException;
use DalPraS\SmartTemplate\TemplateEngine;
use DalPraS\UnitTests\Enums\Status;
use PHPUnit\Framework\TestCase;

final class TemplateEngineTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__ . '/templates';

    public function testRegisterAndRenderNamedCollection(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'card' => '<section><h2>{title}</h2><div>{body}</div></section>',
        ]);

        $result = $engine->render('ui', static function (RenderCollection $ui): string {
            return $ui['card']([
                '{title}' => 'Hello',
                '{body}' => 'Welcome',
            ]);
        });

        self::assertSame(
            '<section><h2>Hello</h2><div>Welcome</div></section>',
            $result
        );
    }

    public function testFirstRegisteredCollectionBecomesDefault(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'title' => '<h1>{text}</h1>',
        ]);

        $result = $engine->collection()['title']([
            '{text}' => 'Default',
        ]);

        self::assertSame('<h1>Default</h1>', $result);
        self::assertSame('ui', $engine->getDefaultNamespace());
    }

    public function testExplicitDefaultNamespaceCanBeChanged(): void
    {
        $engine = new TemplateEngine();

        $engine->register('html', [
            'tag' => '<span>{text}</span>',
        ]);

        $engine->register('ui', [
            'tag' => '<strong>{text}</strong>',
        ], default: true);

        $result = $engine->defaultCollection()['tag']([
            '{text}' => 'Active',
        ]);

        self::assertSame('<strong>Active</strong>', $result);
        self::assertSame('ui', $engine->getDefaultNamespace());
    }

    public function testSetDefaultNamespace(): void
    {
        $engine = new TemplateEngine();

        $engine->register('html', [
            'tag' => '<span>{text}</span>',
        ]);

        $engine->register('ui', [
            'tag' => '<strong>{text}</strong>',
        ]);

        $engine->setDefaultNamespace('ui');

        $result = $engine->collection()['tag']([
            '{text}' => 'Changed',
        ]);

        self::assertSame('<strong>Changed</strong>', $result);
    }

    public function testRegisterMergesIntoExistingNamespace(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'button' => '<button>{label}</button>',
        ]);

        $engine->register('ui', [
            'card' => '<section>{body}</section>',
        ]);

        $ui = $engine->collection('ui');

        self::assertSame('<button>Save</button>', $ui['button']([
            '{label}' => 'Save',
        ]));

        self::assertSame('<section>Hello</section>', $ui['card']([
            '{body}' => 'Hello',
        ]));
    }

    public function testRegisterOverridesExistingTemplateKey(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'button' => '<button>{label}</button>',
        ]);

        $engine->register('ui', [
            'button' => '<button class="btn">{label}</button>',
        ]);

        $result = $engine->collection('ui')['button']([
            '{label}' => 'Save',
        ]);

        self::assertSame('<button class="btn">Save</button>', $result);
    }

    public function testRenderDefault(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'message' => '<p>{text}</p>',
        ]);

        $result = $engine->renderDefault(static function (RenderCollection $ui): string {
            return $ui['message']([
                '{text}' => 'Hello',
            ]);
        });

        self::assertSame('<p>Hello</p>', $result);
    }

    public function testHasCollection(): void
    {
        $engine = new TemplateEngine();

        self::assertFalse($engine->hasCollection('ui'));

        $engine->register('ui', [
            'message' => '<p>{text}</p>',
        ]);

        self::assertTrue($engine->hasCollection('ui'));
    }

    public function testCollectionThrowsWhenNamespaceIsMissing(): void
    {
        $engine = new TemplateEngine();

        $this->expectException(TemplateNotFoundException::class);

        $engine->collection('missing');
    }

    public function testCollectionThrowsWhenDefaultNamespaceIsMissing(): void
    {
        $engine = new TemplateEngine();

        $this->expectException(TemplateNotFoundException::class);

        $engine->collection();
    }

    public function testSetDefaultNamespaceThrowsWhenNamespaceIsMissing(): void
    {
        $engine = new TemplateEngine();

        $this->expectException(TemplateNotFoundException::class);

        $engine->setDefaultNamespace('missing');
    }

    public function testRequireLoadsExplicitTemplateFile(): void
    {
        $engine = new TemplateEngine();

        $templates = $engine->require(self::TEMPLATE_DIR . '/default.php');

        self::assertIsArray($templates);

        $engine->register('ui', $templates);

        $result = $engine->collection('ui')['card']([
            '{title}' => 'Loaded',
            '{body}' => 'From file',
        ]);

        self::assertSame(
            '<section class="card"><h2>Loaded</h2><div>From file</div></section>',
            $result
        );
    }

    public function testRequireThrowsWhenFileDoesNotExist(): void
    {
        $engine = new TemplateEngine();

        $this->expectException(\RuntimeException::class);

        $engine->require(self::TEMPLATE_DIR . '/missing.php');
    }

    public function testLazyRequireLoadsNestedTemplateFileOnAccess(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', $engine->require(self::TEMPLATE_DIR . '/default.php'));

        $result = $engine->collection('ui')['partials']['title']([
            '{text}' => 'Lazy title',
        ]);

        self::assertSame('<h1>Lazy title</h1>', $result);
    }

    public function testClosureTemplateIsReturnedAsCallable(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'message' => static fn(string $text): string => "Message: {$text}",
        ]);

        $result = $engine->collection('ui')['message']('Hello');

        self::assertSame('Message: Hello', $result);
    }

    public function testClosurePlaceholderReceivesRenderContext(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'layout' => '<main>{content}</main>',
            'paragraph' => '<p>{text}</p>',
        ]);

        $result = $engine->collection('ui')['layout']([
            '{content}' => static function (
                RenderCollection $root,
                RenderCollection $scope,
                TemplateEngine $engine,
                string $namespace
            ): string {
                self::assertSame('ui', $namespace);
                self::assertSame($root, $scope);
                self::assertInstanceOf(TemplateEngine::class, $engine);

                return $root['paragraph']([
                    '{text}' => 'Generated',
                ]);
            },
        ]);

        self::assertSame('<main><p>Generated</p></main>', $result);
    }

    public function testCustomParamCallbackTransformsProvidedValue(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'button' => '<button {attributes}>{label}</button>',
        ]);

        $engine->addCustomParamCallback('{attributes}', static function ($value) use ($engine): string {
            return $value === null ? '' : $engine->attributes($value);
        });

        $result = $engine->collection('ui')['button']([
            '{attributes}' => [
                'type' => 'button',
                'class' => 'btn',
            ],
            '{label}' => 'Save',
        ]);

        self::assertSame(
            '<button type="button" class="btn">Save</button>',
            $result
        );
    }

    public function testCustomParamCallbackInjectsMissingValue(): void
    {
        $engine = new TemplateEngine();

        $engine->register('ui', [
            'button' => '<button {attributes}>{label}</button>',
        ]);

        $engine->addCustomParamCallback('{attributes}', static function ($value) use ($engine): string {
            return $value === null ? '' : $engine->attributes($value);
        });

        $result = $engine->collection('ui')['button']([
            '{label}' => 'Save',
        ]);

        self::assertSame('<button >Save</button>', $result);
    }

    public function testRemoveCustomParamCallback(): void
    {
        $engine = new TemplateEngine();

        $engine->addCustomParamCallback('{value}', static fn($value): string => strtoupper((string) $value));

        self::assertTrue($engine->removeCustomParamCallback('{value}'));
        self::assertFalse($engine->removeCustomParamCallback('{value}'));
        self::assertSame([], $engine->getCustomParamCallbacks());
    }

    public function testAttributesRenderValues(): void
    {
        $engine = new TemplateEngine();

        $result = $engine->attributes([
            'id' => 'user[email]',
            'name' => 'user[email]',
            'title' => 'Email address',
            'class' => 'form-control',
        ]);

        self::assertSame(
            'id="user-email" name="user[email]" title="Email address" class="form-control"',
            $result
        );
    }

    public function testAttributesRenderClosureValues(): void
    {
        $engine = new TemplateEngine();

        $result = $engine->attributes([
            'class' => static fn(): string => 'btn btn-primary',
        ]);

        self::assertSame('class="btn btn-primary"', $result);
    }

    public function testCustomAttributeComposer(): void
    {
        $engine = new TemplateEngine();

        $engine->setAttributeComposer(
            static fn($name, $value): string => sprintf('%s=%s', $name, $value)
        );

        self::assertSame('class=btn', $engine->attributes([
            'class' => 'btn',
        ]));
    }

    public function testCustomAttributeRenderCanSkipAttributes(): void
    {
        $engine = new TemplateEngine();

        $engine->setAttributeRender(static function ($name, $value): string {
            if ($value === null || $value === '') {
                return '';
            }

            return $name . '="' . $value . '"';
        });

        $result = $engine->attributes([
            'id' => '',
            'class' => 'btn',
        ]);

        self::assertSame('class="btn"', $result);
    }

    public function testNormalizeId(): void
    {
        $engine = new TemplateEngine();

        self::assertSame('user-email', $engine->normalizeId('user[email]'));
        self::assertSame('items-0-name', $engine->normalizeId('items[0][name]'));
    }

    public function testStringifiesCommonValues(): void
    {
        $engine = new TemplateEngine();

        $engine->register('demo', [
            'line' => '{value}',
        ]);

        $demo = $engine->collection('demo');

        self::assertSame('123', $demo['line'](['{value}' => 123]));
        self::assertSame('1', $demo['line'](['{value}' => true]));
        self::assertSame('', $demo['line'](['{value}' => null]));
        self::assertSame('on', $demo['line'](['{value}' => Status::ON]));
        self::assertSame('{"a":1}', $demo['line'](['{value}' => ['a' => 1]]));
    }

    public function testVnsprintfSupportsResolver(): void
    {
        $result = TemplateEngine::vnsprintf(
            [],
            'Hello {name}',
            [],
            static fn(array $args): array => $args + ['{name}' => 'World']
        );

        self::assertSame('Hello World', $result);
    }
}
