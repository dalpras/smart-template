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

    'input' => <<<HTML
        <input type="{type}" value="{value}" {attributes} />
        HTML,        

    'a' => <<<HTML
        <a aria-label="{label}" href="{url}" {attributes}>{content}</a>
        HTML,

    'img' => <<<HTML
        <img src="{src}" alt="{alt}" {attributes} />
        HTML,
];