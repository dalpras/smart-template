<?php
return [
    'table' => <<<html
        %toEscape%
        <table class="table table-sm">
            {rows}
        </table>
        html,
        
    'tr' => <<<html
        <tr>{cols}</tr>
        html,

    'td' => <<<html
        <td>{text}</td>
        html,
];