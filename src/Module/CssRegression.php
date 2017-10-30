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
        'repositionImage' => false, // Experimental
        'module' => 'WebDriver',
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

        $failDir = dirname($this->fileSystem->getFailImageDirectory());

        if (!is_dir($failDir)) {
            return;
        }

        \Codeception\Util\FileSystem::doEmptyDir($failDir);
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

        $this->fileSystem->cleanupTemporaryFiles();
    }

    public function _getInitTime()
    {
        return self::$moduleInitTime;
    }

    public function getBestOffset(\Undemanding\Difference\Image $image1, \Undemanding\Difference\Image $image, $startOffset = [0, 0], $offsetCap = [0, 0])
    {
        $minWidth = min($image1->getWidth(), $image->getWidth());
        $minHeight = min($image1->getHeight(), $image->getHeight());

        $maxWidth = max($image1->getWidth(), $image->getWidth());
        $maxHeight = max($image1->getHeight(), $image->getHeight());

        if (!array_filter($offsetCap)) {
            $offsetCap = [
                $maxWidth - $minWidth,
                $maxHeight - $minHeight
            ];
        }

        $bestMatch = 999;
        $bestOffset = [0, 0];

        for ($offsetLeft = $startOffset[0]; $offsetLeft <= $offsetCap[0]; $offsetLeft++) {
            for ($offsetTop = $startOffset[1]; $offsetTop <= $offsetCap[1]; $offsetTop++) {
                $difference = $image1->difference($image, [$offsetLeft, $offsetTop]);

                $result = $difference->percentage();

                if ($result < $bestMatch) {
                    $bestMatch = $result;
                    $bestOffset = $difference->getOffset();
                }
            }
        }

        return $bestOffset;
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

        $capturedImage = $this->_captureImage($identifier, reset($elements));

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

            $this->imageEditor->save($capturedImage, $referenceImagePath);
        } else {
            $referenceImage = \Grafika\Gd\Image::createFromFile($referenceImagePath);

            /**
             * Diff image :: find best offset :: create down-scaled images
             */
            $scale = 20;

            if ($this->config['repositionImage']) {
                $rzCapturedImage = clone $capturedImage;
                $rzReferenceImage = clone $referenceImage;

                $this->imageEditor->resize(
                    $rzCapturedImage,
                    $capturedImage->getWidth() / $scale,
                    $capturedImage->getHeight() / $scale
                );

                $this->imageEditor->resize(
                    $rzReferenceImage,
                    $referenceImage->getWidth() / $scale,
                    $referenceImage->getHeight() / $scale
                );

                $diRzCapturedImage = new \Undemanding\Difference\Image($rzCapturedImage->getCore());
                $diRzReferenceImage = new \Undemanding\Difference\Image($rzReferenceImage->getCore());

                /**
                 * Diff image :: find best offset :: do the calculation
                 */
                $bestOffset = $this->getBestOffset($diRzCapturedImage, $diRzReferenceImage);

                $offset = array_map(function ($value) use ($scale) {
                    return $value * $scale / 2;
                }, $bestOffset);

                $this->imageEditor->free($rzCapturedImage);
                $this->imageEditor->free($rzReferenceImage);

            } else {
                $offset = [0, 0];
            }

            /**
             * Diff image :: calculate
             */
            $diCapturedImage = new \Undemanding\Difference\Image($capturedImage->getCore());
            $diReferenceImage = new \Undemanding\Difference\Image($referenceImage->getCore());

            if ($this->config['repositionImage']) {
                $diOffset = $this->getBestOffset(
                    $diCapturedImage,
                    $diReferenceImage,
                    $offset,
                    [$offset[0] + $scale, $offset[1] + $scale]
                );
            } else {
                $diOffset = [0, 0];
            }

            $difference = $diCapturedImage->difference($diReferenceImage, $diOffset);

            $maxWidth = max($diCapturedImage->getWidth(), $diReferenceImage->getWidth());
            $maxHeight = max($diCapturedImage->getHeight(), $diReferenceImage->getHeight());
            $minWidth = min($diCapturedImage->getWidth(), $diReferenceImage->getWidth());
            $minHeight = min($diCapturedImage->getHeight(), $diReferenceImage->getHeight());

            $extraBoundaries = [];

            $offset = $difference->getOffset();

            /**
             * Left
             */
            $extraBoundaries[] = [
                'left' => 0,
                'top' => 0,
                'right' => $offset[0],
                'bottom' => $maxHeight,
            ];

            /**
             * Right
             */
            $extraBoundaries[] = [
                'left' => $minWidth + $offset[0],
                'top' => 0,
                'right' => $maxWidth,
                'bottom' => $maxHeight
            ];

            /**
             * Top
             */
            $extraBoundaries[] = [
                'left' => $offset[0] + 1,
                'top' => 0,
                'right' => $offset[0] + $minWidth - 1,
                'bottom' => $offset[1]
            ];

            /**
             * Bottom
             */
            $extraBoundaries[] = [
                'left' => $offset[0] + 1,
                'top' => $minHeight,
                'right' => $offset[0] + $minWidth - 1,
                'bottom' => $maxHeight
            ];

            $extraBoundaries = array_map(function ($item) {
                return array_replace($item, ['type' => 'size']);
            }, $extraBoundaries);

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

            $connectedImages = new \Undemanding\Difference\ConnectedDifferences($difference);

            $diffAreas = array_merge(
                $connectedImages->withJoinedBoundaries()->boundaries(),
                $extraBoundaries
            );

            $diffAreas = array_filter($diffAreas, function ($boundary) {
                return $boundary['left'] !== $boundary['right']
                    && $boundary['top'] !== $boundary['bottom'];
            });

            $diCapturedImage->reset();
            $diReferenceImage->reset();

            /**
             * Diff image :: create
             */
            $failImagePath = $this->fileSystem->getFailImagePath($imageName, $contextPath, 'fail');
            $diffImagePath = $this->fileSystem->getFailImagePath($imageName, $contextPath, 'diff');

            $this->imageEditor->save($capturedImage, $failImagePath);

            $diffImage = \Grafika\Gd\Image::createBlank($maxWidth, $maxHeight);

            $diffImage->fullAlphaMode(true);
            $capturedImage->fullAlphaMode(true);
            $referenceImage->fullAlphaMode(true);

            $this->imageEditor->fill($diffImage, new \Grafika\Color('FFFFFF'));

            $this->imageEditor->blend($diffImage, $capturedImage, 'normal', 0.8);
            $this->imageEditor->blend($diffImage, $referenceImage, 'normal', 0.8, 'top-left', $offset[0], $offset[1]);
            $this->imageEditor->blend($diffImage, $capturedImage, 'normal', 0.4);
            $this->imageEditor->blend($diffImage, $referenceImage, 'normal', 0.1, 'top-left', $offset[0], $offset[1]);

            /**
             * Diff image :: highlight differences
             */
            $boundaryColors = [];
            $markerContext = $diffImage->getCore();

            foreach ($this->colorConfigMap as $type => $name) {
                list($red, $green, $blue, $alpha) = sscanf($this->config[$name], "%02x%02x%02x%02x");

                $boundaryColors[$type] = imagecolorallocatealpha($markerContext, $red, $green, $blue, $alpha * 127 / 255);
            }

            $rectanglePolyPointMap = ['left', 'top', 'right', 'top', 'right', 'bottom', 'left', 'bottom'];

            foreach ($diffAreas as $boundary) {
                $boundaryType = isset($boundary['type']) ? $boundary['type'] : 'content';

                imagefilledpolygon(
                    $markerContext,
                    array_map(function ($key) use ($boundary) {
                        return $boundary[$key];
                    }, $rectanglePolyPointMap),
                    count($rectanglePolyPointMap) / 2,
                    $boundaryColors[$boundaryType]
                );
            }

            $this->imageEditor->save($diffImage, $diffImagePath);

            $this->imageEditor->free($capturedImage);
            $this->imageEditor->free($referenceImage);
            $this->imageEditor->free($diffImage);

            $this->fail(
                sprintf('Page content for "%s" differs from reference image', $selector)
            );
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

        $elementCoordinates = $element->getCoordinates();
        $elementSize = $element->getSize();

        $sizeString = $this->fileSystem->getCurrentWindowSizeString($this->webDriver);

        $tempImagePath = $this->fileSystem->getTemporaryImagePath(
            $referenceImageName,
            $sizeString
        );

        $this->fileSystem->createDirectoryRecursive(dirname($tempImagePath));

        $this->webDriver->webDriver->takeScreenshot($tempImagePath);

        $size = explode('x', $sizeString);

        $grImage = \Grafika\Gd\Image::createFromFile($tempImagePath);

        $this->imageEditor->resize($grImage, array_shift($size), array_shift($size));
        $this->imageEditor->save($grImage, $tempImagePath);

        if ($this->config['fullScreenshots']) {
            $elementPosition = $elementCoordinates->onPage();
        } else {
            $elementPosition = $elementCoordinates->inViewPort();
        }

        $this->imageEditor->crop(
            $grImage,
            $elementSize->getWidth(),
            $elementSize->getHeight(),
            'top-left',
            $elementPosition->getX(),
            $elementPosition->getY()
        );

        return $grImage;
    }
}
