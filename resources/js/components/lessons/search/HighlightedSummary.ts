import { defineComponent, h } from 'vue';
import type { PropType } from 'vue';
import type { SummarySearchSegment } from '@/lib/lessonSummarySearch';

/**
 * O SUMÁRIO COMPLETO COM AS OCORRÊNCIAS DESTACADAS.
 *
 * Os pedaços vêm do servidor já decididos (`SummarySearchText::segments`) e a
 * concatenação deles é o texto gravado: aqui só se desenha. Cada pedaço entra
 * como TEXTO — o Vue escapa-o —, e nunca por `v-html`: um sumário com «<b>» ou
 * «<script>» aparece tal como foi escrito.
 *
 * Função de render e não template, de propósito: dentro de um bloco
 * `whitespace-pre-line`, o espaço que a indentação de um template deixasse
 * entre as tags passava a ser texto do sumário. As quebras de linha e os
 * parágrafos são os do professor, e mais nenhuns.
 */
export default defineComponent({
    name: 'HighlightedSummary',
    props: {
        segments: {
            type: Array as PropType<SummarySearchSegment[]>,
            required: true,
        },
    },
    setup(props) {
        return () =>
            h(
                'p',
                {
                    class: 'max-w-[72ch] text-base leading-relaxed whitespace-pre-line wrap-anywhere',
                    'data-testid': 'search-result-summary',
                },
                props.segments.map((segment, index) =>
                    segment.match
                        ? h(
                              'mark',
                              {
                                  key: index,
                                  class: 'rounded-[3px] bg-amber-200/80 box-decoration-clone px-0.5 text-foreground dark:bg-amber-400/35',
                                  'data-testid': 'search-match',
                              },
                              segment.text,
                          )
                        : segment.text,
                ),
            );
    },
});
