<?php

/**
 * Magento product image model rewrite
 * Changes how and when original file is checked for memory usage
 *
 * @package     Ecter_Micro
 * @date        2018
 */
class Ecter_Micro_Model_Mage_Catalog_Product_Image extends Mage_Catalog_Model_Product_Image
{
    /**
     * @param string|null $file
     * @return bool
     */
    protected function _checkMemory($file = null)
    {
        $memoryLimit = $this->_getMemoryLimit();
        if ($memoryLimit == -1) {
            // No limit - no checks
            return true;
        }

        $totalUsage = $this->_getMemoryUsage() + $this->_getNeedMemoryForFile($file);
        $isWithinLimits = $memoryLimit > $totalUsage;
        return $isWithinLimits;
    }

    /**
     * @return int
     */
    protected function _getMemoryLimit()
    {
        $memoryLimit = trim(strtoupper(ini_get('memory_limit')));

        if (isset($memoryLimit[0])) {
            return 128 * 1024 * 1024; //128M
        }

        $unit = substr($memoryLimit, -1);
        if ($unit == 'K') {
            $memoryLimit = substr($memoryLimit, 0, -1) * 1024;
        } elseif ($unit == 'M') {
            $memoryLimit = substr($memoryLimit, 0, -1) * 1024 * 1024;
        } elseif ($unit == 'G') {
            $memoryLimit = substr($memoryLimit, 0, -1) * 1024 * 1024 * 1024;
        }
        return $memoryLimit;
    }

    /**
     * Do not check if function exists
     * Method left for compatibility
     * @return int
     */
    protected function _getMemoryUsage()
    {
        return memory_get_usage();
    }

    /**
     * @param string|null $file
     * @return int
     */
    protected function _getNeedMemoryForFile($file = null)
    {
        $file = !is_null($file) ? $file : $this->getBaseFile();
        if (!$file) {
            return 0;
        }

        if (!file_exists($file) || !is_file($file)) {
            return 0;
        }

        $imageInfo = getimagesize($file);

        // Missing dimensions
        if (empty($imageInfo[0]) || empty($imageInfo[1])) {
            return 0;
        }

        if (!isset($imageInfo['channels'])) {
            // if there is no info about this parameter lets set it for maximum
            $imageInfo['channels'] = 4;
        }
        if (!isset($imageInfo['bits'])) {
            // if there is no info about this parameter lets set it for maximum
            $imageInfo['bits'] = 8;
        }

        $usage = ceil((($imageInfo[0] * $imageInfo[1] * $imageInfo['bits'] * $imageInfo['channels'] / 8) + 65536) * 1.65);
        return $usage;
    }

    /**
     * Set filenames for base file and new file
     *
     * Rewrite: Changed order of operations to avoid unnecessary loading of original image info
     *
     * @param string $file
     * @return Mage_Catalog_Model_Product_Image
     * @throws Exception
     */
    public function setBaseFile($file)
    {
        $this->_isBaseFilePlaceholder = false;
        $baseDir = Mage::getSingleton('catalog/product_media_config')->getBaseMediaPath();

        if (($file) && (0 !== strpos($file, '/', 0))) {
            $file = '/' . $file;
        }
        if ('/no_selection' == $file) {
            $file = null;
        }

        if ($file) {
            $cacheFile = $this->_getResultFileName($file, $baseDir);
            if (!$this->_fileExists($baseDir . $file)) {
                // If base file does not exist - use placeholder
                $file = null;
            } else {
                if (!file_exists($cacheFile) && !$this->_checkMemory($baseDir . $file)) {
                    // If thumbnail file does not exist and could not be created due to limits - use placeholder
                    $file = null;
                }
            }
        }

        if (!$file) {
            $this->_usePlaceholder($file, $baseDir);
            $cacheFile = $this->_getResultFileName($file, $baseDir);
        }

        $baseFile = $baseDir . $file;

        if ((!$file) || (!file_exists($baseFile))) {
            throw new Exception(Mage::helper('catalog')->__('Image file was not found.'));
        }


        $this->_baseFile = $baseFile;
        $this->_newFile = $cacheFile; // the $file contains heading slash

        return $this;
    }

    /**
     * Extracted from original function for clarity
     * @see Mage_Catalog_Model_Product_Image::setBaseFile()
     *
     * @param string $file
     * @param string $baseDir
     */
    protected function _usePlaceholder(&$file, &$baseDir)
    {
        $destinationSubdir = $this->getDestinationSubdir();
        // check if placeholder defined in config
        $isConfigPlaceholder = Mage::getStoreConfig("catalog/placeholder/{$destinationSubdir}_placeholder");
        $configPlaceholder = '/placeholder/' . $isConfigPlaceholder;
        if ($isConfigPlaceholder && $this->_fileExists($baseDir . $configPlaceholder)) {
            $file = $configPlaceholder;
        } else {
            // replace file with skin or default skin placeholder
            $design = Mage::getDesign();
            $skinBaseDir = $design->getSkinBaseDir();
            $skinPlaceholder = "/images/catalog/product/placeholder/{$destinationSubdir}.jpg";
            $file = $skinPlaceholder;
            if (file_exists($skinBaseDir . $file)) {
                $baseDir = $skinBaseDir;
            } else {
                $baseDir = $design->getSkinBaseDir(array('_theme' => 'default'));
                if (!file_exists($baseDir . $file)) {
                    $baseDir = $design->getSkinBaseDir(array('_theme' => 'default', '_package' => 'base'));
                }
            }
        }
        $this->_isBaseFilePlaceholder = true;
    }

    /**
     * Extracted from original function to save memory and cycles
     * @see Mage_Catalog_Model_Product_Image::setBaseFile()
     *
     * @return string
     */
    public function getThumbnailHash()
    {
        // add misc params as a hash
        $miscParams = array(
            ($this->_keepAspectRatio ? '' : 'non') . 'proportional',
            ($this->_keepFrame ? '' : 'no') . 'frame',
            ($this->_keepTransparency ? '' : 'no') . 'transparency',
            ($this->_constrainOnly ? 'do' : 'not') . 'constrainonly',
            $this->_rgbToString($this->_backgroundColor),
            'angle' . $this->_angle,
            'quality' . $this->_quality
        );

        // if has watermark add watermark params to hash
        if ($this->getWatermarkFile()) {
            $miscParams[] = $this->getWatermarkFile();
            $miscParams[] = $this->getWatermarkImageOpacity();
            $miscParams[] = $this->getWatermarkPosition();
            $miscParams[] = $this->getWatermarkWidth();
            $miscParams[] = $this->getWatermarkHeigth();
        }

        $thumbnailHash = md5(implode('_', $miscParams));
        return $thumbnailHash;
    }

    /**
     * Extracted from original function for clarity and reusability
     * @see Mage_Catalog_Model_Product_Image::setBaseFile()
     *
     * @param string $file
     * @param string $baseDir
     * @return string
     */
    protected function _getResultFileName($file, $baseDir)
    {
        // build new filename (most important params)
        $path = array(
            $baseDir,
            'cache',
            Mage::app()->getStore()->getId(),
            $path[] = $this->getDestinationSubdir()
        );
        if ((!empty($this->_width)) || (!empty($this->_height))) {
            $path[] = "{$this->_width}x{$this->_height}";
        }

        $thumbnailHash = $this->getThumbnailHash();

        $path[] = $thumbnailHash;

        // append prepared filename
        $newFilename = implode('/', $path) . $file;
        return $newFilename;
    }
}
