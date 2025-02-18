<?php
return [
    'table' => <<<html
        <div class="table-responsive">
            <table class="table table-sm {table-class}">
                {content}
                <thead class="{thead-class}">
                    <tr>{cols}</tr>
                </thead>
                <tbody>
                    {rows}
                </tbody>
            </table>
        </div>
    html,

    'tr' => [
        'base' => <<<html
            <tr>{cols}</tr>
            html,

        'class' => <<<html
            <tr class="{class}">{cols}</tr>
            html,
    ],

    'th' => [
        'base' => <<<html
            <th>{text}</th>
            html,
    ],

    'td' => [
        'base' => <<<html
            <td>{text}</td>
            html,

        'class' => <<<html
            <td class="{class}">{text}</td>
            html,
    ],
];