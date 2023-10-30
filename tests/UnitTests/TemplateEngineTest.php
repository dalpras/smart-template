<?php declare(strict_types=1);

namespace DalPraS\UnitTests;

use DalPraS\SmartTemplate\Exception\TemplateNotFoundException;
use DalPraS\SmartTemplate\TemplateEngine;
use DalPraS\UnitTests\Enums\Status;
use PHPUnit\Framework\TestCase;
use TypeError;

final class TemplateEngineTest extends TestCase
{
    const TEMPLATE_DIR = __DIR__ . '/templates';

    public function testRenderFolderTemplate()
    {
        $templateEngine = new TemplateEngine(self::TEMPLATE_DIR);

        $result = $templateEngine->setUglify(true)->render('table1.php', function ($render) {
            return $render['table']([
                '{table-class}' => '',
                '{thead-class}' => '',
                '{cols}' => $render['th']['base'](['{text}' => 'column 1']),
                '{rows}' => $render['tr']['base']([
                    '{cols}' => $render['td']['base'](['{text}' => 'datum'])
                ]),
            ]);
        });

        $expectedOutput = '<div class="table-responsive"> <table class="table table-sm "> <thead class=""> <tr><th>column 1</th></tr> </thead> <tbody> <tr><td>datum</td></tr> </tbody> </table> </div>';

        $this->assertEquals($expectedOutput, $result);
    }

    public function testRenderDeeperFolderTemplate()
    {
        $templateEngine = new TemplateEngine(self::TEMPLATE_DIR);

        $result = $templateEngine->setUglify(true)->render('deeper/table.php', function ($render) {
            return $render['table']([
                '{rows}' => $render['tr'](['{cols}' => $render['td'](['{text}' => 'datum'])]),
            ]);
        });

        $expectedOutput = '%toEscape% <table class="table table-sm"> <tr><td>datum</td></tr> </table>';

        $this->assertEquals($expectedOutput, $result);
    }

    public function testRenderDeeperCallbackTemplate()
    {
        $templateEngine = new TemplateEngine(self::TEMPLATE_DIR);

        $result = $templateEngine->setUglify(true)->render('callback/table.php', function ($render) {
            return $render['deeper']['table']('message for better living');
        });

        $expectedOutput = 'This is a message for better living';

        $this->assertEquals($expectedOutput, $result);
    }

    public function testRenderCustomTemplate()
    {
        $templateEngine = new TemplateEngine(self::TEMPLATE_DIR);

        $templateEngine->addCustom('custom/template/namespace', [
            'custom_template' => 'Custom template content: {value}',
        ]);

        $result = $templateEngine->render('custom/template/namespace', fn($render) => $render['custom_template'](['{value}' => 'my content']));

        $expectedOutput = 'Custom template content: my content';

        $this->assertEquals($expectedOutput, $result);
    }

    public function testRenderCustomCallback()
    {
        $templateEngine = new TemplateEngine(self::TEMPLATE_DIR);
        
        $result = $templateEngine->render('callback/table.php', fn($render) => $render['table']('hi', ['first col', 'second col']));

        $expectedOutput = <<<html
        hi
        <table class="table table-sm">
            hello
        </table>
        html;

        $this->assertEquals($expectedOutput, $result);        
    }

    public function testTemplateNotFoundException() 
    {
        $templateEngine = new TemplateEngine(self::TEMPLATE_DIR);
        $this->expectException(TemplateNotFoundException::class);
        $result = $templateEngine->render('callback/table-not-present.php', fn($render) => $render['table']('hi', ['first col', 'second col']));
    }


    /**
     * In future perhaps array will support enums as keys.
     */
    public function testEnumAsTemplateKey()
    {
        $templateEngine = new TemplateEngine(self::TEMPLATE_DIR);
        $this->expectException(TypeError::class);
        $result = $templateEngine->render('enumkeys.php', fn($render) => $render[Status::ON](['{message}' => 'give a try!']));
    }

}