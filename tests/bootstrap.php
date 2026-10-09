<?php

require __DIR__.'/vendor/autoload.php';

if ($source = getenv('NOVA_SOURCE')) {
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'Laravel\\Nova\\';
        if (str_starts_with($class, $prefix)) {
            $file = $source.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
} else {
    require __DIR__.'/fixtures/nova-contracts.php';
}
