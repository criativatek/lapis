<?php

namespace App\Models;

/**
 * De onde veio uma aula — a pergunta que `recurring_lesson_slot_id`, sozinha,
 * deixou de poder responder: a FK é `nullOnDelete`, pelo que uma aula nascida
 * do horário pode acabar com ela a NULL sem nunca ter sido manual.
 *
 * Ver 2026_11_13_000100_add_origin_to_lessons_table.
 */
enum LessonOrigin: string
{
    /** Nasceu de um tempo do horário, mesmo que já não aponte para nenhum. */
    case Schedule = 'schedule';

    /** Nunca teve um tempo do horário a produzi-la. */
    case Manual = 'manual';
}
