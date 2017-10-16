<?php
namespace Vaimo\CodeceptionCssRegression\Extension;

use Codeception\Event\PrintResultEvent;
use Codeception\Event\StepEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Events;
use Codeception\Module\WebDriver;
use Vaimo\CodeceptionCssRegression\Module\CssRegression;
use Vaimo\CodeceptionCssRegression\Util\FileSystem;

/**
 * Generates an html file with all failed tests that contains the reference image, failed image and diff image.
 *
 * #### Installation
 *
 * Add to list of enabled extensions
 *
 * ``` yaml
 * extensions:
 *      - Vaimo\CodeceptionCssRegression\Extension\CssRegressionReporter
 * ```
 *
 * #### Configuration
 *
 * * `templateFolder` Path to the template folder that is used to generate the report. Must contain a Page.html and Item.html file.
 *
 * ``` yaml
 * extensions:
 *     config:
 *         Vaimo\CodeceptionCssRegression\Extension\CssRegressionReporter
 *             templateFolder: /my/path/to/my/templates
 * ```
 *
 */
class CssRegressionReporter extends \Codeception\Extension
{
    static $events = [
        Events::RESULT_PRINT_AFTER => 'resultPrintAfter',
        Events::STEP_AFTER => 'stepAfter',
        Events::SUITE_BEFORE => 'suiteInit'
    ];

    /**
     * @var \Vaimo\CodeceptionCssRegression\Util\Runtime
     */
    protected $runtimeUtils;

    /**
     * @var array
     */
    protected $failedIdentifiers = [];

    /**
     * @var FileSystem
     */
    protected $fileSystemUtil;

    /**
     * @var array
     */
    protected $config = [
        'templateFolder' => null
    ];

    /**
     * @param $config
     * @param $options
     */
    function __construct($config, $options)
    {
        $this->runtimeUtils = new \Vaimo\CodeceptionCssRegression\Util\Runtime();
        
        if (empty($this->config['templateFolder'])) {
            $this->config['templateFolder'] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Templates';
        }

        $this->config['templateFolder'] = rtrim($this->config['templateFolder'],
                DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        parent::__construct($config, $options);
    }

    /**
     * @param SuiteEvent $suiteEvent
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function suiteInit(SuiteEvent $suiteEvent)
    {
        /** @var CssRegression $cssRegressionModule */
        $cssRegressionModule = $this->getModule(
            \Vaimo\CodeceptionCssRegression\Module\CssRegression::class
        );
        
        $this->fileSystemUtil = new FileSystem($cssRegressionModule);
    }

    /**
     * @param PrintResultEvent $printResultEvent
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function resultPrintAfter(PrintResultEvent $printResultEvent)
    {
        if (count($this->failedIdentifiers) > 0) {
            $items = '';
            $itemTemplate = new \Text_Template($this->config['templateFolder'] . 'Item.html');
            foreach ($this->failedIdentifiers as $vars) {
                $itemTemplate->setVar($vars);
                $items .= $itemTemplate->render();
            }

            $pageTemplate = new \Text_Template($this->config['templateFolder'] . 'Page.html');
            $pageTemplate->setVar(array('items' => $items));
            $reportPath = $this->fileSystemUtil->getFailImageDirectory() . 'index.html';
            $pageTemplate->renderTo($reportPath);
            $printResultEvent->getPrinter()->write('Report has been created: ' . $reportPath . "\n");
        }
    }

    /**
     * @param StepEvent $stepEvent
     */
    public function stepAfter(StepEvent $stepEvent)
    {
        if ($stepEvent->getStep()->hasFailed() && $stepEvent->getStep()->getAction('dontSeeDifferencesWithReferenceImage')) {
            /** @var WebDriver $stepWebDriver */
            $stepWebDriver = $stepEvent->getTest()->getScenario()->current('modules')['WebDriver'];
            $identifier = $stepEvent->getStep()->getArguments()[0];
            $windowSize = $this->fileSystemUtil->getCurrentWindowSizeString($stepWebDriver);

            $imageName = $identifier . '-' . $windowSize;
            $contextPath = $this->runtimeUtils->getContextPath($stepEvent->getTest());
            
            $this->failedIdentifiers[] = array(
                'identifier' => $identifier,
                'windowSize' => $windowSize,
                'failImage' => base64_encode(
                    file_get_contents(
                        $this->fileSystemUtil->getFailImagePath($imageName, $contextPath, 'fail')
                    )
                ),
                'diffImage' => base64_encode(
                    file_get_contents(
                        $this->fileSystemUtil->getFailImagePath($imageName, $contextPath, 'diff')
                    )
                ),
                'referenceImage' => base64_encode(
                    file_get_contents(
                        $this->fileSystemUtil->getReferenceImagePath($imageName, $contextPath)
                    )
                )
            );
        }
    }
}
