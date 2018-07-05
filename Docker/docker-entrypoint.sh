#!/usr/bin/env bash

echo Starting server

set -u
set -e

DB_HOST=${TOURBUZZ__DATABASE_HOST:-tourbuzz-db.service.consul}
DB_PORT=${TOURBUZZ__DATABASE_PORT:-5432}
DB_NAME=${TOURBUZZ__DATABASE_NAME:-tourbuzz}
DB_USER=${TOURBUZZ__DATABASE_USER:-tourbuzz}
DB_PASSWORD=${TOURBUZZ__DATABASE_PASSWORD:-insecure}
MESSAGEBIRD_API_KEY=${TOURBUZZ__MESSAGEBIRD_API_KEY:-placeholder}
TRANSLATE_API_KEY=${TOURBUZZ__TRANSLATE_API_KEY:-placeholder}
SENDGRID_API_KEY=${TOURBUZZ__SENDGRID_API_KEY:-placeholder}

cat > /srv/web/tourbuzz-api/src/settings.php <<EOF
<?php
return [
    'settings' => [
        'displayErrorDetails' => true, // set to false in production
        'addContentLengthHeader' => true, // Allow the web server to send the content-length header

        // Renderer settings
        'renderer' => [
            'template_path' => __DIR__ . '/../templates/',
        ],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => __DIR__ . '/../logs/app.log',
            'level' => \Monolog\Logger::DEBUG,
        ],
        'doctrine' => [
            'meta' => [
                'entity_path' => [
                    'src/entity'
                ],
                'auto_generate_proxies' => true,
                'proxy_dir' =>  __DIR__.'/../cache/proxies',
                'cache' => null,
            ],
            'connection' => [
                'driver'   => 'pdo_pgsql',
                'port'     => '${DB_PORT}',
                'host'     => '${DB_HOST}',
                'dbname'   => '${DB_NAME}',
                'user'     => '${DB_USER}',
                'password' => '${DB_PASSWORD}',
            ]
        ],
        'haltesUrl'              => 'http://open.data.amsterdam.nl/ivv/touringcar/in_uitstaphaltes.json',
        'messagesUrl'            => 'http://tourapi.b.nl/berichten/' . date("Y/m/d"),
        'parkeerUrl'             => 'http://open.data.amsterdam.nl/ivv/touringcar/parkeerplaatsen.json',
        'wachtwoordVergetenUrl'  => 'http://tour.b.nl/wachtwoordvergeten/',
        'mailConfirmUrl'         => 'http://tour.b.nl/mailbevestigen/',
        'mailUnsubscribeUrl'     => 'http://tour.b.nl/mailannuleren/',
        'imageStoreRootPath'     => 'images/',
        'imageStoreExternalPath' => 'http://tourapi.b.nl/images/',
        'imageResizeUrl'         => 'http://tourapi.b.nl/afbeeldingen/',
        'translateApiKey'        => '${TRANSLATE_API_KEY}',
        'fromMail'               => 'noreply@tourbuzz.nl',
        'sendgridApiKey'         => '${SENDGRID_API_KEY}',
        'messagebirdApiKey'      => '${MESSAGEBIRD_API_KEY}'
    ]
];
EOF

#php tourbuzz-api/bin/console assetic:dump --env=prod
#php tourbuzz-api/bin/console assets:install
#php tourbuzz-api/bin/console cache:clear --env=prod
#php /srv/web/tourbuzz-api/vendor/bin/doctrine-migrations mig:mig --configuration=/srv/web/tourbuzz-api/migrations.yml

# Again, just to be sure
#chown -R www-data:www-data /srv/web/tourbuzz-api/var && chmod -R 0770 /srv/web/tourbuzz-api/var

service php7.0-fpm start
nginx -g "daemon off;"