<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,

            // O GRUPO TEM DE PODER LER, OU A LIMPEZA NÃO ACONTECE.
            //
            // Sem este bloco o Flysystem cria os diretórios privados a `0700` e
            // os ficheiros a `0600`: legíveis só por quem os escreveu. Em
            // produção quem escreve é o php-fpm e quem limpa é o scheduler, dois
            // utilizadores diferentes do mesmo grupo — e o segundo passa a
            // apanhar `UnableToListContents` numa pasta da sua própria
            // aplicação. Já aconteceu: a 2026-08-30 matou o `data-imports:prune`
            // de hora a hora durante 22 horas, e `PrunesPrivateStorage` existe
            // por causa disso. O trait trata o sintoma — falhar sem ser fatal e
            // sem mentir que correu bem; isto trata a causa.
            //
            // `other` continua a zero: privado quer dizer privado. O que muda é
            // só o grupo da aplicação poder ler e arrumar o que a aplicação
            // escreveu.
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0660],
                'dir' => ['public' => 0755, 'private' => 0770],
            ],
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
