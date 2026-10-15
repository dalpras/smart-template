<?php

declare(strict_types=1);

namespace DalPraS\UnitTests;

use DalPraS\SmartTemplate\HtmlAttributes;
use DalPraS\SmartTemplate\Preset\HtmlPreset;
use DalPraS\SmartTemplate\TemplateEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HtmlAttributesTest extends TestCase
{
    public function testSerializationAndIdNormalization(): void
    {
        $attributes = new HtmlAttributes();

        self::assertSame(
            'id="user-email" name="user[email]" title="A &amp; B" class="form-control"',
            $attributes->join([
                'id' => 'user[email]',
                'name' => 'user[email]',
                'title' => 'A & B',
                'class' => 'form-control',
            ])
        );
        self::assertSame('items-0-name', HtmlAttributes::normalizeId('items[0][name]'));
    }

    public function testAjaxUrlAttributeEscapesRawQuerySeparatorOnce(): void
    {
        // URL helpers must return raw query separators to the attribute renderer.
        // The browser will decode this escaped HTML attribute exactly once.
        $attributes = new HtmlAttributes(escapedNames: ['data-fz-url']);
        $html = $attributes->join([
            'data-fz-url' => '/article/admin-content/create-fragment?sid=321&type=main',
        ]);

        self::assertSame(
            'data-fz-url="/article/admin-content/create-fragment?sid=321&amp;type=main"',
            $html,
        );
        self::assertSame(1, preg_match('/data-fz-url="([^"]+)"/', $html, $matches));
        $browserUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        parse_str(parse_url($browserUrl, PHP_URL_QUERY), $params);
        self::assertSame(['sid' => '321', 'type' => 'main'], $params);
    }

    public function testBooleansNullsAndLazyAttributes(): void
    {
        $attributes = new HtmlAttributes();
        $calls = 0;

        self::assertSame(
            'title="" class="btn" required data-quote="a&quot;b"',
            $attributes->join([
                'id' => null,
                'name' => '',
                'title' => null,
                'class' => static function () use (&$calls): string { ++$calls; return 'btn'; },
                'required' => true,
                'disabled' => false,
                'data-quote' => 'a"b',
            ])
        );
        self::assertSame(1, $calls);
        self::assertSame('', $attributes->join([]));
        self::assertSame('', $attributes->join(null));
    }

    public function testEscapingNamesAndFunctionAreConfigurable(): void
    {
        $escaped = [];
        $attributes = new HtmlAttributes(
            escape: static function (string $value) use (&$escaped): string {
                $escaped[] = $value;
                return strtoupper($value);
            },
            escapedNames: ['aria-label'],
            omitEmptyNames: ['data-key'],
        );

        self::assertSame('id="a-b" aria-label="YES" title="no"', $attributes->join([
            'id' => 'a[b]',
            'aria-label' => 'yes',
            'title' => 'no',
            'data-key' => '',
        ]));
        self::assertSame(['yes'], $escaped);
    }

    public function testHtmlPresetInstallsJoinAndOptionalAttributeDefault(): void
    {
        $engine = new TemplateEngine();
        HtmlPreset::register($engine);

        $input = $engine->collection('html')['input'];
        self::assertSame(
            '<input type="text" value="" class="field" required />',
            $input([
                '{type}' => 'text',
                '{value}' => '',
                '{attributes}|join' => ['class' => 'field', 'required' => true],
            ])
        );
        self::assertSame(
            '<input type="text" value=""  />',
            $input(['{type}' => 'text', '{value}' => ''])
        );
    }

    public function testJoinWorksForOtherTokens(): void
    {
        $engine = new TemplateEngine();
        (new HtmlAttributes())->register($engine);
        $engine->register('ui', ['list' => '<p>{content}</p>']);

        self::assertSame('<p>First Second</p>', $engine->collection('ui')['list']([
            '{content}|join' => ['First', null, '', 'Second'],
        ]));
    }

    public function testRawAttributeArrayRequiresJoin(): void
    {
        $engine = new TemplateEngine();
        (new HtmlAttributes())->register($engine);
        $engine->register('ui', ['button' => '<button {attributes}>Save</button>']);

        $this->expectException(InvalidArgumentException::class);
        $engine->collection('ui')['button'](['{attributes}' => ['class' => 'btn']]);
    }
}
