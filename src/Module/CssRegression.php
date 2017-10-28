<?php
namespace Vaimo\CodeceptionCssRegression\Module;

use Codeception\Exception\ElementNotFound;
use Codeception\Exception\ModuleException;
use Codeception\Module;
use Codeception\Module\WebDriver;
use Codeception\Step;
use Codeception\TestCase;
use Codeception\Util\FileSystem;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Vaimo\CodeceptionCssRegression\Util\FileSystem as RegressionFileSystem;

/**
 * ## Configuration
 * - maxDifference: float - the maximum difference between 2 images in percentages
 * - automaticCleanup: bool - defines if the fail image folder should be cleaned up before a new test run is started.
 * - referenceImageDirectory: string - defines the folder where the reference images should be stored
 * - failImageDirectory: string - defines the folder where the fail images should be stored
 * - fullScreenshots: bool - crop the screenshot using the absolute element coordinates or relative to current viewport
 *   (set false to use, for example with chromedriver)
 * - module: string - defines the module where the WebDriver is getted, by default WebDriver but you can set any
 *   other module that extends WebDriver, like AngularJS
 * - widthOffset: int - defines different browser viewport width between OS, for example on Mac a screen width of 1300px
 *   is actually 1300px of viewport, but using xvfb and chrome 1300px is 1285px of viewport
 */
class CssRegression extends Module
{
    /**
     * @var \Codeception\Lib\Console\Output
     */
    protected $logger;

    /**
     * @var \Vaimo\CodeceptionCssRegression\Util\Runtime
     */
    protected $runtimeUtils;
    
    /**
     * @var WebDriver
     */
    protected $webDriver = null;

    /**
     * @var array
     */
    protected $requiredFields = ['referenceImageDirectory', 'failImageDirectory'];

    /**
     * @var array
     */
    protected $config = [
        'maxDifference' => 0.01,
        'automaticCleanup' => true,
        'fullScreenshots' => true,
        'module' => 'WebDriver',
        'widthOffset' => 0,
        'diffColor' => 'BF00FF'
    ];

    /**
     * @var string
     */
    protected $suitePath = '';

    /**
     * @var int Timestamp when the suite was initialized
     */
    protected static $moduleInitTime = 0;

    /**
     * @var TestCase
     */
    protected $currentTestCase;

    /**
     * @var RegressionFileSystem
     */
    protected $moduleFileSystemUtil;

    /**
     * Elements that have been hidden for the current suite
     *
     * @var array
     */
    protected $hiddenSuiteElements;

    /**
     * @var \Grafika\Gd\Editor
     */
    private $imageEditor;

    /**
     * @var array
     */
    private $tmpImagePaths = [];

    /**
     * @var int
     */
    private $captureCounter = 0;
    
    public function _initialize()
    {
        $this->imageEditor = new \Grafika\Gd\Editor();

        $this->logger = new \Codeception\Lib\Console\Output([]);
        $this->runtimeUtils = new \Vaimo\CodeceptionCssRegression\Util\Runtime();

        $this->moduleFileSystemUtil = new RegressionFileSystem($this);

        if (self::$moduleInitTime === 0) {
            self::$moduleInitTime = time();

            if ($this->config['automaticCleanup'] === true && is_dir($this->moduleFileSystemUtil->getFailImageDirectory())) {
                // cleanup fail image directory
                FileSystem::doEmptyDir($this->moduleFileSystemUtil->getFailImageDirectory());
            }
        }
    }

    /**
     * @param array $settings
     */
    public function _beforeSuite($settings = [])
    {
        $this->suitePath = $settings['path'];
        $this->hiddenSuiteElements = array();
    }

    public function _before(TestCase $test)
    {
        $this->currentTestCase = $test;
        $this->webDriver = $this->getModule($this->config['module']);
    }

    public function _afterStep(Step $step)
    {
        if ($step->getAction() !== 'dontSeeDifferencesWithReferenceImage' || !$this->config['automaticCleanup']) {
            return;
        }

        foreach ($this->tmpImagePaths as $imagePath) {
            if (!file_exists($imagePath)) {
                continue;
            }

            @unlink($imagePath);
        }

        $this->tmpImagePaths = [];
    }
    
    /**
     * Checks if there are any visual changes to the page when compared to previously 
     * captured reference image.   
     *
     * @param string $identifier
     * @param string $selector
     * @throws ModuleException
     */
    public function dontSeeDifferencesWithReferenceImage($selector = 'body', $identifier = null)
    {
        if (!$identifier) {
            $identifier = 'capture_' . str_pad(++$this->captureCounter, 3, '0', STR_PAD_LEFT);
        }
        
        $elements = $this->webDriver->_findElements($selector);

        if (count($elements) == 0) {
            throw new ElementNotFound($selector);
        } elseif (count($elements) > 1) {
            throw new ModuleException(
                __CLASS__,
                sprintf(
                    'Multiple elements found for given selector "%s" but need exactly one element!',
                    $selector
                )
            );
        }
        
        /** @var RemoteWebElement $element */
        $imagePath = $this->_captureImage($identifier, reset($elements));

        $windowSizeString = $this->moduleFileSystemUtil->getCurrentWindowSizeString($this->webDriver);

        $imageName = $identifier . '---' . $windowSizeString;
        $contextPath = $this->runtimeUtils->getContextPath($this->currentTestCase);
        
        $referenceImagePath = $this->moduleFileSystemUtil->getReferenceImagePath($imageName, $contextPath); 

        if (!file_exists($referenceImagePath)) {
            $this->logger->writeln(
                sprintf(
                    '~ <comment>Generating reference image "%s" ...</comment>',
                    $identifier
                )
            );
            
            $this->moduleFileSystemUtil->createDirectoryRecursive(
                dirname($referenceImagePath)
            );
            
            copy($imagePath, $referenceImagePath);
        } else {
            $image1 = new \Undemanding\Difference\Image($referenceImagePath);
            $image2 = new \Undemanding\Difference\Image($imagePath);

            $difference = $image1->difference(
                $image2,
                new \Undemanding\Difference\Method\EuclideanDistance()
            );

            $percentage = round($difference->percentage(), 2);

            $messageTag = $percentage > $this->config['maxDifference'] ? 'error' : 'info';

            if ($percentage) {
                $this->logger->writeln(
                    sprintf(
                        '<%s>Visual difference detected for "%s": %s%%</%s>',
                        $messageTag,
                        $identifier,
                        $percentage,
                        $messageTag
                    )
                );
            }

            if ($percentage > $this->config['maxDifference']) {
                $connectedImages = new \Undemanding\Difference\ConnectedDifferences($difference);
                $diffAreas = $connectedImages->withJoinedBoundaries()->boundaries();

                /**
                 * Merge images
                 */
                $failImagePath = $this->moduleFileSystemUtil->getFailImagePath($imageName, $contextPath, 'fail');
                $diffImagePath = $this->moduleFileSystemUtil->getFailImagePath($imageName, $contextPath, 'diff');

                $this->moduleFileSystemUtil->createDirectoryRecursive(dirname($failImagePath));
                $this->moduleFileSystemUtil->createDirectoryRecursive(dirname($diffImagePath));

                copy($imagePath, $failImagePath);

                $image1 = \Grafika\Gd\Image::createFromFile($imagePath);
                $image2 = \Grafika\Gd\Image::createFromFile($referenceImagePath);

                $this->imageEditor->blend($image1, $image2, 'normal', 0.7);

                /**
                 * Draw out differences
                 */
                $handle = $image1->getCore();

                list($r, $g, $b) = sscanf($this->config['diffColor'], "%02x%02x%02x");
                $overlayColor = imagecolorallocatealpha($handle, $r, $g, $b, 100);

                $polygonPointMap = ['left', 'top', 'right', 'top', 'right', 'bottom', 'left', 'bottom'];

                foreach ($diffAreas as $boundary) {
                    imagefilledpolygon(
                        $handle,
                        array_map(function ($key) use ($boundary) {
                            return $boundary[$key];
                        }, $polygonPointMap),
                        count($polygonPointMap) / 2,
                        $overlayColor
                    );
                }

                $this->imageEditor->save($image1, $diffImagePath);

                imagedestroy($image1->getCore());
                imagedestroy($image2->getCore());

                $this->fail(
                    sprintf('Page content for "%s" differs from reference image', $selector)
                );
            }
        }
    }

    /**
     * @param string $selector
     */
    public function hideElements($selector)
    {
        $selectedElements = $this->webDriver->_findElements($selector);

        foreach ($selectedElements as $element) {
            $elementVisibility = $element->getCSSValue('visibility');

            if ($elementVisibility != 'hidden') {
                $this->hiddenSuiteElements[$element->getID()] = array(
                    'visibilityBackup' => $elementVisibility,
                    'element' => $element
                );
                $this->webDriver->webDriver->executeScript(
                    'arguments[0].style.visibility = \'hidden\';',
                    array($element)
                );
            }
        }
    }

    /**
     * @param string|null $selector
     */
    public function unhideElements($selector = null)
    {
        if ($selector === null) {
            foreach ($this->hiddenSuiteElements as $elementData) {
                $this->webDriver->webDriver->executeScript(
                    'arguments[0].style.visibility = \'' . $elementData['visibilityBackup'] . '\';',
                    array($elementData['element'])
                );
            }

            $this->hiddenSuiteElements = array();
        } else {
            $elements = $this->webDriver->_findElements($selector);
            foreach ($elements as $element) {
                if (isset($this->hiddenSuiteElements[$element->getID()])) {
                    $visibility = $this->hiddenSuiteElements[$element->getID()]['visibilityBackup'];
                    unset($this->hiddenSuiteElements[$element->getID()]);
                } else {
                    $visibility = 'visible';
                }
                $this->webDriver->webDriver->executeScript(
                    'arguments[0].style.visibility = \'' . $visibility . '\';',
                    array($element)
                );
            }
        }
    }

    /**
     * @param string $referenceImageName
     * @param RemoteWebElement $element
     * @return string
     */
    protected function _captureImage($referenceImageName, RemoteWebElement $element)
    {
        if (!$this->config['fullScreenshots']) {
            $element->getLocationOnScreenOnceScrolledIntoView();
        }

        $tempImagePath = $this->moduleFileSystemUtil->getTempImagePath($referenceImageName);

        $this->moduleFileSystemUtil->createDirectoryRecursive(dirname($tempImagePath));

        $this->webDriver->webDriver->takeScreenshot($tempImagePath);

        $image = imagecreatefrompng($tempImagePath);

        $elementCoordinates = $element->getCoordinates();

        if ($this->config['fullScreenshots']) {
            $elementPosition = $elementCoordinates->onPage();
        } else {
            $elementPosition = $elementCoordinates->inViewPort();
        }

        $elementSize = $element->getSize();

        $croppedImage = imagecrop($image, [
            'x' => $elementPosition->getX(),
            'y' => $elementPosition->getY(),
            'width' => $elementSize->getWidth(),
            'height' => $elementSize->getHeight()
        ]);

        imagepng($croppedImage, $tempImagePath);
        imagedestroy($croppedImage);

        $this->tmpImagePaths[] = $tempImagePath;

        return $tempImagePath;
    }

    /**
     * @return null|TestCase
     */
    public function _getCurrentTestCase()
    {
        if ($this->currentTestCase instanceof TestCase) {
            return $this->currentTestCase;
        }
        return null;
    }

    /**
     * @return int timestamp
     */
    public function _getModuleInitTime()
    {
        return self::$moduleInitTime;
    }

    /**
     * @return string
     */
    public function _getSuitePath()
    {
        return $this->suitePath;
    }

    public function _getWebdriver()
    {
        return $this->webDriver;
    }
}
