<?php
namespace Vaimo\CodeceptionCssRegression\Util;

class FileSystem
{

    /**
     * @var \Vaimo\CodeceptionCssRegression\Module\CssRegression
     */
    protected $module;

    /**
     * @var string
     */
    protected $currentRunDirectory;

    /**
     * @param \Vaimo\CodeceptionCssRegression\Module\CssRegression $module
     */
    public function __construct(
        \Vaimo\CodeceptionCssRegression\Module\CssRegression $module,
        $currentRunDirectory
    ) {
        $this->module = $module;
        $this->currentRunDirectory = $currentRunDirectory;
    }

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

    public function getReferenceImageDirectory()
    {
        return $this->getPath(
            \Codeception\Configuration::dataDir(),
            $this->module->_getConfig('referenceImageDirectory')
        );
    }

    public function getCurrentWindowSizeString(\Codeception\Module\WebDriver $webDriver)
    {
        $windowSize = $webDriver->webDriver->manage()->window()->getSize();

        return ($windowSize->getWidth() - $this->module->_getConfig('widthOffset')) . 'x' . $windowSize->getHeight();
    }

    public function sanitizeFilename($name)
    {
        return str_replace(
            ' ',
            '_',
            preg_replace('/[^A-Za-z0-9\._\- ]/', '', $name)
        );
    }

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

    public function getFailImageDirectory()
    {
        return $this->getPath(
            \Codeception\Configuration::outputDir(),
            $this->module->_getConfig('failImageDirectory'),
            $this->currentRunDirectory
        );
    }

    public function getTempImagePath($identifier, $sizeString)
    {
        $fileNameParts = array(
            $this->currentRunDirectory,
            $sizeString,
            $this->sanitizeFilename($identifier),
            'png'
        );

        return $this->getPath(
            $this->getTempDirectory(),
            implode('.', $fileNameParts)
        );
    }

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
