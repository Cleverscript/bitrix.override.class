<?php
/**
 * Перегрузка классов
 */
$classDirectoryPath = __DIR__ . '/../classes';

/**
 * Конфигуратор переопределяемых классов
 * 'CCrmDeal' => [
 * 		'classPath' => __DIR__ . '/../../bitrix/modules/crm/classes/mysql/crm_deal.php',
 * 		'overrideClass' => '\Mib\Override\CCrmDeal'
 * ],
 * 'Bitrix\Crm\DealTable' => [
 * 		'classPath' => __DIR__ . '/../../bitrix/modules/crm/lib/deal.php',
 * 		'overrideClass' => '\Mib\Override\DealTable'
 * ]
 */
$config = [
    'CTaskItem' => [
        'classPath' => __DIR__ . '/../../bitrix/modules/tasks/classes/general/taskitem.php',
        'overrideClass' => 'Override\CTaskItem',
    ],
];

/**
 * Регистрация автозагрузчика
 */
spl_autoload_register(function ($baseClassName) use ($config, $classDirectoryPath) {
    if (!empty($config[$baseClassName])) {

        $classParts = explode('\\', $baseClassName);
        $className = array_pop($classParts);
        $namespace = implode('\\', array_filter($classParts));

        $virtualClassName = "___Virtual{$className}";

        $interface = 'CTaskItemInterface';
        $virtualInterfaceName = "___Virtual{$interface}";

        if (file_exists($config[$baseClassName]['classPath'])) {
            $classContent = file_get_contents($config[$baseClassName]['classPath']);
            $classContent = preg_replace('#^<\?(?:php)?\s*#', '', $classContent);
            $classContent = str_replace("final class {$className}", "class {$virtualClassName}", $classContent);

            $classContent = str_replace("interface {$interface}", "interface {$virtualInterfaceName}", $classContent);
            $classContent = str_replace("implements {$interface}", "implements {$virtualInterfaceName}", $classContent);

            if(!empty($config[$baseClassName]['replace'])) {
                foreach ($config[$baseClassName]['replace'] as $from => $to) {
                    $classContent = str_replace($from, $to, $classContent);
                }
            }

            //log($classContent);
            eval($classContent);
        }

        $classFilePath = $classDirectoryPath . '/' . str_replace('\\', '/', $config[$baseClassName]['overrideClass']) . '.php';
        //log($classFilePath);

        if (file_exists($classFilePath)) {
            $overrideClassContent = file_get_contents($classFilePath);
            $overrideClassContent = preg_replace('#^<\?(?:php)?\s*#', '', $overrideClassContent);
            $overrideClassContent = preg_replace('#extends ([^\s]+)#', "extends {$virtualClassName}", $overrideClassContent);

            $overrideClassContent = preg_replace('#implements ([^\s]+)#', "implements {$virtualInterfaceName}, ArrayAccess", $overrideClassContent);

            $overrideClassContent = preg_replace('#namespace ([^\s]+);#',
                $namespace ? "namespace {$namespace};" : "",
                $overrideClassContent);

            //log($overrideClassContent);
            eval($overrideClassContent);
            return;
        }
    }
}, true, true);

function log($data='',$logFileName="pLog.log")
{
    $path = $_SERVER["DOCUMENT_ROOT"] . "/local/log/";
    \CheckDirPath($path);
    $fp = fopen($path . "/" . $logFileName, "a");
    fwrite($fp, "=================================\r\n" . date('d.m.Y H:i:s') . "\r\n" . print_r($data,true) . "\r\n");
    fclose($fp);
}