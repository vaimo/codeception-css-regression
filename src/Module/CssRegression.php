<?php
namespace Vaimo\CodeceptionCssRegression\Module;

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
 * - colorContent: The color of the diff overlay on content differences
 * - colorSize: The color of the image size difference indicator (in case compared image has bigger dimensions than
 *   the reference)
 */
class CssRegression extends \Codeception\Module
{
    /**
     * @var int Timestamp when the suite was initialized
     */
    protected static $moduleInitTime = 0;

    /**
     * @var array
     */
    protected $config = [
        'maxDifference' => 0.01,
        'automaticCleanup' => true,
        'fullScreenshots' => true,
        'module' => 'WebDriver',
        'widthOffset' => 0,
        'colorContent' => 'EE0000C7',
        'colorSize' => '88888866'
    ];

    /**
     * @var \Codeception\Lib\Console\Output
     */
    protected $logger;

    /**
     * @var \Vaimo\CodeceptionCssRegression\Util\Runtime
     */
    protected $runtimeUtils;

    /**
     * @var \Codeception\Module\WebDriver
     */
    protected $webDriver = null;

    /**
     * @var array
     */
    protected $requiredFields = ['referenceImageDirectory', 'failImageDirectory'];

    /**
     * @var string
     */
    protected $suitePath = '';

    /**
     * @var \Codeception\TestInterface
     */
    protected $currentTestCase;

    /**
     * @var \Vaimo\CodeceptionCssRegression\Util\FileSystem
     */
    protected $fileSystem;

    /**
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

    private $colorConfigMap = [
        'content' => 'colorContent',
        'size' => 'colorSize'
    ];

    public function _initialize()
    {
        $isFirstInitialize = self::$moduleInitTime === 0;

        if ($isFirstInitialize) {
            self::$moduleInitTime = time();
        }

        $this->imageEditor = new \Grafika\Gd\Editor();
        $this->logger = new \Codeception\Lib\Console\Output([]);
        $this->runtimeUtils = new \Vaimo\CodeceptionCssRegression\Util\Runtime();
        $this->fileSystem = new \Vaimo\CodeceptionCssRegression\Util\FileSystem($this, $this->_getInitTime());

        if (!$isFirstInitialize) {
            return;
        }

        if (!$this->config['automaticCleanup']) {
            return;
        }


        if (!is_dir($this->fileSystem->getFailImageDirectory())) {
            return;
        }

        \Codeception\Util\FileSystem::doEmptyDir(
            $this->fileSystem->getFailImageDirectory()
        );
    }

    public function _beforeSuite($settings = [])
    {
        $this->suitePath = $settings['path'];
        $this->hiddenSuiteElements = [];
    }

    public function _before(\Codeception\TestInterface $test)
    {
        $this->currentTestCase = $test;
        $this->webDriver = $this->getModule($this->config['module']);
    }

    public function _afterStep(\Codeception\Step $step)
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

    public function _getInitTime()
    {
        return self::$moduleInitTime;
    }

    public function dontSeeDifferencesWithReferenceImage($selector = 'body', $identifier = null)
    {
        if (!$identifier) {
            $identifier = 'capture_' . str_pad(++$this->captureCounter, 3, '0', STR_PAD_LEFT);
        }

        $elements = $this->webDriver->_findElements($selector);

        if (count($elements) == 0) {
            throw new \Codeception\Exception\ElementNotFound($selector);
        } elseif (count($elements) > 1) {
            throw new \Codeception\Exception\ModuleException(
                __CLASS__,
                sprintf(
                    'Multiple elements found for given selector "%s" but need exactly one element!',
                    $selector
                )
            );
        }

        $imagePath = $this->_captureImage($identifier, reset($elements));

        $windowSizeString = $this->fileSystem->getCurrentWindowSizeString($this->webDriver);

        $imageName = $identifier . '---' . $windowSizeString;
        $contextPath = $this->runtimeUtils->getContextPath($this->currentTestCase);

        $referenceImagePath = $this->fileSystem->getReferenceImagePath($imageName, $contextPath);

        if (!file_exists($referenceImagePath)) {
            $this->logger->writeln(
                sprintf(
                    '~ <comment>Generating reference image "%s" ...</comment>',
                    $identifier
                )
            );

            $this->fileSystem->createDirectoryRecursive(
                dirname($referenceImagePath)
            );

            copy($imagePath, $referenceImagePath);
        } else {
            /**
             * Diff image :: calculate
             */
            $image = new \Undemanding\Difference\Image($imagePath);
            $referenceImage = new \Undemanding\Difference\Image($referenceImagePath);

            $difference = $image->difference(
                $referenceImage,
                new \Undemanding\Difference\Method\EuclideanDistance()
            );

            $maxWidth = max($image->getWidth(), $referenceImage->getWidth());
            $maxHeight = max($image->getHeight(), $referenceImage->getHeight());

            $extraBoundaries = [];
            $extraBoundaries[] = [
                'left' => min($image->getWidth(), $referenceImage->getWidth()),
                'top' => 0,
                'right' => $maxWidth,
                'bottom' => min($image->getHeight(), $referenceImage->getHeight()),
                'type' => 'size'
            ];

            $extraBoundaries[] = [
                'left' => 0,
                'top' => min($image->getHeight(), $referenceImage->getHeight()),
                'right' => $maxWidth,
                'bottom' => $maxHeight,
                'type' => 'size'
            ];

            $diffArea = 0;
            foreach ($extraBoundaries as $boundary) {
                $diffArea += ($boundary['right'] - $boundary['left']) * ($boundary['bottom'] - $boundary['top']);
            }

            $areaDiff = 100 * $diffArea / ($maxWidth * $maxHeight);
            $contentDiff = $difference->percentage() * (100 - $areaDiff) / 100;

            if ($percentage = round($contentDiff + $areaDiff, 2)) {
                $messageTag = $percentage > $this->config['maxDifference'] ? 'error' : 'info';

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

            /**
             * Diff image :: evaluate
             */
            if ($percentage <= $this->config['maxDifference']) {
                return;
            }

            /**
             * Diff image :: create
             */
            $connectedImages = new \Undemanding\Difference\ConnectedDifferences($difference);

            $diffAreas = array_merge($connectedImages->withJoinedBoundaries()->boundaries(), $extraBoundaries);

            $failImagePath = $this->fileSystem->getFailImagePath($imageName, $contextPath, 'fail');
            $diffImagePath = $this->fileSystem->getFailImagePath($imageName, $contextPath, 'diff');

            $this->fileSystem->createDirectoryRecursive(dirname($failImagePath));
            $this->fileSystem->createDirectoryRecursive(dirname($diffImagePath));

            copy($imagePath, $failImagePath);

            $diffImage = \Grafika\Gd\Image::createBlank($maxWidth, $maxHeight);
            $grImage = \Grafika\Gd\Image::createFromCore($image->getCore());
            $grReferenceImage = \Grafika\Gd\Image::createFromCore($referenceImage->getCore());

            $diffImage->fullAlphaMode(true);
            $grImage->fullAlphaMode(true);
            $grReferenceImage->fullAlphaMode(true);

            $this->imageEditor->fill($diffImage, new \Grafika\Color('FFFFFF'));
            $this->imageEditor->blend($diffImage, $grImage, 'normal', 0.8);
            $this->imageEditor->blend($diffImage, $grReferenceImage, 'normal', 0.8);
            $this->imageEditor->blend($diffImage, $grImage, 'normal', 0.4);
            $this->imageEditor->blend($diffImage, $grReferenceImage, 'normal', 0.1);

            /**
             * Diff image :: highlight differences
             */
            $handle = $diffImage->getCore();

            $boundaryColors = [];
            foreach ($this->colorConfigMap as $type => $name) {
                list($red, $green, $blue, $alpha) = sscanf($this->config[$name], "%02x%02x%02x%02x");
                $boundaryColors[$type] = imagecolorallocatealpha($handle, $red, $green, $blue, $alpha * 127 / 255);
            }

            $polygonPointMap = ['left', 'top', 'right', 'top', 'right', 'bottom', 'left', 'bottom'];

            foreach ($diffAreas as $boundary) {
                $boundaryType = isset($boundary['type']) ? $boundary['type'] : 'content';

                imagefilledpolygon(
                    $handle,
                    array_map(function ($key) use ($boundary) {
                        return $boundary[$key];
                    }, $polygonPointMap),
                    count($polygonPointMap) / 2,
                    $boundaryColors[$boundaryType]
                );
            }

            $this->imageEditor->save($diffImage, $diffImagePath);

            imagedestroy($image->getCore());
            imagedestroy($referenceImage->getCore());
            imagedestroy($diffImage->getCore());

            $this->fail(
                sprintf('Page content for "%s" differs from reference image', $selector)
            );

            $image->reset();
            $referenceImage->reset();
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

    public function showElements($selector = null)
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

    protected function _captureImage($referenceImageName, \Facebook\WebDriver\Remote\RemoteWebElement $element)
    {
        if (!$this->config['fullScreenshots']) {
            $element->getLocationOnScreenOnceScrolledIntoView();
        }

        $tempImagePath = $this->fileSystem->getTempImagePath(
            $referenceImageName,
            $this->fileSystem->getCurrentWindowSizeString($this->webDriver)
        );

        $this->fileSystem->createDirectoryRecursive(dirname($tempImagePath));

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
}
