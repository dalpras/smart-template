<?php

return [
    'table' => function($message) {
        /** @var \DalPraS\SmartTemplate\TemplateEngine $this */
        $html = <<<html
            {$message}
            <table class="table table-sm">
                {rows}
            </table>
            html;
        $callback = self::closure($html, $this->invokeArgs(__FILE__));
        return $callback(['{rows}' => 'hello']);
    },
        
    'row' => <<<html
        <tr><td>{text}</td></tr>
        html,
];