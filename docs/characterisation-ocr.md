# Importar caracterização a partir de imagem (OCR)

Fatia que preenche a seam `extractTableFromImage()`
(`resources/js/pages/classes/partials/characterisation-image-extraction.ts`):
lê uma tabela fotografada/colada como imagem e produz a mesma estrutura
`ExtractedTable` que os restantes formatos (HTML colado, texto colado,
.docx) já produzem — ver `app/Services/Characterisation/Import/Extraction/`.

## Privacidade — a imagem nunca sai do browser

A imagem pode conter nomes de menores. O reconhecimento de texto corre
**inteiramente no browser**, num Web Worker do `tesseract.js`, contra
ficheiros servidos pela própria aplicação — nunca a um serviço de OCR de
terceiros, nunca ao Gemini, nunca ao servidor do Lapispro. Só o texto já
reconhecido (uma tabela estruturada) é enviado ao endpoint de pré-visualização
existente, como `extracted_table` (JSON) — nunca os bytes da imagem.

Isto inclui os próprios ficheiros do `tesseract.js`: o `workerPath`, o
`corePath` e o `langPath` apontam todos para `/vendor/tesseract/`, servido
pela própria app — nunca para o CDN jsDelivr que é a omissão do `tesseract.js`.
Sem isso, cada OCR seria um pedido de rede a um terceiro a dizer "alguém
nesta escola acabou de fazer OCR a uma tabela", mesmo sem levar bytes da
imagem. Não há dependência de terceiros em runtime nesta fatia.

Ver o bloco de comentário no topo de `characterisation-image-extraction.ts`
para o detalhe, e `characterisation-image-extraction.test.ts` para o teste
que garante que nenhum pedido de rede transporta a imagem.

### Medido, não apenas afirmado

Um teste com worker simulado prova que o código não chama `fetch`; não prova
que a aplicação servida se comporta assim. A garantia foi por isso verificada
num browser real, com a rede observada durante todo o reconhecimento de uma
imagem colada:

| | |
|---|---|
| Pedidos para fora da aplicação | **0** |
| Assets do tesseract pedidos | `/vendor/tesseract/worker.min.js`, `tesseract-core-simd-lstm.wasm.js`, `por.traineddata.gz` — todos da própria origem |
| Resultado | «Tabela reconhecida na imagem (3 linhas)» |

Vale a pena registar como esta verificação se pagou. Antes dela, o número de
pedidos externos também era zero — mas pela pior das razões: o OCR nunca
arrancava. A detecção de imagem procurava um tipo começado por `image/` em
`clipboardData.types`, e um browser que recebe um screenshot anuncia
`types: ["Files"]`, com o tipo concreto no ficheiro. O caminho inteiro estava
inalcançável pelo gesto para que foi construído, e o teste unitário não o
via porque escrevia à mão a forma que o código esperava em vez da que os
browsers produzem.

Um zero pode significar «nada sai» ou «nada acontece». Só a medição distingue
os dois.

## Instalação: nada a transferir à mão

`public/vendor/tesseract/` (worker, núcleo WASM e o modelo de português) é
gerado no `build`, nunca committed. Os três ficheiros já vêm de pacotes npm
que o projeto instala de qualquer forma — `tesseract.js` (worker),
`tesseract.js-core` (núcleo WASM, todas as variantes) e
`@tesseract.js-data/por` (traineddata de português) — e um plugin Vite
(`resources/build/vite-plugin-tesseract-assets.ts`) copia só o que é
efetivamente usado de `node_modules` para `public/vendor/tesseract/`:

- `worker.min.js` — de `tesseract.js/dist`.
- `tesseract-core-simd-lstm.wasm(.js)` — a ÚNICA variante do núcleo que
  `characterisation-image-extraction.ts` pede via `corePath` (`tesseract.js-core`
  em si ships ~44 MB de variantes; copiamos só esta, ~6,7 MB juntas).
- `por.traineddata.gz` — a variante `4.0.0_best_int` (int8, ~1,4 MB
  comprimido) de `@tesseract.js-data/por`, não a `4.0.0` por omissão do
  pacote (~6,8 MB comprimido) — mantém o footprint que a app sempre teve.

O plugin corre em `buildStart`, que o Vite dispara tanto em `npm run build`
como em `npm run dev`; é idempotente (só reescreve um ficheiro se faltar ou
o conteúdo mudar). Não há passo manual: `npm install && npm run build` (ou
`npm run dev`) já deixa `public/vendor/tesseract/` completo. Zero binários
no git, zero dependência de CDN de terceiros em runtime.

## Como funciona (resumo)

1. A imagem é validada e descodificada no browser (dimensões, tamanho,
   decodificação real) antes de qualquer OCR — ver
   `decodeAndValidateImage()`.
2. `tesseract.js` corre num worker próprio e devolve palavras com caixas
   delimitadoras (bounding boxes) e confiança por palavra.
3. `characterisation-ocr-grid.ts` reconstrói a grelha: agrupa palavras em
   linhas por sobreposição vertical (tolerante a fotografias tortas), infere
   colunas pelo alinhamento horizontal de TODA a imagem, e nunca desloca uma
   célula vazia — uma célula sem palavra fica vazia, nunca empurra a coluna
   seguinte.
4. O resultado é enviado como `extracted_table` ao endpoint de
   pré-visualização existente (`CharacterisationImportController::preview`),
   que o valida como entrada hostil (limites de linhas/colunas/tamanho de
   texto) antes de o transformar num `ExtractedTable` e o passar pelo mesmo
   `NormaliseExtractedTable` que todos os outros formatos usam.

## O que este código nunca faz

Nunca corrige automaticamente o texto reconhecido — um `ACN5` mal lido nunca
se torna silenciosamente `ACNS`. A confiança de extração (por célula, do
OCR) é um eixo diferente da `CodeConfidence` (se um código é reconhecido) —
ver o comentário em `ExtractedCell.php` e em
`characterisation-extracted-table.ts`.
