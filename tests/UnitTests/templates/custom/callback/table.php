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
        return $this->vnsprintf($this->invokeArgs('callback/table.php'), $html, ['{rows}' => 'hello']);
    },
        
    'row' => <<<html
        <tr><td>{text}</td></tr>
        html,

    'deeper' => [
        'table' => fn($message) => 'This is a ' . $message 
    ]
];