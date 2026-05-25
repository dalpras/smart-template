<?php

return [
    'h1' => <<<HTML
        <h1 {attributes}>{content}</h1>
        HTML,

    'h2' => <<<HTML
        <h2 {attributes}>{content}</h2>
        HTML,

    'h3' => <<<HTML
        <h3 {attributes}>{content}</h3>
        HTML,

    'h4' => <<<HTML
        <h4 {attributes}>{content}</h4>
        HTML,

    'span' => <<<HTML
        <span {attributes}>{content}</span>
        HTML,

    'section' => <<<HTML
        <section {attributes}>{content}</section>
        HTML,

    'strong' => <<<HTML
        <strong {attributes}>{content}</strong>
        HTML,

    'sup' => <<<HTML
        <sup>{content}</sup>
        HTML,

    'p' => <<<HTML
        <p {attributes}>{content}</p>
        HTML,

    'dl' => <<<HTML
        <dl {attributes}>{content}</dl>
        HTML,

    'dt' => <<<HTML
        <dt {attributes}>{content}</dt>
        HTML,

    'dd' => <<<HTML
        <dd {attributes}>{content}</dd>
        HTML,        

    'picture' => <<<HTML
        <picture>{content}</picture>
        HTML,

    'source' => <<<HTML
        <source srcset="{src}" media="{media}">
        HTML,       

    'ul' => <<<HTML
        <ul {attributes}>{content}</ul>
        HTML,

    'ol' => <<<HTML
        <ul {attributes}>{content}</ul>
        HTML,

    'li' => <<<HTML
        <li {attributes}>{content}</li>
        HTML,

    'div' => <<<HTML
        <div {attributes}>{content}</div>
        HTML,

    'form' => <<<HTML
        <form action="{action}" method="{method}" {attributes}>{content}</form>
        HTML,

    'fieldset' => <<<HTML
        <fieldset {attributes}>{content}</fieldset>
        HTML,

    'legend' => <<<HTML
        <legend {attributes}>{content}</legend>
        HTML,

    'label' => <<<HTML
        <label for="{for}" {attributes}>{content}</label>
        HTML,

    'input' => <<<HTML
        <input type="{type}" value="{value}" {attributes} />
        HTML,

    'textarea' => <<<HTML
        <textarea name="{name}" {attributes}>{content}</textarea>
        HTML,

    'select' => <<<HTML
        <select name="{name}" {attributes}>{content}</select>
        HTML,

    'option' => <<<HTML
        <option value="{value}" {attributes}>{content}</option>
        HTML,

    'optgroup' => <<<HTML
        <optgroup label="{label}" {attributes}>{content}</optgroup>
        HTML,

    'button' => <<<HTML
        <button type="{type}" {attributes}>{content}</button>
        HTML,

    'a' => <<<HTML
        <a aria-label="{label}" href="{url}" {attributes}>{content}</a>
        HTML,

    'img' => <<<HTML
        <img src="{src}" alt="{alt}" {attributes} />
        HTML,

    'table' => <<<HTML
        <div class="table-responsive">
            <table {attributes}>
                {content}
            </table>
        </div>
        HTML,

    'colgroup' => <<<HTML
        <colgroup>
            {content}
        </colgroup>
        HTML,

    'col' => <<<HTML
        <col style="width: {percent}%" />
        HTML,

    'thead' => <<<HTML
        <thead {attributes}>
            {content}
        </thead>
        HTML,

    'tbody' => <<<HTML
        <tbody {attributes}>
            {content}
        </tbody>
        HTML,

    'tr' => <<<HTML
        <tr {attributes}>{content}</tr>
        HTML,

    'th' =><<<HTML
        <th class="{class}" {attributes}>{content}</th>
        HTML,

    'td' => <<<HTML
        <td class="{class}" {attributes}>{content}</td>
        HTML,

];