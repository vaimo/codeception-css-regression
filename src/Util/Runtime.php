<?php
namespace Vaimo\CodeceptionCssRegression\Util;

class Runtime
{
    public function getContextPath(\Codeception\TestCase $testCase)
    {
        $testsRoot = exec(
            sprintf('cd %s && pwd', \Codeception\Configuration::testsDir())
        );

        $trace = debug_backtrace();

        foreach ($trace as $item) {
            if (!isset($item['file'])) {
                continue;
            }

            if (strpos($item['file'], $testsRoot) === 0) {
                $filename = basename($item['file'], '.php');

                if (substr($filename, -4) == 'Cest') {
                    $contextPath = dirname(substr($item['file'], strlen($testsRoot)))
                        . DIRECTORY_SEPARATOR
                        . $filename
                        . DIRECTORY_SEPARATOR;

                    return trim($contextPath, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                        . ucfirst($testCase->getScenario()->current('name'));
                }
            }
        }

        throw new \PHPUnit_Framework_Exception('Could not resolve caller');
    }
}
