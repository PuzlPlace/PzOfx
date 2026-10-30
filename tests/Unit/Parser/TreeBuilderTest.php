<?php

declare(strict_types=1);

namespace Puzl\PzOfx\Tests\Unit\Parser;

use Puzl\PzOfx\Parser\Node;
use Puzl\PzOfx\Parser\TreeBuilder;
use Puzl\PzOfx\Tests\TestCase;

final class TreeBuilderTest extends TestCase
{
    private static function node(string $body): Node
    {
        $root = TreeBuilder::build('<OFX><STMTTRN>'.$body.'</STMTTRN></OFX>');

        return $root->find('STMTTRN')[0];
    }

    public function test_folhas_sem_fechamento_em_linhas(): void
    {
        $trn = self::node("\n<TRNTYPE>DEBIT\n<TRNAMT>-50,00\n<FITID>123\n");

        $this->assertSame(['TRNTYPE' => 'DEBIT', 'TRNAMT' => '-50,00', 'FITID' => '123'], $trn->firstFields());
    }

    public function test_arquivo_inteiro_numa_linha_so(): void
    {
        $trn = self::node('<TRNTYPE>DEBIT<TRNAMT>-50.00</TRNAMT><FITID>1<MEMO>A B</STMTTRN>');

        $this->assertSame(['TRNTYPE' => 'DEBIT', 'TRNAMT' => '-50.00', 'FITID' => '1', 'MEMO' => 'A B'], $trn->firstFields());
    }

    public function test_folha_vazia_em_todas_as_formas(): void
    {
        $trn = self::node("<TRNTYPE>\n<MEMO/><NAME></NAME><CHECKNUM>   \n<REFNUM>\n<FITID>9");

        $this->assertSame(['TRNTYPE' => '', 'MEMO' => '', 'NAME' => '', 'CHECKNUM' => '', 'REFNUM' => '', 'FITID' => '9'], $trn->firstFields());
    }

    public function test_tags_minusculas_viram_maiusculas(): void
    {
        $trn = self::node('<trnamt>1.00</trnamt><Memo>x</Memo>');

        $this->assertSame(['TRNAMT' => '1.00', 'MEMO' => 'x'], $trn->firstFields());
    }

    public function test_entidades_e_e_comercial_solto(): void
    {
        $trn = self::node('<MEMO>A &amp; B &lt;C&gt; D&#39;E &nbsp;F<NAME>AGUA & ESGOTO #12');

        $this->assertSame("A & B <C> D'E \u{00A0}F", $trn->field('MEMO'));
        $this->assertSame('AGUA & ESGOTO #12', $trn->field('NAME'));
    }

    public function test_menor_que_que_nao_e_tag_vira_texto(): void
    {
        $trn = self::node('<MEMO>PAG <LOJA X> LTDA 3 < 5</MEMO><FITID>1');

        $this->assertSame('PAG <LOJA X> LTDA 3 < 5', $trn->field('MEMO'));
        $this->assertSame('1', $trn->field('FITID'));
    }

    public function test_tag_desconhecida_no_meio_do_memo_volta_para_o_texto(): void
    {
        $trn = self::node("<MEMO>PAG <BOLETO> LTDA\n<FITID>1");

        $this->assertSame('PAG <BOLETO> LTDA', $trn->field('MEMO'));
        $this->assertSame('1', $trn->field('FITID'));
    }

    public function test_tag_desconhecida_em_linha_propria_e_campo(): void
    {
        $trn = self::node("<MEMO>PAGAMENTO\n<BANCOX>valor\n<FITID>1");

        $this->assertSame('PAGAMENTO', $trn->field('MEMO'));
        $this->assertSame('valor', $trn->field('BANCOX'));
    }

    public function test_cdata_e_literal_e_comentarios_sao_descartados(): void
    {
        $trn = self::node('<!-- nada --><MEMO><![CDATA[a &amp; <b>]]></MEMO><?pi x?><FITID>1');

        $this->assertSame('a &amp; <b>', $trn->field('MEMO'));
        $this->assertSame('1', $trn->field('FITID'));
    }

    public function test_fechamento_de_ancestral_fecha_o_que_ficou_aberto(): void
    {
        $root = TreeBuilder::build('<OFX><BANKTRANLIST><STMTTRN><FITID>1<STMTTRN><FITID>2</BANKTRANLIST><LEDGERBAL><BALAMT>5</LEDGERBAL></OFX>');

        $ofx = $root->child('OFX');
        $this->assertCount(2, $ofx->find('STMTTRN'));
        $this->assertSame('5', $ofx->value('LEDGERBAL', 'BALAMT'));
    }

    public function test_fechamento_orfao_e_ignorado(): void
    {
        $root = TreeBuilder::build('<OFX></FOO></BAR><STMTTRN><FITID>1</FITID></FITID></STMTTRN></OFX>');

        $this->assertSame('1', $root->child('OFX')->find('STMTTRN')[0]->field('FITID'));
    }

    public function test_aggregate_desconhecido_fechado_no_arquivo_vira_no(): void
    {
        $trn = self::node('<BANCO.EXT><COD>7</BANCO.EXT><FITID>1');

        $this->assertSame('7', $trn->value('BANCO.EXT', 'COD'));
        $this->assertSame('1', $trn->field('FITID'));
    }

    public function test_currency_como_folha_nao_engole_os_irmaos(): void
    {
        $trn = self::node('<CURRENCY>USD<TRNAMT>1.00<FITID>1');

        $this->assertSame(['CURRENCY' => 'USD', 'TRNAMT' => '1.00', 'FITID' => '1'], $trn->firstFields());
    }

    public function test_currency_como_aggregate(): void
    {
        $trn = self::node('<CURRENCY><CURRATE>5.1<CURSYM>USD</CURRENCY><FITID>1');

        $this->assertSame('USD', $trn->value('CURRENCY', 'CURSYM'));
        $this->assertSame('1', $trn->field('FITID'));
    }

    public function test_varios_blocos_ofx_e_lixo_depois(): void
    {
        $root = TreeBuilder::build("<OFX><STMTTRN><FITID>1</STMTTRN></OFX>\nOFXHEADER:100\n<OFX><STMTTRN><FITID>2</STMTTRN></OFX><html><body>erro</body></html>");

        $blocks = array_values(array_filter($root->children, static fn (Node $n): bool => $n->name === 'OFX'));
        $this->assertCount(2, $blocks);
        $this->assertSame('2', $blocks[1]->find('STMTTRN')[0]->field('FITID'));
    }

    public function test_doctype_e_entidades_externas_nao_sao_processados(): void
    {
        $root = TreeBuilder::build('<!DOCTYPE OFX [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><OFX><STMTTRN><MEMO>&xxe;</STMTTRN></OFX>');

        $this->assertSame('&xxe;', $root->child('OFX')?->find('STMTTRN')[0]->field('MEMO'));
    }

    public function test_aninhamento_patologico_nao_estoura(): void
    {
        $body = '<OFX>'.str_repeat('<STMTTRN>', 500).'<FITID>1'.str_repeat('</STMTTRN>', 500).'</OFX>';

        $root = TreeBuilder::build($body);

        $this->assertNotNull($root->child('OFX'));
    }
}
