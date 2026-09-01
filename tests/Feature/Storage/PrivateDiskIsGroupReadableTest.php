<?php

namespace Tests\Feature\Storage;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O disco privado tem de ser legível pelo grupo da aplicação.
 *
 * Em produção quem escreve em `storage/app/private` é o php-fpm e quem o limpa
 * é o scheduler, dois utilizadores diferentes do mesmo grupo. Com a predefinição
 * do Flysystem — `0700` nos diretórios, `0600` nos ficheiros — o segundo apanha
 * `UnableToListContents` numa pasta da sua própria aplicação. Foi o que matou o
 * `data-imports:prune` de hora a hora durante 22 horas a 2026-08-30, e é a razão
 * de `PrunesPrivateStorage` existir.
 *
 * A asserção é sobre a configuração e não sobre um `stat` do disco de propósito:
 * o modo real de um ficheiro depende também do `umask` do processo e não existe
 * de todo em Windows, onde metade do desenvolvimento acontece. O que este teste
 * fixa é a intenção declarada, que é a parte que alguém pode apagar sem reparar.
 */
class PrivateDiskIsGroupReadableTest extends TestCase
{
    #[Test]
    public function a_private_directory_is_created_readable_by_the_group(): void
    {
        $mode = config('filesystems.disks.local.permissions.dir.private');

        $this->assertIsInt($mode, 'O disco privado não declara permissões de diretório.');

        // Ler, escrever e atravessar: sem o bit de execução o grupo não lista.
        $this->assertSame(
            0070,
            $mode & 0070,
            'O grupo tem de poder ler, escrever e atravessar os diretórios privados, '
            .'ou o scheduler não consegue limpar o que o php-fpm escreveu.',
        );
    }

    #[Test]
    public function a_private_file_is_created_readable_by_the_group(): void
    {
        $mode = config('filesystems.disks.local.permissions.file.private');

        $this->assertIsInt($mode, 'O disco privado não declara permissões de ficheiro.');

        $this->assertSame(0060, $mode & 0060);
    }

    #[Test]
    public function private_never_means_readable_by_everybody(): void
    {
        // A correção do grupo não pode ter alargado o que «privado» quer dizer:
        // os dados aqui dentro são de alunos, a maioria menores.
        foreach (['dir', 'file'] as $kind) {
            $mode = config("filesystems.disks.local.permissions.{$kind}.private");

            $this->assertSame(0, $mode & 0007, "Permissões de {$kind} privadas abertas a `other`.");
        }
    }
}
