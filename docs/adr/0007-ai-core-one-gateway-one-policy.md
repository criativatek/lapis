# ADR-0007 — Um gateway, uma política

- **Status:** Accepted
- **Date:** 2026-09-10
- **Complementa:** [ADR-0006](0006-ai-rewrites-text-it-is-never-the-source.md), que
  continua integralmente em vigor. Nada aqui autoriza a IA a ser fonte de facto.

## Context

O ADR-0006 desenhou a IA para **uma** funcionalidade: «Aperfeiçoar redação» nos
Relatórios. As garantias que interessam — pseudonimização, marcadores para os
números, guarda sobre a resposta, auditoria sem texto — foram construídas **dentro
de `App\Services\Reporting\Writing`**, que era o sítio certo enquanto houvesse uma
funcionalidade. Entretanto apareceu uma segunda (`InterventionStrategySuggester`),
que reimplementou a mesma forma de garantia à mão, e estão planeadas mais duas: o
assistente do Centro de Ajuda e o assistente pedagógico.

Quatro implementações da mesma política são quatro respostas possíveis à pergunta
«o Lapispro envia dados de alunos para um serviço externo?», e a resposta honesta
passaria a ser «lê as quatro e espera bem». Isso não é aceitável num produto sobre
menores.

Ao mesmo tempo, três coisas concretas faltavam:

1. **Nenhum fornecedor estava escolhido.** O operador escolheu: Gemini.
2. **A IA só se configurava por `.env`.** «Desligar isto, está a custar demasiado»
   era um login no servidor. A pessoa que precisa de o fazer é o operador do SaaS.
3. **Não havia medição.** Dívida nº 3 do ADR-0006: os contadores de tokens eram
   gravados desde o primeiro dia, dentro de um blob JSON de auditoria onde nada os
   conseguia somar.

## Decision

### 1. Uma porta, e o tipo trata de a fechar

`App\Services\Ai\Gateway\AiGateway::ask()` é o único ponto de saída para
funcionalidades novas. Faz, por esta ordem: entitlement → motor → rate limit →
quota → verificação de privacidade → chamada → medição.

A parte que não é uma convenção mas uma propriedade do código: **`AiAsk` não
aceita `string`.** Aceita `SanitisedPayload`, cujo construtor é **privado** e cuja
classe é **final** — `new SanitisedPayload(...)` não compila em lado nenhum.

O PHP não tem *friend classes* nem visibilidade de pacote, por isso «só o
`AiPayloadSanitizer` pode construir um» não é exprimível como tipo. O que é
exprimível, e está: construtor privado (1), classe final (2), uma só fábrica
`producedBy()` marcada `@internal` que exige um sanitizador como argumento (3), e
um teste de arquitetura que falha se essa fábrica ganhar um segundo chamador em
`app/` (4). Sobra um ato deliberado que parte um teste em CI. Não é possível por
distração, e não é possível em silêncio. Fingir mais do que isto seria pior do
que dizê-lo.

E o gateway continua a **não confiar no tipo**: `assertClean()` corre outra vez
sobre o que lhe foi entregue, imediatamente antes do fio.

**As duas funcionalidades existentes não foram migradas.** Relatórios e
Intervenções continuam a chamar `AiTextProviders` diretamente, com as suas próprias
garantias, testadas e em produção. Reescrevê-las para passarem pelo gateway era um
risco desproporcionado para uma slice cujo objetivo é a infraestrutura; fica
registado como trabalho a fazer, e o `PseudonymMap` já foi o primeiro passo (ver 4).

### 2. Gemini é um driver, não uma dependência

`GeminiProvider` regista-se como qualquer outro: uma classe em `Providers/`, um
binding no `AppServiceProvider`, uma `case` em `AiTextProviders::driver()`. Não há
`if ($provider === 'gemini')` em lado nenhum. Trocar de motor é uma alteração de
definições.

Duas decisões dentro do driver que não são detalhe:

- **A chave viaja no header `x-goog-api-key`, nunca em `?key=`.** O Gemini aceita as
  duas; todos os proxies, renderizadores de exceção e access logs entre aqui e a
  Google registam URLs. Uma credencial numa query string é uma credencial num
  ficheiro de log de alguém.
- **Um 200 não é uma resposta.** O Gemini reporta prompt recusado, bloqueio de
  segurança e resposta truncada todos com HTTP 200 e corpos de formas diferentes.
  Cada um é reconhecido e recusado; `MAX_TOKENS` é recusado porque meia resposta é
  pior do que nenhuma — o mesmo juízo que os Relatórios já fazem sobre meia secção.

### 3. A configuração desce para a base de dados, com o `.env` por baixo

`platform_settings` ganha um bloco `ai_*`, aplicado sobre a config no boot —
exatamente o arranjo que o SMTP tem desde que existe. **Todas as colunas são
nullable e null significa «não decidido aqui»**, pelo que uma instalação que se
configure pelo ambiente e nunca abra o ecrã comporta-se exatamente como antes.

A exceção é `ai_enabled`: é um interruptor geral e `false` sobrepõe-se a um `.env`
completamente configurado. «Desligar» tem de significar desligado.

A credencial é `encrypted` no modelo, não é `#[Fillable]`, tem um único caminho de
escrita (`storeAiCredential()`) e um único caminho de leitura
(`aiCredential()`, chamado só pelo `AppServiceProvider`). Não existe rota que a
devolva: a ação chama-se **«Substituir credencial»** e não «Mostrar chave».

**Isto não finge ser melhor do que é.** Uma coluna cifrada só é tão forte quanto a
`APP_KEY`, que vive no mesmo `.env` que o operador está a tentar não editar, na
mesma máquina. Onde houver um gestor de segredos a sério, a chave pertence lá com
`LAPIS_AI_KEY` injetada no deploy e esta coluna vazia — a ordem de fallback já
torna esse o arranjo suportado, e está escrito em três sítios para que ninguém
descubra tarde.

### 4. A pseudonimização passa a ser uma coisa só

O algoritmo do `PseudonymMap` (mais longo primeiro, nomes próprios incluídos,
tokens curtos excluídos, palavra inteira) mudou-se para
`App\Support\Privacy\Pseudonyms`, sem alterações. O `PseudonymMap` continua a
existir e a responder exatamente o mesmo — passou a saber apenas **quais** os nomes
de um relatório, e delega o resto. Uma implementação, dois pontos de entrada.

`AiPayloadSanitizer` acrescenta por cima a remoção por padrão — emails, telefones,
códigos postais, URLs, `n.º NN`, ULIDs/UUIDs, corridas de 6+ algarismos — e depois
**verifica o seu próprio trabalho**: `assertClean()` volta a correr todos os
detetores e recusa o payload se algum disparar. Não é cerimónia. Os passos
anteriores são expressões regulares sobre texto que este código não escreveu, e uma
substituição que silenciosamente não fez nada é indistinguível de uma que funcionou.

### 4b. E o sanitizador é a SEGUNDA barreira, nunca a única

A ordem obrigatória para qualquer conteúdo com estrutura é
**allowlist → pseudonimizar → serializar → sanitizar**, e existe uma classe que a
impõe: `AiContext`.

Porque é que a ordem oposta — montar o parágrafo e sanitizá-lo — não serve: no
momento em que o parágrafo existe, a estrutura que o tornava verificável
desapareceu. O sanitizador procura formas que conhece e é cego a tudo o resto. Um
pipeline cuja única barreira é pattern matching sobre prosa deixa passar
exatamente os dados pessoais para os quais ninguém escreveu um padrão.

`AiContext::add()` aceita `string|int|float|null` **e recusa tudo o resto em
runtime**. Isso é uma guarda explícita e não a união de tipos do parâmetro, por um
motivo concreto que só apareceu porque um teste o procurou:
`Illuminate\Database\Eloquent\Model::__toString()` devolve `toJson()`, portanto um
parâmetro tipado `string` **aceita um registo Eloquent inteiro por coerção** —
`->add('Aluno', $student)` teria posto todas as colunas da linha dentro do prompt,
em silêncio. E `declare(strict_types=1)` não resolve, porque o modo estrito é
decidido pelo ficheiro *chamador*: esta classe não o pode impor a um serviço
escrito para o ano.

Cada valor é pseudonimizado no `add()`, enquanto ainda é um escalar isolado com
significado conhecido. `AiContext::fields()` existe para que um teste possa provar
essa ordem diretamente, em vez de a inferir da string final — um teste que só vê o
resultado não distingue «pseudonimizou antes de juntar» de «juntou e depois
pseudonimizou», e é exatamente essa a diferença.

`sanitiseFields()` passou a delegar aqui. A versão anterior juntava os campos e
sanitizava o parágrafo, ou seja, fazia das expressões regulares a única barreira —
precisamente o arranjo que isto substitui.

**A linha dos números está nos seis algarismos**, e é um compromisso deliberado: um
número com seis ou mais algarismos não é uma classificação, e um com menos não é um
NIF, um cartão de cidadão nem um telefone. Os Relatórios tomam a posição oposta e
mais estrita para o seu caso — *todos* os números viram marcadores — e continuam
com ela. Um payload sem percentagens e sem períodos é um payload sobre o qual não há
pergunta que valha a pena fazer.

### 5. Duas capabilities, e nenhuma num plano

`help_assistant` e `ai_pedagogical_analysis` entram no catálogo e **em nenhum
plano**. Não é um esquecimento: a que subscrição pertencem é uma decisão comercial
que ninguém tomou, e «alterar a composição comercial dos planos» está na lista do
CLAUDE.md §31. Escrever um número ou uma chave num array seria tomá-la em silêncio.

Consequência, dita em voz alta: hoje nenhuma organização as tem, todos os ecrãs
mostram o estado «plano», e nada construído sobre o gateway chega a um motor. É o
estado pretendido até alguém decidir. Um teste falha de propósito no dia em que
alguém as compuser num plano, para que leia primeiro o
[contrato](../ai-core-contract.md) §10.

São **duas** e não uma porque uma escola pode razoavelmente querer o assistente de
ajuda e não a análise pedagógica, e porque a segunda é a que vê material sobre
crianças. E nenhuma delas é `ai_assistance`: reutilizá-la significaria que ligar o
assistente de ajuda ligava também a reescrita dentro dos relatórios.

### 6. Uma tabela nova para medir, o rasto existente para auditar

`ai_usage_events` responde a perguntas que se contam — «quantos pedidos este mês»,
«este utilizador está acima do teto diário» — e a verificação de quota é um
`count(*)` no caminho do pedido. Fazê-lo sobre `audit_events` seria um
`JSON_EXTRACT` sobre uma tabela cujas linhas são, por desenho, sobre outra coisa, e
poria um índice de medição no rasto de auditoria.

**Não tem coluna onde caiba um prompt ou uma resposta.** Não é uma regra que alguém
siga; é o esquema. Um medidor que acumulasse o que os professores escrevem sobre
crianças seria um problema de proteção de dados criado para medir uma fatura.

Para a **auditoria de configuração**, `audit_events.organization_id` passa a ser
nullable e ganha `AuditLog::recordPlatform()`. Uma segunda tabela de auditoria seria
um rasto paralelo — a duplicação que se queria evitar — e o próximo evento de
plataforma (a palavra-passe SMTP, hoje igualmente não auditada) teria de ser
acrescentado às duas. As linhas de plataforma são invisíveis a qualquer tenant de
graça: o global scope compara a coluna a um número, e NULL nunca é igual a um número.

### 7. O rate limit vive dentro do gateway

Os Relatórios limitam por middleware de rota, o que funciona porque só lá se chega
por HTTP. O gateway tem de aguentar a mesma linha para um job ou um comando, onde
não há stack de middleware — por isso toma os baldes ele próprio, um par por
capability. Uma rota construída sobre o gateway **não deve** acrescentar
`throttle:` para a mesma capability: contaria duas vezes.

## Consequences

- **A funcionalidade continua desligada** em qualquer instalação que não a
  configure, incluindo a de produção — e agora também porque nenhum plano concede
  as capabilities novas. Dois cadeados independentes, ambos no estado fechado.
- **Não há credencial Gemini nesta instalação**, e todo o caminho está testado sem
  uma: `Http::fake()` em todo o lado, uma chave obviamente fictícia como canário, e
  nenhum teste que passe ou falhe consoante a conta de alguém.
- **Fecha a dívida nº 3 do ADR-0006.** Os contadores passam a ter onde ser somados.
  As dívidas 1 (página de proteção de dados) e 2 (consentimento por organização)
  continuam abertas.
- **Relatórios e Intervenções não migraram.** Duas formas de chamar um motor
  coexistem, e a mais antiga é a que está em produção. Registado como trabalho
  futuro, não como estado desejado.
- **O sanitizador vai remover coisas que não precisava de remover.** É aceitável e é
  o desenho — um falso positivo custa contexto a uma resposta; um falso negativo é
  um número de aluno num sistema remoto.
- **O que o sanitizador não consegue ver continua a passar.** Um nome fora da pauta
  — um irmão, um colega, um aluno de outra turma — não é apanhado por nenhuma
  regra. Está escrito no código, está escrito no contrato, e é por isso que
  `ai_pedagogical_analysis` é uma capability separada: para que uma escola possa
  recusar a categoria inteira em vez de confiar numa expressão regular.
