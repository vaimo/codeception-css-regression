<?php
namespace Vaimo\CodeceptionCssRegression\Extension;

use Codeception\Event\PrintResultEvent;
use Codeception\Event\StepEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Events;
use Codeception\Module\WebDriver;

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
 */
class CssRegressionReporter extends \Codeception\Extension
{
    const DS = DIRECTORY_SEPARATOR;

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
     * @var \Vaimo\CodeceptionCssRegression\Util\FileSystem
     */
    protected $fileSystem;

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
            $this->config['templateFolder'] = __DIR__ . self::DS . '..' . self::DS . 'Templates';
        }

        $this->config['templateFolder'] = rtrim($this->config['templateFolder'], self::DS) . self::DS;

        parent::__construct($config, $options);
    }

    /**
     * @param SuiteEvent $suiteEvent
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function suiteInit(SuiteEvent $suiteEvent)
    {
        $module = $this->getModule(\Vaimo\CodeceptionCssRegression\Module\CssRegression::class);
        
        $this->fileSystem = new \Vaimo\CodeceptionCssRegression\Util\FileSystem($module, $module->_getInitTime());
    }

    /**
     * @param PrintResultEvent $printResultEvent
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function resultPrintAfter(PrintResultEvent $printResultEvent)
    {
        if (count($this->failedIdentifiers) <= 0) {
            return;
        }

        $items = '';
        $itemTemplate = new \Text_Template($this->config['templateFolder'] . 'Item.html');
        foreach ($this->failedIdentifiers as $vars) {
            $itemTemplate->setVar($vars);
            $items .= $itemTemplate->render();
        }

        $pageTemplate = new \Text_Template($this->config['templateFolder'] . 'Page.html');
        $pageTemplate->setVar(array('items' => $items));

        $reportPath = $this->fileSystem->getFailImageDirectory() . self::DS .  'index.html';

        $pageTemplate->renderTo($reportPath);

        $printResultEvent->getPrinter()->write('Report has been created: ' . $reportPath . "\n");
    }

    /**
     * @param StepEvent $stepEvent
     */
    public function stepAfter(StepEvent $stepEvent)
    {
        if (!$stepEvent->getStep()->hasFailed()) {
            return;
        }

        if (!$stepEvent->getStep()->getAction('dontSeeDifferencesWithReferenceImage')) {
            return;
        }

        /** @var WebDriver $stepWebDriver */
        $stepWebDriver = $stepEvent->getTest()->getScenario()->current('modules')['WebDriver'];
        $identifier = $stepEvent->getStep()->getArguments()[0];
        $windowSize = $this->fileSystem->getCurrentWindowSizeString($stepWebDriver);

        $imageName = $identifier . '-' . $windowSize;
        $contextPath = $this->runtimeUtils->getContextPath($stepEvent->getTest());

        $this->failedIdentifiers[] = array(
            'identifier' => $identifier,
            'windowSize' => $windowSize,
            'failImage' => base64_encode(
                file_get_contents(
                    $this->fileSystem->getFailImagePath($imageName, $contextPath, 'fail')
                )
            ),
            'diffImage' => base64_encode(
                file_get_contents(
                    $this->fileSystem->getFailImagePath($imageName, $contextPath, 'diff')
                )
            ),
            'referenceImage' => base64_encode(
                file_get_contents(
                    $this->fileSystem->getReferenceImagePath($imageName, $contextPath)
                )
            )
        );
    }
}
