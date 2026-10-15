<script setup lang="ts">
import { computed } from 'vue';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import type { ToneableScaleBand } from '@/lib/qualitativeTone';
import { displayOf, EARLIER_MARK, fullText, isOtherCriteria, title as titleOf } from '@/lib/selfAssessmentReading';
import type { SelfAssessmentReading } from '@/lib/selfAssessmentReading';

const props = withDefaults(
    defineProps<{
        reading: SelfAssessmentReading;
        /** A narrow cell: the value, plus «(ant.)» when it is not on today's criteria. Otherwise the value is written out in full. */
        compact?: boolean;
        /** The domain it is about; absent for the global answer. */
        domain?: string;
        /** The class's current scale. With it, a reading on today's criteria takes the colour of its band; without it, none. */
        bands?: ToneableScaleBand[];
        /** Written before the value in the visible text («Auto », «A»), for the superscript form. */
        prefix?: string;
        tag?: 'span' | 'sup';
    }>(),
    { compact: false, domain: undefined, bands: undefined, prefix: undefined, tag: 'span' },
);

const name = computed(() => (props.reading === null ? undefined : titleOf(props.reading, props.domain)));

const other = computed(() => isOtherCriteria(props.reading));

// A reading on earlier criteria is on ANOTHER scale (or on other weights): the
// bands of today's scale mean nothing for it, so it stays neutral rather than
// borrowing a green or a red it did not earn.
const tone = computed(() => {
    if (props.reading === null || props.bands === undefined) {
        return undefined;
    }

    if (other.value) {
        return qualitativeToneClasses.neutral;
    }

    return props.reading.sequence === null ? undefined : qualitativeToneClasses[qualitativeToneFor({ sequence: props.reading.sequence, is_negative: props.reading.is_negative }, props.bands)];
});

const text = computed(() => (props.reading === null ? '' : props.compact ? displayOf(props.reading) : fullText(props.reading)));
</script>

<template>
    <component :is="tag" v-if="reading" :class="tone" :title="name" :aria-label="name" data-test="self-assessment-value"
        ><template v-if="prefix">{{ prefix }}</template
        >{{ text }}<span v-if="compact && other" aria-hidden="true" class="text-[0.85em] font-normal">{{ ` ${EARLIER_MARK}` }}</span></component
    >
</template>
