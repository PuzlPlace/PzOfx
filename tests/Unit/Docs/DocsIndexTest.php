<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Docs;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Puzl\PzOfx\Enums\PzOfxErrorEnum;
use Puzl\PzOfx\Enums\PzOfxWarningEnum;

/** Âncoras, seções obrigatórias e códigos documentados no guia. */
final class DocsIndexTest extends TestCase
{
    private const REQUIRED_SECTIONS = [
        'instalacao', 'inicio-rapido', 'modelo', 'valores', 'datas', 'encoding', 'deduplicacao',
        'configuracao', 'perfis', 'avisos', 'erros', 'laravel', 'testando', 'pegadinhas', 'migracao', 'diferencas', 'faq',
    ];

    private static function html(): string
    {
        $html = file_get_contents(dirname(__DIR__, 3).'/docs/index.html');
        self::assertNotFalse($html);

        return $html;
    }

    private static function xpath(): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.self::html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    public function test_toda_ancora_interna_aponta_para_um_id(): void
    {
        $xpath = self::xpath();
        $ids = [];
        foreach ($xpath->query('//*[@id]') ?: [] as $node) {
            $ids[$node->getAttribute('id')] = true;
        }

        foreach ($xpath->query('//a[starts-with(@href, "#")]') ?: [] as $link) {
            $target = substr($link->getAttribute('href'), 1);
            $this->assertArrayHasKey($target, $ids, "Âncora #{$target} sem destino");
        }
    }

    public function test_secoes_obrigatorias_existem_e_estao_no_sumario(): void
    {
        $xpath = self::xpath();
        foreach (self::REQUIRED_SECTIONS as $id) {
            $this->assertSame(1, $xpath->query("//section[@id='{$id}']")?->length, "Seção #{$id}");
            $this->assertSame(1, $xpath->query("//nav[@id='toc']//a[@href='#{$id}']")?->length, "Sumário #{$id}");
        }
    }

    public function test_todos_os_codigos_de_aviso_e_erro_estao_documentados(): void
    {
        $html = self::html();
        foreach ([...PzOfxWarningEnum::cases(), ...PzOfxErrorEnum::cases()] as $case) {
            if ($case === PzOfxErrorEnum::NO_TRANSACTIONS) {
                continue; // só a regra de validação usa
            }
            $this->assertStringContainsString('<code>'.$case->value.'</code>', $html, $case->value);
        }
    }
}
