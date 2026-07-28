<?php

namespace App\Domain\Templates;

use Illuminate\Support\Carbon;

final class TemplateEngine
{
    /**
     * Substitutes standard template variables cleanly without eval.
     *
     * Supported variables:
     * {{title}}, {{workspace}}, {{date}}, {{time}}, {{iso_datetime}},
     * {{date:YYYY-MM-DD}}, {{date:YYYY-MM}}, {{date:YYYY}}
     */
    public function render(string $content, array $context = []): string
    {
        $now = isset($context['date']) ? Carbon::parse($context['date']) : now();

        $replacements = [
            '{{title}}' => $context['title'] ?? 'Untitled',
            '{{workspace}}' => $context['workspace'] ?? 'Default',
            '{{date}}' => $now->toDateString(),
            '{{time}}' => $now->toTimeString(),
            '{{iso_datetime}}' => $now->toIso8601String(),
            '{{date:YYYY-MM-DD}}' => $now->format('Y-m-d'),
            '{{date:YYYY-MM}}' => $now->format('Y-m'),
            '{{date:YYYY}}' => $now->format('Y'),
        ];

        return strtr($content, $replacements);
    }
}
