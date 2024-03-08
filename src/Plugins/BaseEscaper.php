<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Plugins;

class BaseEscaper implements EscaperInterface
{
    public function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, "UTF-8");
    }

    public function escapeHtmlAttr(string $text): string
    {
        return $this->escapeHtml($text);
    }

    public function escapeJs(string $text): string
    {
        return $this->escapeHtml($text);
    }

    public function escapeCss(string $text): string
    {
        return $this->escapeHtml($text);
    }

    public function escapeUrl(string $text): string
    {
        return rawurlencode($text);
    }    
}
