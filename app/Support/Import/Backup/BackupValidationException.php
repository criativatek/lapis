<?php

namespace App\Support\Import\Backup;

use RuntimeException;

/**
 * A backup upload failed before anything was written — always safe to show
 * the message directly to the teacher (§52 of the import brief: human
 * copy, never a stack trace). Every factory here corresponds to a
 * DataImportStatus::Invalid outcome; nothing throws this after a plan has
 * started writing.
 */
class BackupValidationException extends RuntimeException
{
    public static function notAZipOrJson(): self
    {
        return new self(__('Este ficheiro não é uma exportação válida do Lapispro.'));
    }

    public static function corruptZip(): self
    {
        return new self(__('O backup está danificado.'));
    }

    public static function missingBackupEntry(): self
    {
        return new self(__('Este ficheiro não é uma exportação válida do Lapispro — não contém backup-lapis.json.'));
    }

    public static function unsafeZipEntry(): self
    {
        return new self(__('O backup está danificado.'));
    }

    public static function tooManyEntries(): self
    {
        return new self(__('O backup está danificado.'));
    }

    public static function tooLarge(): self
    {
        return new self(__('Este backup é demasiado grande para importação direta nesta versão.'));
    }

    public static function invalidJson(): self
    {
        return new self(__('O backup está danificado.'));
    }

    public static function unsupportedNewerSchema(): self
    {
        return new self(__('Este backup foi criado por uma versão do Lapispro que ainda não é suportada para restauro.'));
    }

    public static function unsupportedSchema(): self
    {
        return new self(__('Este backup foi criado por uma versão do Lapispro que ainda não é suportada para restauro.'));
    }

    public static function missingRequiredField(string $field): self
    {
        return new self(__('O backup está danificado — falta um campo obrigatório (:field).', ['field' => $field]));
    }

    public static function suspiciousContent(): self
    {
        return new self(__('Este ficheiro contém dados que nunca deveriam estar num backup do Lapispro e não pode ser importado.'));
    }
}
