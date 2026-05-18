<?php

declare(strict_types=1);

return [
    'card' => '<section class="card"><h2>{title}</h2><div>{body}</div></section>',
    'button' => '<button type="{type}" class="{class}">{label}</button>',
    'partials' => $this->lazyRequire(__DIR__ . '/partials.php'),
];
