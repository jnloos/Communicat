<?php
namespace App\Services;

use Parsedown;

class MarkdownParser
{
    protected Parsedown $parser;

    public function __construct(Parsedown $parser) {
        $this->parser = $parser;
    }

    public function parse($content): string {
        return $this->parser->text($content);
    }
}
