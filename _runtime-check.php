
<?php
header('Content-Type: text/plain; charset=utf-8');

echo 'PHP_VERSION=' . PHP_VERSION . PHP_EOL;
echo 'PHP_VERSION_ID=' . PHP_VERSION_ID . PHP_EOL;
echo 'SAPI=' . PHP_SAPI . PHP_EOL;
echo 'PDO_DRIVERS=' . implode(',', PDO::getAvailableDrivers()) . PHP_EOL;
