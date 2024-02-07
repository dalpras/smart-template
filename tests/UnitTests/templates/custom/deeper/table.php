<?php
return [
    'table' => 
        '%toEscape%' .
        '<table class="table table-sm">' .
            '{rows}' .
        '</table>'
    ,
        
    'tr' => <<<html
        <tr>{cols}</tr>
        html,

    'td' => <<<html
        <td>{text}</td>
        html,
];