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
 * Compares a screenshot of an element against a reference image
 *
 * ## Status
 *
 * * Maintainer: **Carlos Mendieta**
 * * Contact: mendicm@gmail.com
 *
 * ## Configuration
 *
 * * maxDifference: float - the maximum difference between 2 images
 * * automaticCleanup: bool - defines if the fail image folder should be cleaned up before a new test run is started.
 * * referenceImageDirectory: string - defines the folder where the reference images should be stored
 * * failImageDirectory: string - defines the folder where the fail images should be stored
 * * fullScreenshots: bool - crop the screenshot using the absolute element coordinates or relative to current viewport
 * *    (set false to use, for example with chromedriver)
 * * module: string - defines the module where the WebDriver is getted, by default WebDriver but you can set any
 * *    other module that extends WebDriver, like AngularJS
 * * widthOffset: int - defines different browser viewport width between OS, for example on Mac a screen width of 1300px
 *      is actually 1300px of viewport, but using xvfb and chrome 1300px is 1285px of viewport
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
        'maxDifference'    => 0.01,
        'automaticCleanup' => true,
        'fullScreenshots'  => true,
        'module'           => 'WebDriver',
        'widthOffset'      => 0,
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
     * Initialize the module after configuration has been loaded
     */
    public function _initialize()
    {
        $this->logger = new \Codeception\Lib\Console\Output([]);
        $this->runtimeUtils = new \Vaimo\CodeceptionCssRegression\Util\Runtime();

        if (!class_exists('\\Imagick')) {
            throw new ModuleException(__CLASS__,
                'Required class \\Imagick could not be found!
                Please install the PHP Image Magick extension to use this module.'
            );
        }

        $this->moduleFileSystemUtil = new RegressionFileSystem($this);

        if (self::$moduleInitTime === 0) {
            self::$moduleInitTime = time();

            if ($this->config['automaticCleanup'] === true && is_dir($this->moduleFileSystemUtil->getFailImageDirectory())) {
                // cleanup fail image directory
                FileSystem::doEmptyDir($this->moduleFileSystemUtil->getFailImageDirectory());
            }
        }

        $this->moduleFileSystemUtil->createDirectoryRecursive($this->moduleFileSystemUtil->getTempDirectory());
        $this->moduleFileSystemUtil->createDirectoryRecursive($this->moduleFileSystemUtil->getReferenceImageDirectory());
        $this->moduleFileSystemUtil->createDirectoryRecursive($this->moduleFileSystemUtil->getFailImageDirectory());
    }

    /**
     * Before each suite
     *
     * @param array $settings
     */
    public function _beforeSuite($settings = [])
    {
        $this->suitePath = $settings['path'];
        $this->hiddenSuiteElements = array();
    }

    /**
     * Before each scenario
     *
     * @param TestCase $test
     */
    public function _before(TestCase $test)
    {
        $this->currentTestCase = $test;
        $this->webDriver = $this->getModule($this->config['module']);
    }

    /**
     * After each step
     *
     * @param Step $step
     */
    public function _afterStep(Step $step)
    {
        if ($step->getAction() === 'seeNoDifferenceToReferenceImage' && $this->config['automaticCleanup']) {
            // cleanup the temp image
            $identifier = str_replace('"', '', explode(',', $step->getArgumentsAsString())[0]);
            if (file_exists($this->moduleFileSystemUtil->getTempImagePath($identifier))) {
                @unlink($this->moduleFileSystemUtil->getTempImagePath($identifier));
            }
        }
    }
    
    /**
     * Checks item in Memcached exists and the same as expected.
     *
     * @param string $imageIdentifier
     * @param null|string $selector
     * @throws ModuleException
     */
    public function seeNoDifferenceToReferenceImage($imageIdentifier, $selector = null)
    {
        if ($selector === null) {
            $selector = 'body';
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
        $image = $this->_createScreenshot($imageIdentifier, reset($elements));

        $windowSizeString = $this->moduleFileSystemUtil->getCurrentWindowSizeString($this->webDriver);

        $imageName = $imageIdentifier . '-' . $windowSizeString;
        $contextPath = $this->runtimeUtils->getContextPath($this->currentTestCase);
        
        $referenceImagePath = $this->moduleFileSystemUtil->getReferenceImagePath($imageName, $contextPath); 

        if (!file_exists($referenceImagePath)) {
            $this->logger->writeln(
                sprintf(
                    '~ <comment>Generating reference image "%s" for css regression test...</comment>',
                    $imageIdentifier
                )
            );
            
            $this->moduleFileSystemUtil->createDirectoryRecursive(
                dirname($referenceImagePath)
            );
            
            copy($image->getImageFilename(), $referenceImagePath);
        } else {
            $referenceImage = new \Imagick($referenceImagePath);

            // Match image sizes to prevent Imagick exception
            $referenceImageSize = $referenceImage->getImageGeometry();
            $imageSize          = $image->getImageGeometry();
            $maxWidth           = max($referenceImageSize['width'], $imageSize['width']);
            $maxHeight          = max($referenceImageSize['height'], $imageSize['height']);

            $referenceImage->extentImage($maxWidth, $maxHeight, 0, 0);
            $image->extentImage($maxWidth, $maxHeight, 0, 0);

            try {
                /** @var \Imagick $comparedImage */
                list($comparedImage, $difference) = $referenceImage->compareImages($image,
                    \Imagick::METRIC_MEANSQUAREERROR);

                $calculatedDifferenceValue = round((float)round($difference, 4) * 100, 2);

                $this->currentTestCase->getScenario()->comment(
                    'Difference between reference and current image is around ' . $calculatedDifferenceValue . '%'
                );

                if ($calculatedDifferenceValue > $this->config['maxDifference']) {
                    $failImagePath = $this->moduleFileSystemUtil->getFailImagePath($imageName, $contextPath, 'diff');

                    $this->moduleFileSystemUtil->createDirectoryRecursive(dirname($failImagePath));

                    $image->writeImage($this->moduleFileSystemUtil->getFailImagePath($imageName, $contextPath, 'fail'));
                    $comparedImage->setImageFormat('png');
                    $comparedImage->writeImage($failImagePath);
                    $this->fail('Image does not match to the reference image.');
                }
            } catch (\ImagickException $e) {
                $this->debug("IMagickException! could not campare $referenceImage and $image.\nExceptionMessage: " . $e->getMessage());
                $this->fail($e->getMessage() . ", $referenceImage and $image.");
            }
        }
    }
    
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
     * Will unhide the element for the given selector or unhide all elements that have been set to hidden before if
     * no selector is given.
     *
     * @param string|null $selector The selector of the element that should be unhidden nor null if all elements should
     * be unhidden that have been set to hidden before.
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
     * Create screenshot for an element
     *
     * @param string $referenceImageName
     * @param RemoteWebElement $element
     * @return \Imagick
     */
    protected function _createScreenshot($referenceImageName, RemoteWebElement $element)
    {
        if (!$this->config['fullScreenshots']) {
            // Try scrolling the element into the view port
            $element->getLocationOnScreenOnceScrolledIntoView();
        }

        $tempImagePath = $this->moduleFileSystemUtil->getTempImagePath($referenceImageName);
        $this->webDriver->webDriver->takeScreenshot($tempImagePath);

        $image = new \Imagick($tempImagePath);

        $takeCoordinatesFrom = $this->config['fullScreenshots'] ? 'onPage' : 'inViewPort';

        $image->cropImage(
            $element->getSize()->getWidth(),
            $element->getSize()->getHeight(),
            $element->getCoordinates()->{$takeCoordinatesFrom}()->getX(),
            $element->getCoordinates()->{$takeCoordinatesFrom}()->getY()
        );
        $image->setImageFormat('png');
        $image->writeImage($tempImagePath);

        return $image;
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
     * The time when the module has been initalized
     *
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
