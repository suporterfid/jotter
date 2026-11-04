<?php

namespace Tests\Unit;

use App\Domain\Mcp\MarkdownSectionAppender;
use PHPUnit\Framework\TestCase;

final class MarkdownSectionAppenderTest extends TestCase
{
    private MarkdownSectionAppender $appender;

    protected function setUp(): void
    {
        $this->appender = new MarkdownSectionAppender;
    }

    public function test_appends_at_the_end_with_one_blank_line(): void
    {
        $this->assertSame("# Diário\n\n- 09:00 começo\n\n- 10:00 agente\n", $this->appender->append("# Diário\n\n- 09:00 começo\n\n\n", '- 10:00 agente'));
        $this->assertSame("- primeira\n", $this->appender->append('', "- primeira\n"));
    }

    public function test_appends_at_the_end_of_a_section_before_the_next_heading(): void
    {
        $document = "# Projeto\n\n## Decisões\n\n- A\n\n## Próximo passo\n\n- B\n";

        $this->assertSame(
            "# Projeto\n\n## Decisões\n\n- A\n\n- C\n\n## Próximo passo\n\n- B\n",
            $this->appender->append($document, '- C', 'decisões'),
        );
    }

    public function test_deeper_headings_stay_inside_the_section(): void
    {
        $document = "## Notas\n\n### Sub\n\ntexto\n\n## Outra\n";

        $this->assertSame("## Notas\n\n### Sub\n\ntexto\n\nnovo\n\n## Outra\n", $this->appender->append($document, 'novo', 'Notas'));
    }

    public function test_headings_in_code_fences_and_front_matter_are_ignored(): void
    {
        $document = "---\ntitle: Notas\n---\n\n## Log\n\n```md\n## Log\n```\n\n## Fim\n";

        $this->assertSame(
            "---\ntitle: Notas\n---\n\n## Log\n\n```md\n## Log\n```\n\nentrada\n\n## Fim\n",
            $this->appender->append($document, 'entrada', 'Log'),
        );
    }

    public function test_missing_heading_is_created_at_the_end(): void
    {
        $this->assertSame("# Diário\n\n## Hermes\n\n- feito\n", $this->appender->append("# Diário\n", '- feito', 'Hermes'));
    }

    public function test_crlf_documents_keep_crlf(): void
    {
        $this->assertSame("# A\r\n\r\nb\r\n", $this->appender->append("# A\r\n", 'b'));
    }
}
