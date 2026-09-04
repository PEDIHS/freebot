<?php
declare(strict_types=1);
$script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/index.php'));
$dir=rtrim(str_replace('\\','/',dirname($script)),'/');
$target=is_file(__DIR__.'/config.php')?'admin.php':'install.php';
header('Location: '.($dir===''?'':$dir).'/'.$target,true,302);
exit;
