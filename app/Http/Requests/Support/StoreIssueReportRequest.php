<?php

namespace App\Http\Requests\Support;

use App\Models\SupportCategory;
use App\Support\Support\ClientContext;
use App\Support\Support\IssueConsent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Um reporte feito pelo widget: o mesmo pedido, com contexto e com imagens.
 *
 * PORQUÊ UM PEDIDO PRÓPRIO E NÃO MAIS CAMPOS NO DE SEMPRE. O formulário de
 * `/support/novo` é uma conversa e recusa ficheiros por escrito; este leva
 * `multipart` e uma decisão de consentimento que aquele não tem. São dois
 * caminhos com regras diferentes para a mesma fila, e misturá-los faria com que
 * cada um tivesse de saber quando é que as regras do outro se aplicam.
 *
 * MULTIPART, E NUNCA BASE64 DENTRO DE JSON. O sistema equivalente do Plaanly
 * envia as imagens como cadeias de caracteres dentro do corpo JSON, e os
 * comentários do próprio código pedem desculpa dos 422 que isso provoca — um
 * limite em caracteres não é um limite em bytes, e a mensagem que chega ao
 * utilizador é «o botão de reportar dá erro». Com `multipart` o limite é o do
 * ficheiro, e diz o que é.
 */
class StoreIssueReportRequest extends FormRequest
{
    /** Três imagens chegam para mostrar um defeito; cinco já é um álbum. */
    public const MAX_IMAGES = 3;

    /** 5 MB por imagem. Uma captura de ecrã em JPEG fica muito abaixo disto. */
    public const MAX_KILOBYTES = 5120;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Opcionais desde o SUP-2B5T3J: o widget deixou de os perguntar.
            // Quem descreve um defeito já o está a resumir — ver `payload()`,
            // onde os dois se derivam quando não vêm.
            'category' => ['nullable', Rule::enum(SupportCategory::class)],
            'subject' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'technical_route' => ['nullable', 'string', 'max:200'],

            // A captura que a aplicação tirou. Só entra se vier certificada —
            // ver `OpenIssueReport`, onde essa condição é imposta, e não aqui:
            // uma imagem por certificar não é um erro de validação, é uma
            // imagem que não segue. O reporte segue na mesma.
            'screenshot' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:'.self::MAX_KILOBYTES],

            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:'.self::MAX_KILOBYTES],

            ...ClientContext::rules(),
            ...IssueConsent::rules(),
        ];
    }

    /**
     * O que este pedido aceita, com a forma declarada.
     *
     * Escrito por extenso em vez de devolver `validated()` à solta: a acção do
     * outro lado declara uma forma exacta, e uma FormRequest é o único sítio
     * que sabe mesmo qual é a sua. Cada valor é convertido para o tipo que diz
     * ter — um `boolean()` sobre um campo de `multipart`, onde tudo chega como
     * string, é a diferença entre «false» e `false`.
     *
     * @return array{category: string, subject: string, description: string, technical_route: ?string, client_context: ?array<string, mixed>, screenshot_certified: bool, screenshot_warning: ?string}
     */
    public function payload(): array
    {
        $route = $this->input('technical_route');
        $context = $this->input('client_context');
        $warning = $this->input('screenshot_warning');

        $category = trim((string) $this->string('category'));
        $subject = trim((string) $this->string('subject'));
        $description = (string) $this->string('description');

        return [
            // Derivados quando o widget não os manda (SUP-2B5T3J): a categoria
            // nasce «other» — a triagem do backoffice reclassifica quando fizer
            // diferença — e o resumo é a primeira linha da descrição, encolhida.
            // A derivação vive AQUI e não na acção: este é o único sítio que
            // sabe a forma do pedido, e a acção continua a receber os campos
            // que sempre declarou.
            'category' => $category !== '' ? $category : SupportCategory::Other->value,
            'subject' => $subject !== '' ? $subject : $this->subjectFrom($description),
            'description' => $description,
            'technical_route' => is_string($route) ? $route : null,
            'client_context' => is_array($context) ? $context : null,
            'screenshot_certified' => $this->boolean('screenshot_certified'),
            'screenshot_warning' => is_string($warning) ? $warning : null,
        ];
    }

    /**
     * O resumo derivado: a primeira linha do que a pessoa escreveu, encolhida.
     *
     * Espaços em série colapsam primeiro — texto ditado chega sem pontuação e
     * com respirações — e o corte é em 70 caracteres, que é o que uma fila de
     * backoffice mostra sem truncar ela própria.
     */
    protected function subjectFrom(string $description): string
    {
        $firstLine = (string) preg_replace('/\s+/u', ' ', trim($description));

        return Str::limit($firstLine, 70, '…');
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category' => __('assunto'),
            'subject' => __('resumo'),
            'description' => __('descrição'),
            'screenshot' => __('captura de ecrã'),
            'images' => __('imagens'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'images.max' => __('Pode juntar no máximo :max imagens.'),
            'screenshot.max' => __('A captura de ecrã é demasiado grande.'),
            'images.*.max' => __('Cada imagem tem de ter menos de 5 MB.'),
        ];
    }
}
