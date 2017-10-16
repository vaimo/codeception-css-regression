<?php
namespace Vaimo\CodeceptionCssRegression\Util;

use Codeception\Module;
use Vaimo\CodeceptionCssRegression\Module\CssRegression;

/**
 * Provide some methods for filesystem related actions
 */
class FileSystem
{

    /**
     * @var CssRegression
     */
    protected $module;

    /**
     * @param CssRegression $module
     */
    public function __construct(CssRegression $module)
    {
        $this->module = $module;
    }

    /**
     * Create a directory recursive
     *
     * @param $path
     */
    public function createDirectoryRecursive($path)
    {
        if (substr($path, 0, 1) !== '/') {
            $path = \Codeception\Configuration::projectDir() . $path;
        } elseif (!strstr($path, \Codeception\Configuration::projectDir())) {
            throw new \InvalidArgumentException(
                'Can\'t create directroy "' . $path
                . '" as it is outside of the project root "'
                . \Codeception\Configuration::projectDir() . '"'
            );
        }

        if (!is_dir(dirname($path))) {
            self::createDirectoryRecursive(dirname($path));
        }

        if (!is_dir($path)) {
            \Codeception\Util\Debug::debug('Directory "' . $path . '" does not exist. Try to create it ...');
            mkdir($path);
        }
    }

    /**
     * Get path for the reference image
     *
     * @param string $identifier
     * @return string path to the reference image
     */
    public function getReferenceImagePath($identifier, $sizeString)
    {
        $fileNameParts = array(
            $this->sanitizeFilename($identifier),
            'png'
        );
        
        return $this->getPath(
            $this->getReferenceImageDirectory(),
            $sizeString,
            implode('.', $fileNameParts)
        );
    }

    /**
     * @return string
     */
    public function getReferenceImageDirectory()
    {
        return $this->getPath(
            \Codeception\Configuration::dataDir(),
            $this->module->_getConfig('referenceImageDirectory')
        );
    }

    /**
     * @return string
     */
    public function getCurrentWindowSizeString(Module\WebDriver $webDriver)
    {
        $windowSize = $webDriver->webDriver->manage()->window()->getSize();
        return ($windowSize->getWidth() - $this->module->_getConfig('widthOffset')) . 'x' . $windowSize->getHeight();
    }

    /**
     * @param $name
     * @return string
     */
    public function sanitizeFilename($name)
    {
        return str_replace(
            ' ',
            '_',
            preg_replace('/[^A-Za-z0-9\._\- ]/', '', $name)
        );
    }

    /**
     * @param string $identifier test identifier
     * @param string $sizeString
     * @param string $suffix suffix added to the filename
     * @return string path to the fail image
     */
    public function getFailImagePath($identifier, $sizeString, $suffix = 'fail')
    {
        $fileNameParts = array(
            $suffix,
            $this->sanitizeFilename($identifier),
            'png'
        );

        return $this->getPath(
            $this->getFailImageDirectory(),
            $sizeString,
            implode('.', $fileNameParts)
        );
    }

    /**
     * @return string
     */
    public function getFailImageDirectory()
    {
        return $this->getPath(
            \Codeception\Configuration::outputDir(),
            $this->module->_getConfig('failImageDirectory'),
            $this->module->_getModuleInitTime()
        );
    }

    /**
     * @param string $identifier identifier for the test
     * @return string Path to the temp image
     */
    public function getTempImagePath($identifier)
    {
        $fileNameParts = array(
            $this->module->_getModuleInitTime(),
            $this->getCurrentWindowSizeString($this->module->_getWebdriver()),
            $this->sanitizeFilename($identifier),
            'png'
        );

        return $this->getPath(
            $this->getTempDirectory(),
            implode('.', $fileNameParts)
        );
    }

    /**
     * Get the directory to store temporary files
     *
     * @return string
     */
    public function getTempDirectory()
    {
        return $this->getPath(
            \Codeception\Configuration::outputDir(), 
            'debug'
        );
    }
    
    private function getPath()
    {
        return implode(
            DIRECTORY_SEPARATOR, 
            array_map(function ($item) {
                return rtrim($item, DIRECTORY_SEPARATOR);
            }, func_get_args())
        );
    }
}
