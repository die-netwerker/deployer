<?php

namespace Deployer;

require 'recipe/typo3.php';

add('recipes', ['webprofil']);

set('typo3_webroot', 'public');

add('shared_files', ['.env']);
// Hosts
try {
    import('.hosts.yaml');
} catch (Exception\Exception $e) {
}

// Hooks
after('deploy:failed', 'deploy:unlock');

desc('Deploys a webprofil project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'database:updateschema',
    'language:update',
    'cache:flush',
    'cache:warmup',
    'deploy:publish',
]);

task('language:update', function () {
    $path = test('[ -d {{release_path}} ]') ? get('release_path') : get('current_path');
    run('cd ' . $path . ' && {{bin/php}} vendor/bin/typo3 language:update');
});
task('database:updateschema', function () {
    $path = test('[ -d {{release_path}} ]') ? get('release_path') : get('current_path');
    run('cd ' . $path . ' && {{bin/php}} vendor/bin/typo3 database:updateschema safe');
});
task('cache:flush', function () {
    runLocally('./vendor/bin/typo3 cache:flush');
});
task('cache:warmup', function () {
    runLocally('./vendor/bin/typo3 cache:warmup');
});
task('database:pull', function () {
    writeln('Creating DB dump on remote server');
    run("cd {{current_path}} && {{bin/php}} vendor/bin/typo3 database:export -c Default -e 'cf_*' -e 'cache_*' -e '[bf]e_sessions' -e sys_log > dump.sql");

    // get the dump from the server
    writeln('Downloading DB dump from remote server');
    download('{{current_path}}/dump.sql', 'dump.sql');

    // remove the dump from the server
    writeln('Removing DB dump from remote server');
    run('rm {{current_path}}/dump.sql');

    // import the dump to the local database
    writeln('Importing DB dump to local database');
    runLocally('cat dump.sql | ./vendor/bin/typo3 database:import');

    // remove the dump from the local machine
    writeln('Removing DB dump from local machine');
    runLocally('rm dump.sql');

    invoke("database:updateschema");
    invoke("language:update");
    invoke("cache:flush");

    writeln('DB dump imported');
});

// task to send public ssh key to remote server, if not already done
task('ssh:sendkey', function () {
    $key = runLocally('cat ~/.ssh/id_rsa.pub');
    $authorized_keys = run('cat ~/.ssh/authorized_keys');
    if (strpos($authorized_keys, $key) === false) {
        runLocally('ssh-copy-id -i ~/.ssh/id_rsa.pub ' . get('remote_user') . '@' . get('hostname'));
    }
});