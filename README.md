# PzOfx

Leitura de extratos **OFX** de qualquer banco: OFX 1.x (SGML) e 2.x (XML), conta corrente
e cartão de crédito, vários extratos no mesmo arquivo. Valores como string decimal exata,
datas sem deslocamento de fuso, encoding detectado sozinho, deduplicação por FITID ou
fingerprint, perfis por banco e avisos para tudo que veio fora do padrão.

```php
use Puzl\PzOfx\PzOfx;

$document  = PzOfx::fromFile($request->file('ofx'));   // UploadedFile, caminho, stream ou conteúdo
$statement = $document->statement();                   // primeiro extrato (o caso comum)

$statement->account->bankCode();   // "341"  (BANKID "0341" normalizado)
$statement->account->number();     // "12345" / digit() → "6"
$statement->firstDate();           // menor data das transações (não a ordem do arquivo)

foreach ($statement as $transaction) {
    $transaction->postedAt;        // DateTimeImmutable — o dia do banco, sem virar o dia
    $transaction->amount;          // "-1234.56" — string exata, nunca float
    $transaction->direction();     // PzOfxDirectionEnum::DEBIT (pelo sinal)
    $transaction->description;     // MEMO; se vazio, NAME
    $transaction->uniqueId;        // FITID confiável ou fingerprint estável
}

$document->warnings;               // FITID repetido, linha de saldo ignorada, data deduzida…
```

**[Guia completo do desenvolvedor →](https://puzlplace.github.io/PzOfx/)** (fonte: [`docs/index.html`](docs/index.html))

---

## Instalação

O pacote **não está no Packagist**.

1. Declare o repositório no `composer.json` da aplicação:

   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/PuzlPlace/PzOfx.git" }
   ]
   ```

2. Instale por versão:

   ```bash
   composer require puzl/pzofx:^1.0
   ```

Requisitos: PHP `^8.1` com `mbstring`. Nenhuma dependência obrigatória — funciona em
qualquer projeto PHP. No Laravel (10 a 13) o Service Provider entra por auto-discovery e
a config é opcional:

```bash
php artisan vendor:publish --tag=pzofx-config   # só se precisar mudar algo
```

---

## O que o pacote faz

| Área | Em uma linha |
|------|--------------|
| **Formatos** | OFX 1.02–1.60 (SGML) e 2.x (XML); tags sem fechamento, arquivo numa linha só, tags minúsculas, `<MEMO/>`, CDATA, vários blocos `<OFX>`, lixo depois do `</OFX>`. Sem libxml: sem XXE. |
| **Encoding** | UTF-8 válido é usado como está (o cabeçalho mente às vezes); o resto é Windows-1252/ISO-8859-1. BOM e UTF-16 tratados. Acentos preservados. |
| **Valores** | `-1.234,56`, `1,234.56`, `-50,00`, `+3.500,00`, `50.00-`, `(50.00)` → `"-1234.56"`. Soma exata (`total()`, `totalCredits()`), `amountCents()`. |
| **Datas** | `20240115`, `20240115120000.000[-3:BRT]`, `[-03:EST]`, `[+5.30:IST]`… Modo `local` (padrão) mantém o dia do banco; `convert` aplica o offset. |
| **Extratos** | Conta (`STMTRS`) e cartão (`CCSTMTRS`), várias contas por arquivo, saldos, status, período declarado e real. |
| **Deduplicação** | `uniqueId` = FITID quando presente e único no extrato; senão `fingerprint` (conta + data + valor + descrição + ocorrência). |
| **Perfis por banco** | Descrição (MEMO/NAME), política de sinal (cartão invertido), confiança no FITID, linhas de saldo — por BANKID, ORG, FID ou tipo de extrato. |
| **Pegadinhas reais** | Linhas "SALDO ANTERIOR/TOTAL DISPONÍVEL DIA" (Itaú, BB) ignoradas; FITID repetido (Caixa, Unicred, BB); DTPOSTED ausente (Sicoob) deduzido de DTUSER/FITID. |
| **Erros** | Exceções com código estável (`PzOfxErrorEnum`) e mensagem en/pt_BR; avisos (`PzOfxWarningEnum`) sem interromper. Modo `strict` decide se transação ruim derruba ou é pulada. |
| **Laravel** | Config publicável, `PzOfxParserInterface` singleton, regra `PzOfxFileRule` para FormRequest. |
| **Testes** | `PzOfx::fake()` com asserts, `PzOfxDocumentBuilder`, mock da interface ou da classe concreta. |

### Validação no FormRequest

```php
use Puzl\PzOfx\Laravel\Rules\PzOfxFileRule;

'file' => ['required', 'file', 'max:10240', new PzOfxFileRule()],
```

### Perfis por banco (`config/pzofx.php`)

```php
'profiles' => [
    ['match' => ['bank_id' => '237'], 'description' => 'name_and_memo'],
    ['match' => ['bank_id' => ['260', '336'], 'statement' => 'credit_card'], 'amount_sign' => 'invert'],
],
```

Ajuste só de uma leitura: `PzOfx::fromFile($file, ['strict' => false])`.

### Injeção e testes

Injete o contrato; no Laravel ele resolve para o singleton com a config da aplicação:

```php
use Puzl\PzOfx\Contracts\PzOfxParserInterface;

public function __construct(private readonly PzOfxParserInterface $parser) {}
```

Nos testes, use o que preferir — lib real, builder, fake ou mock:

```php
use Puzl\PzOfx\Testing\PzOfxDocumentBuilder;

$document = PzOfxDocumentBuilder::bank('341', '12345-6')
    ->transaction('-50.00', '2024-01-15', 'TARIFA')
    ->build();                                   // passa pelo parser real

PzOfx::fake([$document]);                        // vale para injeção e para PzOfx::
PzOfx::assertParsedTimes(1);

$this->mock(PzOfxParserInterface::class)->shouldReceive('parseFile')->andReturn($document);
```

---

## Migrando do `App\Library\Ofx\Ofx`

`Puzl\PzOfx\Compat\PzOfxLegacy` é substituto direto: mesmo construtor, mesmas propriedades
(`bankTransactions`, `startAt`, `endAt`, `bankCode`, `account`, `accountDigit`, `org`), mesmo
formato de linha e as mesmas chaves de tradução nas exceções. Basta trocar o `use`:

```php
use Puzl\PzOfx\Compat\PzOfxLegacy as Ofx;   // era: use App\Library\Ofx\Ofx;
```

Onde o leitor antigo lia certo, o resultado é **idêntico** (coberto por teste contra uma cópia
fiel do `Ofx.php`). O que muda é só correção:

| Situação | `Ofx.php` | `PzOfxLegacy` |
|----------|-----------|---------------|
| Acentos (`TRANSFERÊNCIA`, `JOSÉ`) | removidos: `TRANSFERNCIA`, `JOS` (~16 mil linhas em produção) | preservados |
| `&` e `#` na descrição | apagados | preservados |
| Emoji / caractere de 4 bytes | apagados | apagados (a coluna é `utf8mb3`) |
| Arquivo numa linha só, `<SEVERITY>INFO</STATUS>`, `&` solto, `<` no MEMO | "String could not be parsed as XML" | lido |
| Linhas "SALDO ANTERIOR", "SALDO TOTAL DISPONÍVEL DIA"… (Itaú, BB) | importadas como transação, com o valor do saldo | ignoradas (aviso) |
| Banco que manda do mais novo ao mais antigo | `startAt` > `endAt` | menor e maior data |
| `1,234.56` | lido como `1` | `1234.56` |
| `value` | string em arquivo com ponto, float em arquivo com vírgula | sempre string exata (`"-35.70"`) |
| MEMO vazio com NAME | descrição vazia | NAME |
| DTPOSTED ausente (Sicoob) | erro | DTUSER ou data do FITID (aviso) |
| `description` > 255, `check_num` > 55 | erro do banco ("Data too long") | truncados para caber |

O documento completo continua acessível em `$ofx->document()` para migrar aos poucos.

---

## Desenvolvimento

```bash
composer install
composer test     # PHPUnit: tests/Unit + tests/Features (fixtures por banco, equivalência com o legado, Laravel)
```

As fixtures em `tests/Fixtures/ofx/` reproduzem os estilos de Bradesco, Itaú, Nubank, BB,
Caixa, Santander, Sicoob, cartão e várias contas — com encoding e quebras de linha reais.

### Versionamento

Não há versão escrita no pacote — a fonte é a tag do Git. Toda entrega na branch
`production` com CI verde gera a próxima tag *patch* e o release
(`.github/workflows/tests.yml`, job `Release`). Minor e major são criados à mão uma vez;
o autoincremento continua a partir deles.

| Constraint | Resolve para |
|------------|--------------|
| `^1.0` | última `1.x` — **recomendado** |
| `~1.2.0` | última `1.2.x` (só correções) |
| `1.2.3` | exatamente essa versão |
