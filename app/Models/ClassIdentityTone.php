<?php

namespace App\Models;

/**
 * A cor por que um professor reconhece uma turma — a mesma no cartão da
 * semana, na vista da turma e no horário.
 *
 * A ORDEM DOS CASOS É A ORDEM DA PALETA: é ela que desempata quando duas cores
 * estão igualmente pouco usadas (ver ClassIdentityTones). Mexer na ordem muda
 * a cor das turmas que vierem a ser atribuídas; as já atribuídas ficam como
 * estão, porque o tom é gravado em `class_teachers.identity_tone`.
 */
enum ClassIdentityTone: string
{
    case Blue = 'blue';
    case Emerald = 'emerald';
    case Violet = 'violet';
    case Amber = 'amber';
    case Rose = 'rose';
    case Stone = 'stone';
}
