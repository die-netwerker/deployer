<?php

namespace Deployer;

use RuntimeException;

require 'recipe/typo3.php';

add('recipes', ['mittwald']);

set('typo3_webroot', 'public');
set('keep_releases', '3');
set('writable_mode', 'chmod');
set('shared_files', ['.env']);

// Hosts
try {
    // load local hosts file if exists
    if (file_exists('.hosts.yaml')) {
        import('.hosts.yaml');
    }
} catch (Exception\Exception $e) {
}

// Hooks
after('deploy:failed', 'deploy:unlock');

desc('Deploys a netwerk project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'database:updateschema',
    'language:update',
    'cache:flush:typo3',
    'cache:warmup',
    'deploy:publish',
]);

// after symlink is set, flush caches
after('deploy:symlink', 'cache:flush:opcache');
after('deploy:symlink', 'cache:flush:apcu');
after('deploy:symlink', 'cache:flush:stat');

/**
 * Updates the language files on the remote server
 */
task('language:update', function () {
    run('cd {{release_or_current_path}} && {{bin/php}} vendor/bin/typo3 language:update');
});

/**
 * Updates the database schema on the remote server, does not delete any data
 */
task('database:updateschema', function () {
    run('cd {{release_or_current_path}} && {{bin/php}} vendor/bin/typo3 database:updateschema safe');
});

/**
 * Flushes the typo3 cache on the remote server
 */
task('cache:flush:typo3', function () {
    run('cd {{release_or_current_path}} && {{bin/php}} vendor/bin/typo3 cache:flush');
});

/**
 * Flushes the apcu cache on the remote server, if installed
 */
task('cache:flush:apcu', function () {
    $command = 'cd {{release_or_current_path}} && {{bin/php}} vendor/bin/cachetool apcu:cache:clear --cli || echo "flush_failed:$?"';
    $result = run($command);
    if (strpos($result, 'flush_failed:') !== false) {
        writeln('Failed to flush APCUcache. Continuing with deployment...');
    }
});

/**
 * Flushes the op cache on the remote server, if installed
 */
task('cache:flush:opcache', function () {
    $command = 'cd {{release_or_current_path}} && {{bin/php}} vendor/bin/cachetool opcache:reset --cli || echo "flush_failed:$?"';
    $result = run($command);
    if (strpos($result, 'flush_failed:') !== false) {
        writeln('Failed to flush OPcache. Continuing with deployment...');
    }
});

/**
 * Flushes the file status cache on the remote server, if installed
 */
task('cache:flush:stat', function () {
    $command = 'cd {{release_or_current_path}} && {{bin/php}} vendor/bin/cachetool stat:clear --cli || echo "flush_failed:$?"';
    $result = run($command);
    if (strpos($result, 'flush_failed:') !== false) {
        writeln('Failed to flush file status cache. Continuing with deployment...');
    }
});

/**
 * Warms up the cache on the remote server
 */
task('cache:warmup', function () {
    run('cd {{release_or_current_path}} && {{bin/php}} vendor/bin/typo3 cache:warmup');
});

/**
 * creates a directory for the database dumps, which is ignored by git
 */
task('database:createDatabaseDir', function () {
    runLocally('mkdir -p database');
    $hosts = Deployer::get()->hosts;
    // create folder for each host
    foreach ($hosts as $host => $config) {
        runLocally('mkdir -p database/' . $host);
    }

    // create one subdirectory for local machine
    runLocally('mkdir -p database/local');

    // create gitignore file in database folder to ignore all its content
    runLocally('echo "*" > database/.gitignore');
});

/**
 * Task to pull the database from the remote server to the local machine
 */
task('database:pull', function () {
    invoke('database:createDatabaseDir');

    // making backup of the local database
    writeln('Creating DB dump on local server');
    runLocally("typo3 database:export -c Default -e 'cf_*' -e 'cache_*' -e '[bf]e_sessions' -e sys_log > local.sql");

    // gzip the dump.sql
    writeln('Gzipping DB dump');
    runLocally('gzip local.sql');

    // name the dump with the current date and time
    writeln('Renaming DB dump');
    runLocally('mv local.sql.gz database/local/`date +%Y-%m-%d-%H-%M-%S`.sql.gz');

    // download dump from the remote server
    invoke('database:download');

    // import the dump to the local database
    writeln('Importing DB dump to local database');
    runLocally('cat dump.sql | ./vendor/bin/typo3 database:import');

    // remove the dump from the local machine
    writeln('Removing DB dump from local machine');
    runLocally('rm dump.sql');

    writeln('typo3 database:updateschema safe');
    runLocally('./vendor/bin/typo3 database:updateschema safe');
    writeln('typo3 language:update');
    runLocally('./vendor/bin/typo3 language:update');
    writeln('typo3 cache:flush');
    runLocally("./vendor/bin/typo3 cache:flush");

    writeln('DB dump imported');
});

/**
 * downloads a database dump from the remote server
 */
task('database:download', function () {
    writeln('Creating DB dump on remote server');
    run("cd {{release_or_current_path}} && {{bin/php}} vendor/bin/typo3 database:export -c Default -e 'cf_*' -e 'cache_*' -e '[bf]e_sessions' -e sys_log > dump.sql");

    // get the dump from the server
    writeln('Downloading DB dump from remote server');
    download('{{release_or_current_path}}/dump.sql', 'dump.sql');

    // remove the dump from the server
    writeln('Removing DB dump from remote server');
    run('rm {{release_or_current_path}}/dump.sql');
});

/**
 * Task to push the database from the local machine to the remote server
 */
task('database:push', function () {
    // ask for confirmation
    if (!askConfirmation('DANGER: Do you really want to push your local database to the remote server? This might break your remote installation!')) {
        return;
    }

    warning('#######################################');
    warning('############### WARNING ###############');
    warning('#######################################');
    writeln("\n");
    warning('You are about to push your local database to the remote server');
    warning('This will overwrite the remote database with your local database');
    writeln("\n");

    $enteredHost = ask('Please enter the hostname of the remote server to confirm');
    if ($enteredHost !== get('hostname')) {
        writeln('Hostname does not match. Aborting');

        return;
    }

    invoke('database:createDatabaseDir');

    writeln('creating a backup of the remote database in the database folder, just in case');
    invoke('database:download');
    runLocally('gzip dump.sql');
    runLocally('mv dump.sql.gz database/' . get('hostname') . '/`date +%Y-%m-%d-%H-%M-%S`.sql.gz');

    writeln('Creating DB dump on local server');
    runLocally("typo3 database:export -c Default -e 'cf_*' -e 'cache_*' -e '[bf]e_sessions' -e sys_log > local.sql");

    // get the dump from the server
    writeln('Uploading DB dump to remote server');
    upload('local.sql', '{{release_or_current_path}}/local.sql');

    // remove the dump from the server
    writeln('Removing DB dump from local server');
    runLocally('rm local.sql');

    // import the dump to the local database
    writeln('Importing DB dump to remote database');
    run("cd {{release_or_current_path}} && cat local.sql | {{bin/php}} vendor/bin/typo3 database:import");

    // remove the dump from the remote machine
    writeln('Removing DB dump from remote machine');
    run('rm {{release_or_current_path}}/local.sql');

    invoke('database:updateschema');
    invoke('language:update');
    invoke('cache:flush:typo3');
    invoke('cache:warmup');

    writeln('DB pushed to ' . get('hostname'));
});

/**
 * Task to generate PhpStorm SSH config
 */
task('ssh:phpstorm', function () {
    $hosts = Deployer::get()->hosts;


    $deploment = '<?xml version="1.0" encoding="UTF-8"?>
<project version="4">';

    $sshConfigs = '<?xml version="1.0" encoding="UTF-8"?>
<project version="4">
  <component name="SshConfigs">
    <configs>';

    $webServers = '<?xml version="1.0" encoding="UTF-8"?>
<project version="4">
    <component name="WebServers">
        <option name="servers">';
    /** @var Host\Host $host */
    foreach ($hosts as $host) {
        $serverId = bin2hex(random_bytes(16));
        $sshConfigId = bin2hex(random_bytes(16));

        $webServers .= '<webServer id="' . $serverId . '" name="' . $host->getAlias() . ' - generated">
                <fileTransfer rootFolder="' . $host->getDeployPath() . '/current"
                              accessType="SFTP"
                              host="' . $host->getHostname() . '"
                              port="22"
                              sshConfigId="' . $sshConfigId . '"
                              sshConfig="' . $host->getRemoteUser() . '@' . $host->getHostname() . ':22 agent"
                              authAgent="true">
                    <advancedOptions>
                        <advancedOptions dataProtectionLevel="Private"
                                         keepAliveTimeout="0"
                                         passiveMode="true"
                                         shareSSLContext="true"/>
                    </advancedOptions>
                </fileTransfer>
            </webServer>
           ';

        $sshConfigs .= '<sshConfig authType="OPEN_SSH" host="' . $host->getHostname() . '" id="' . $sshConfigId . '" port="22" nameFormat="DESCRIPTIVE" username="' . $host->getRemoteUser() . '" />';
        $deploment .= '<component name="PublishConfigData" remoteFilesAllowedToDisappearOnAutoupload="false">
    <serverData>
      <paths name="' . $host->getAlias() . ' - generated">
        <serverdata>
          <mappings>
            <mapping deploy="/" local="$PROJECT_DIR$" web="/" />
          </mappings>
        </serverdata>
      </paths>
    </serverData>
  </component>';
    }
    $webServers .= '
        </option>
    </component>
</project>';
    $sshConfigs .= '    </configs>
  </component>
</project>';
    $deploment .= '</project>';

    // check if file exists .idea/webServers.xml
    if (!file_exists('.idea')) {
        if (!mkdir('.idea') && !is_dir('.idea')) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', '.idea'));
        }
    }
    file_put_contents('.idea/webServers.xml', $webServers);
    file_put_contents('.idea/sshConfigs.xml', $sshConfigs);
    file_put_contents('.idea/deployment.xml', $deploment);
});

/**
 * task to send public ssh key to remote server, if not already done
 */
task('ssh:sendkey', function () {
    runLocally('ssh-copy-id ' . get('remote_user') . '@' . get('hostname'));
});

/**
 * task to sync fileadmin folder from remote to local, via rsync
 */
task('fileadmin:pull', function () {
    // ask for confirmation
    if (!askConfirmation('Do you really want to pull the fileadmin folder from the remote server? This will overwrite your local fileadmin folder!')) {
        return;
    }

    $folder = 'public/fileadmin/';
    $remoteFileadmin = get('remote_user') . '@' . get('hostname') . ':' . get('deploy_path') . '/current/' . $folder;
    $localFileadmin = $folder;

    // do not sync __processed__ folder and __temp__ folder, runLocally and send output to console
    runLocally('rsync -avz ' . $remoteFileadmin . ' ' . $localFileadmin);
});

/**
 * task to execute the cli command container:sorting-in-page
 */
task('executeCommand:sorting-in-page', function () {

    if(run('cd {{release_or_current_path}} && cat composer.json | grep "b13/container" || echo "0"') === '0') {
        writeln('Container extension not found in composer.json. Aborting');
        return;
    }
    run('cd {{release_or_current_path}} && {{bin/php}} vendor/bin/typo3 container:sorting-in-page --apply');
});

/**
 * task to sync fileadmin folder from local to remote, via rsync
 */
task('fileadmin:push', function () {
    // ask for confirmation
    if (!askConfirmation('Do you really want to push the fileadmin folder from your local machine? This will overwrite the fileadmin folder on the remote server!')) {
        return;
    }

    warning('#######################################');
    warning('############### WARNING ###############');
    warning('#######################################');
    writeln("\n");
    warning('You are about to push your local fileadmin to the remote server');
    warning('This will overwrite the remote fileadmin with your local fileadmin');
    writeln("\n");

    $enteredHost = ask('Please enter the hostname of the remote server to confirm');
    if ($enteredHost !== get('hostname')) {
        writeln('Hostname does not match. Aborting');
        return;
    }


    $folder = 'public/fileadmin/';
    $remoteFileadmin = get('remote_user') . '@' . get('hostname') . ':' . get('deploy_path') . '/current/' . $folder;
    $localFileadmin = $folder;

    // do not sync __processed__ folder and __temp__ folder, runLocally and send output to console
    runLocally('rsync -avz ' . $localFileadmin . ' ' . $remoteFileadmin.' --bwlimit=2000');
});

/**
 * task to backup the current release to local machine
 */
task('project:backup', function () {


    // create one subdirectory for new backup
    set('backup_dir', 'backup');
    set('current_release_name', function () {
        return within('{{deploy_path}}', function () {
            return run('cat .dep/latest_release || echo 0');
        });
    });

    runLocally('mkdir -p {{backup_dir}}');
    runLocally('echo "*" > {{backup_dir}}/.gitignore');


    writeln('Zip current release folder');
    run("cd {{deploy_path}} && tar -czf {{current_release_name}}.tar.gz releases/{{current_release_name}}");

    writeln('Downloading current release from remote server');
    download('{{deploy_path}}/{{current_release_name}}.tar.gz', '{{backup_dir}}/{{current_release_name}}.tar.gz');

    writeln('Removing zipped current release from remote server');
    run('rm {{deploy_path}}/{{current_release_name}}.tar.gz');

    writeln('Zip shared folder');
    run("cd {{deploy_path}} && tar -czf shared.tar.gz shared");

    writeln('Downloading shared folder from remote server');
    download('{{deploy_path}}/shared.tar.gz', '{{backup_dir}}/shared.tar.gz');

    writeln('Removing zipped shared folder from remote server');
    run('rm {{deploy_path}}/shared.tar.gz');

    writeln('Creating DB dump on remote server');
    run("cd {{release_or_current_path}} && {{bin/php}} vendor/bin/typo3 database:export -c Default -e 'cf_*' -e 'cache_*' -e '[bf]e_sessions' -e sys_log > dump.sql");

    writeln('Downloading DB dump from remote server');
    download('{{release_or_current_path}}/dump.sql', '{{backup_dir}}/dump.sql');
    runLocally('gzip {{backup_dir}}/dump.sql');

    writeln('Removing DB dump from remote server');
    run('rm {{release_or_current_path}}/dump.sql');
});

task('pipeline:create', function () {

    $config = 'image: wpjw/composer_php-8.1:latest
pipelines:
  custom:
    generic:
      - variables:
          - name: command
      - step:
          name: generic pipeline
          caches:
            - composer-cache
          script:
            - /docker-entrypoint.sh $command
  branches:
    master:
      - step:
          name: Deploy to Prod Server
          caches:
            - composer-cache
          script:
            - /docker-entrypoint.sh deploy stage=prod
#    development:
#      - step:
#          name: Deploy to Dev Server
#          caches:
#            - composer-cache
#          script:
#            - /docker-entrypoint.sh deploy stage=dev
definitions:
  caches:
    composer-cache: vendor';

    file_put_contents('bitbucket-pipelines.yml', $config);
    runLocally('git add bitbucket-pipelines.yml');
});