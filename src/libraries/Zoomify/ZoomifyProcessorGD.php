<?php
/**
 * Copyright (C) 2005  Adam Smith  asmith@agile-software.com
 *
 * Ported from Python to PHP by Wes Wright
 * Cleanup for Drupal by Karim Ratib (kratib@open-craft.com)
 * Cleanup for Omeka by Daniel Berthereau (daniel.github@berthereau.net)
 * Conversion to ImageMagick by Daniel Berthereau
 * Integrated in Omeka S and support a specified destination directory.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/**
 * ZoomifyFileProcessor class.
 */
class ZoomifyFileProcessor
{
    public $_debug = false;

    public $destinationDir = '';
    public $destinationRemove = true;

    public $updatePerms = true;
    public $fileMode = 0644;
    public $dirMode = 0755;
    public $fileGroup = 'www-data';

    public $tileSize = 256;
    public $tileQuality = 85;

    protected $_tileExt = 'jpg';
    protected $_imageFilename = '';
    protected $_originalWidth = 0;
    protected $_originalHeight = 0;
    protected $_originalFormat = 0;
    protected $_saveToLocation;
    protected $_scaleInfo = array();
    protected $_tileGroupMappings = array();
    protected $_numberOfTiles = 0;

    /**
     * The method the client calls to generate zoomify metadata.
     *
     * Check to be sure the file hasn't been converted already.
     *
     * @param string $filepath The path to the image.
     * @param string $destinationDir The directory where to store the tiles.
     * @return boolean
     */
    public function ZoomifyProcess($image_name)
    {
        $this->_imageFilename = realpath($image_name);
        $result = $this->createDataContainer();
        if (!$result) {
            trigger_error('Output directory already exists.', E_USER_WARNING);
            return;
        }
        $this->getImageMetadata();
        $this->processImage();
        $result = $this->saveXMLOutput();
        return $result;
    }

    /**
     * Given an image name, load it and extract metadata.
     *
     * @return void
     */
    protected function getImageMetadata()
    {
        list($this->_originalWidth, $this->_originalHeight, $this->_originalFormat) = getimagesize($this->_imageFilename);

        // Get scaling information.
        $width = $this->_originalWidth;
        $height = $this->_originalHeight;
        if ($this->_debug) {
            print "getImageMetadata for file $this->_imageFilename originalWidth=$width originalHeight=$height tilesize=$this->tileSize<br />" . PHP_EOL;
        }
        $width_height = array($width, $height);
        array_unshift($this->_scaleInfo, $width_height);
        while (($width > $this->tileSize) || ($height > $this->tileSize)) {
            $width = floor($width / 2);
            $height = floor($height / 2);
            $width_height = array($width, $height);
            array_unshift($this->_scaleInfo, $width_height);
            if ($this->_debug) {
                print "getImageMetadata newWidth=$width newHeight=$height<br />" . PHP_EOL;
            }
        }

        // Tile and tile group information.
        $this->preProcess();
    }

    /**
     * Create a container (a folder) for tiles and tile metadata if not set.
     *
     * @return boolean
     */
    protected function createDataContainer()
    {
        if ($this->destinationDir) {
            $location = $this->destinationDir;
        }
        //Determine the path to the directory from the filepath.
        else {
            list($root, $ext) = $this->getRootAndDotExtension($this->_imageFilename);
            $directory = dirname($root);
            $filename = basename($root);
            $root = $filename . '_zdata';
            $location = $directory . DIRECTORY_SEPARATOR . $root;
        }

        $this->_saveToLocation = $location;

        // If the paths already exist, an image is being re-processed, clean up
        // for it.
        if ($this->destinationRemove) {
            if (is_dir($this->_saveToLocation)) {
                $result = $this->rmDir($this->_saveToLocation);
            }
        } elseif (is_dir($this->_saveToLocation)) {
            return false;
        }

        if (!is_dir($this->_saveToLocation)) {
            mkdir($this->_saveToLocation, $this->dirMode, true);
        }
        if ($this->updatePerms) {
            @chmod($this->_saveToLocation, $this->dirMode);
            @chgrp($this->_saveToLocation, $this->fileGroup);
        }

        return true;
    }

    /**
     * Create a container for the next group of tiles within the data container.
     */
    protected function createTileContainer($tileContainerName = '')
    {
        $tileContainerPath = $this->_saveToLocation . DIRECTORY_SEPARATOR . $tileContainerName;

        if (!is_dir($tileContainerPath)) {
            // echo "Trying to make $tileContainerPath<br />" . PHP_EOL;
            mkdir($tileContainerPath);
            if ($this->updatePerms) {
                @chmod($tileContainerPath, $this->dirMode);
                @chgrp($tileContainerPath, $this->fileGroup);
            }
        }
    }

    /**
     * Plan for the arrangement of the tile groups.
     */
    protected function preProcess()
    {
        $tier = 0;
        $tileGroupNumber = 0;
        $numberOfTiles = 0;

        foreach ($this->_scaleInfo as $width_height) {
            list($width, $height) = $width_height;

            // Cycle through columns, then rows.
            $row = 0;
            $column = 0;
            $ul_x = 0;
            $ul_y = 0;
            $lr_x = 0;
            $lr_y = 0;
            while (!(($lr_x == $width) && ($lr_y == $height))) {
                $tileFilename = $this->getTileFilename($tier, $column, $row);
                $tileContainerName = $this->getNewTileContainerName($tileGroupNumber);

                if ($numberOfTiles == 0) {
                    $this->createTileContainer($tileContainerName);
                }
                elseif ($numberOfTiles % $this->tileSize == 0) {
                    ++$tileGroupNumber;
                    $tileContainerName = $this->getNewTileContainerName($tileGroupNumber);
                    $this->createTileContainer($tileContainerName);

                    if ($this->_debug) {
                        print 'new tile group ' . $tileGroupNumber . ' tileContainerName=' . $tileContainerName ."<br />" . PHP_EOL;
                    }
                }
                $this->_tileGroupMappings[$tileFilename] = $tileContainerName;
                ++$numberOfTiles;

                // for the next tile, set lower right cropping point
                $lr_x = ($ul_x + $this->tileSize < $width) ? $ul_x + $this->tileSize : $width;
                $lr_y = ($ul_y + $this->tileSize < $height) ? $ul_y + $this->tileSize : $height;

                // for the next tile, set upper left cropping point
                if ($lr_x == $width) {
                    $ul_x = 0;
                    $ul_y = $lr_y;
                    $column = 0;
                    ++$row;
                }
                else {
                    $ul_x = $lr_x;
                    ++$column;
                }
            }
            ++$tier;
        }
    }

    /**
     * Starting with the original image, start processing each row.
     */
    protected function processImage()
    {
        // Start from the last scale (bigger image).
        $tier = (count($this->_scaleInfo) - 1);
        $row = 0;
        $ul_y = 0;
        $lr_y = 0;

        list($root, $ext) = $this->getRootAndDotExtension($this->_imageFilename);

        if ($this->_debug) {
            print "processImage root=$root ext=$ext<br />" . PHP_EOL;
        }
        // Create a row from the original image and process it.
        $image = $this->openImage();
        while ($row * $this->tileSize < $this->_originalHeight) {
            $ul_y = $row * $this->tileSize;
            $lr_y = ($ul_y + $this->tileSize < $this->_originalHeight)
                ? $ul_y + $this->tileSize
                : $this->_originalHeight;
            $saveFilename = $root . $tier . '-' . $row . '.' . $ext;
            // print "line " . __LINE__ . " calling crop<br />" . PHP_EOL;
            # imageRow = image.crop([0, ul_y, $this->_originalWidth, lr_y])
            $imageRow = $this->imageCrop($image, 0, $ul_y, $this->_originalWidth, $lr_y);
            if ($this->_debug) {
                print "processImage root=$root tier=$tier row=$row saveFilename=$saveFilename<br />" . PHP_EOL;
            }
            touch($saveFilename);
            if ($this->updatePerms) {
                @chmod($saveFilename, $this->fileMode);
                @chgrp($saveFilename, $this->fileGroup);
            }
            imagejpeg($imageRow, $saveFilename, 100);
            imagedestroy($imageRow);
            $this->processRowImage($tier, $row);
            ++$row;
        }
        imagedestroy($image);
    }

    /**
     * For a row image, create and save tiles.
     */
    protected function processRowImage($tier = 0, $row = 0)
    {
        # print '*** processing tier: ' + str(tier) + ' row: ' + str(row)

        list($tierWidth, $tierHeight) = $this->_scaleInfo[$tier];
        if ($this->_debug) {
            print "tier $tier width $tierWidth height $tierHeight<br />" . PHP_EOL;
        }
        $rowsForTier = floor($tierHeight / $this->tileSize);
        if ($tierHeight % $this->tileSize > 0) {
            ++$rowsForTier;
        }

        list($root, $ext) = $this->getRootAndDotExtension($this->_imageFilename);

        $imageRow = null;

        // Create row for the current tier.
        // First tier.
        if ($tier == count($this->_scaleInfo) - 1) {
            $firstTierRowFile = $root . $tier . '-' . $row . '.' . $ext;
            if ($this->_debug) {
                print "firstTierRowFile=$firstTierRowFile<br />" . PHP_EOL;
            }
            if (is_file($firstTierRowFile)) {
                $imageRow = imagecreatefromjpeg($firstTierRowFile);
                if ($this->_debug) {
                    print "firstTierRowFile exists<br />" . PHP_EOL;
                }
            }
        }

        // Instead of use of original image, the image for the current tier is
        // rebuild from the previous tier's row (first and eventual second
        // rows). It allows a quicker resize.
        else {
            // Create an empty file in case where there are no first or second
            // row file.
            $imageRow = imagecreatetruecolor($tierWidth, $this->tileSize);

            $t = $tier + 1;
            $r = $row + $row;

            $firstRowFile = $root . $t . '-' . $r . '.' . $ext;
            if ($this->_debug) {
                print "create this row from previous tier's rows tier=$tier row=$row firstRowFile=$firstRowFile<br />" . PHP_EOL;
            }
            if ($this->_debug) {
                print "imageRow tierWidth=$tierWidth tierHeight= $this->tileSize<br />" . PHP_EOL;
            }
            $firstRowWidth = 0;
            $firstRowHeight = 0;
            $secondRowWidth = 0;
            $secondRowHeight = 0;

            if (is_file($firstRowFile)) {
                # print firstRowFile + ' exists, try to open...'
                $firstRowImage = imagecreatefromjpeg($firstRowFile);
                $firstRowWidth = imagesx($firstRowImage);
                $firstRowHeight = imagesy($firstRowImage);
                $imageRowHalfHeight = floor($this->tileSize / 2);
                // imagecopy(resource dst_im, resource src_im, int dst_x, int dst_y, int src_x, int src_y, int src_w, int src_h )
                // imagecopy($imageRow, $firstRowImage, 0, 0, 0, 0, $firstRowWidth, $firstRowHeight);
                if ($this->_debug) {
                    print "imageRow imagecopyresized tierWidth=$tierWidth imageRowHalfHeight= $imageRowHalfHeight firstRowWidth=$firstRowWidth firstRowHeight=$firstRowHeight<br />" . PHP_EOL;
                }
                // Bug: Use $firstRowHeight instead of $imageRowHalfHeight.
                // See Drupal Zoomify module http://drupalcode.org/project/zoomify.git/blob_plain/e2f977ab4b153b4ce6d1a486a1fe80ecf9512559:/ZoomifyFileProcessor.php.
                // imagecopyresized(resource dst_im, resource src_im, int dst_x, int dst_y, int src_x, int src_y, int dst_w, int dst_h, int src_w, int src_h)
                imagecopyresized($imageRow, $firstRowImage, 0, 0, 0, 0, $tierWidth, $firstRowHeight, $firstRowWidth, $firstRowHeight);
                unlink($firstRowFile);
            }

            ++$r;
            $secondRowFile =  $root . $t . '-' . $r . '.' . $ext;
            if ($this->_debug) {
                print "create this row from previous tier's rows tier=$tier row=$row secondRowFile=$secondRowFile<br />" . PHP_EOL;
            }
            // There may not be a second row at the bottom of the image...
            // If any, copy this second row file at the bottom of the row image.
            if (is_file($secondRowFile)) {
                if ($this->_debug) {
                    print $secondRowFile . " exists, try to open...<br />" . PHP_EOL;
                }
                $secondRowImage = imagecreatefromjpeg($secondRowFile);
                $secondRowWidth = imagesx($secondRowImage);
                $secondRowHeight = imagesy($secondRowImage);
                $imageRowHalfHeight = floor($this->tileSize / 2);
                if ($this->_debug) {
                    print "imageRow imagecopyresized tierWidth=$tierWidth imageRowHalfHeight=$imageRowHalfHeight firstRowWidth=$firstRowWidth firstRowHeight=$firstRowHeight<br />" . PHP_EOL;
                }
                // imagecopy(resource dst_im, resource src_im, int dst_x, int dst_y, int src_x, int src_y, int src_w, int src_h )
                // imagecopy($imageRow, $secondRowImage, 0, $firstRowWidth, 0, 0, $firstRowWidth, $firstRowHeight);
                // imagecopyresampled(resource dst_im, resource src_im, int dst_x, int dst_y, int src_x, int src_y, int dst_w, int dst_h, int src_w, int src_h)

                // As imageRow isn't empty, the second row file is resized, then
                // copied in the bottom of imageRow, then the second row file is
                // deleted.
                imagecopyresampled($imageRow, $secondRowImage, 0, $imageRowHalfHeight, 0, 0, $tierWidth, $secondRowHeight, $secondRowWidth, $secondRowHeight);
                unlink($secondRowFile);
            }

            // The last row may be less than $this->tileSize...
            $rowHeight = $firstRowHeight + $secondRowHeight;
            $tileHeight = $this->tileSize * 2;
            if (($firstRowHeight + $secondRowHeight) < $this->tileSize * 2) {
                if ($this->_debug) {
                    print "line " . __LINE__ . " calling crop rowHeight=$rowHeight tileHeight=$tileHeight<br />" . PHP_EOL;
                }
                # imageRow = imageRow.crop((0, 0, tierWidth, (firstRowHeight + secondRowHeight)))
                $imageRow = $this->imageCrop($imageRow, 0, 0, $tierWidth, $firstRowHeight + $secondRowHeight);
            }
        }

        // Create tiles for the current image row.
        if ($imageRow) {
            // Cycle through columns, then rows.
            $column = 0;
            $imageWidth = imagesx($imageRow);
            $imageHeight = imagesy($imageRow);
            $ul_x = 0;
            $ul_y = 0;
            $lr_x = 0;
            $lr_y = 0;
            while (!(($lr_x == $imageWidth) && ($lr_y == $imageHeight))) {
                if ($this->_debug) {
                    print "ul_x=$ul_x lr_x=$lr_x ul_y=$ul_y lr_y=$lr_y imageWidth=$imageWidth imageHeight=$imageHeight<br />" . PHP_EOL;
                }
                // Set lower right cropping point.
                $lr_x = (($ul_x + $this->tileSize) < $imageWidth)
                    ? $ul_x + $this->tileSize
                    : $imageWidth;
                $lr_y = (($ul_y + $this->tileSize) < $imageHeight)
                    ? $ul_y + $this->tileSize
                    : $imageHeight;

                # tierLabel = len($this->_scaleInfo) - tier
                if ($this->_debug) {
                    print "line " . __LINE__ . " calling crop<br />" . PHP_EOL;
                }
                $this->saveTile($this->imageCrop($imageRow, $ul_x, $ul_y, $lr_x, $lr_y), $tier, $column, $row);
                $this->_numberOfTiles++;
                if ($this->_debug) {
                    print "created tile: numberOfTiles= $this->_numberOfTiles tier column row =($tier,$column,$row)<br />" . PHP_EOL;
                }

                // Set upper left cropping point.
                if ($lr_x == $imageWidth) {
                    $ul_x = 0;
                    $ul_y = $lr_y;
                    $column = 0;
                    #row += 1
                }
                else {
                    $ul_x = $lr_x;
                    ++$column;
                }
            }

            // Create a new sample for the current tier, then process next tiers
            // via a recursive call.
            if ($tier > 0) {
                $halfWidth = max(1, floor($imageWidth / 2));
                $halfHeight = max(1, floor($imageHeight / 2));
                $rowFilename = $root . $tier . '-' . $row . '.' . $ext;
                # print 'resize as ' + str(imageWidth/2) + ' by ' + str(imageHeight/2) + ' (or ' + str(halfWidth) + ' x ' + str(halfHeight) + ')'
                # tempImage = imageRow.resize((imageWidth / 2, imageHeight / 2), PIL.Image.ANTIALIAS)
                # tempImage = imageRow.resize((halfWidth, halfHeight), PIL.Image.ANTIALIAS)
                $tempImage = imagecreatetruecolor($halfWidth, $halfHeight);
                // imagecopyresampled(resource dst_im, resource src_im, int dst_x, int dst_y, int src_x, int src_y, int dst_w, int dst_h, int src_w, int src_h)
                imagecopyresampled($tempImage, $imageRow, 0, 0, 0, 0, $halfWidth, $halfHeight, $imageWidth, $imageHeight);
                # tempImage.save(root + str(tier) + '-' + str(row) + ext)
                touch($rowFilename);
                imagejpeg($tempImage, $rowFilename);
                if ($this->updatePerms) {
                    @chmod($rowFilename, $this->fileMode);
                    @chgrp($rowFilename, $this->fileGroup);
                }
                imagedestroy($tempImage);
                # print 'saved row file: ' + root + str(tier) + '-' + str(row) + ext
                # tempImage = null
                # rowImage = null
            }

            // http://greengaloshes.cc/2007/05/zoomifyimage-ported-to-php/#comment-451
            imagedestroy($imageRow);

            // Process next tiers via a recursive call.
            if ($tier > 0) {
                if ($this->_debug) {
                    print "processRowImage final checks for tier $tier row=$row rowsForTier=$rowsForTier<br />" . PHP_EOL;
                }
                if ($row % 2 != 0) {
                    if ($this->_debug) {
                        print "processRowImage final checks tier=$tier row=$row mod 2 check before<br />" . PHP_EOL;
                    }
                    // $this->processRowImage($tier = $tier - 1, $row = ($row - 1) / 2);
                    $this->processRowImage($tier - 1, floor(($row - 1) / 2));
                    if ($this->_debug) {
                        print "processRowImage final checks tier=$tier row=$row mod 2 check after<br />" . PHP_EOL;
                    }
                }
                elseif ($row == $rowsForTier - 1) {
                    if ($this->_debug) {
                        print "processRowImage final checks tier=$tier row=$row rowsForTier=$rowsForTier check before<br />" . PHP_EOL;
                    }
                    // $this->processRowImage($tier = $tier - 1, $row = $row / 2);
                    $this->processRowImage($tier - 1, floor($row / 2));
                    if ($this->_debug) {
                        print "processRowImage final checks tier=$tier row=$row rowsForTier=$rowsForTier check after<br />" . PHP_EOL;
                    }
                }
            }
        }
    }

    /**
     * Explode a filepath in a root and an extension, i.e. "/path/file.ext" to
     * "/path/file" and ".ext".
     *
     * @return array
     */
    protected function getRootAndDotExtension($filepath)
    {
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        $root = $extension ? substr($filepath, 0, strrpos($filepath, '.')) : $filepath;
        return array($root, $extension);
    }

    /**
     * Get the name of the file for the tile.
     *
     * @return string
     */
    protected function getTileFilename($scaleNumber, $columnNumber, $rowNumber)
    {
        return (string) $scaleNumber . '-' . (string) $columnNumber . '-' . (string) $rowNumber . '.' . $this->_tileExt;
    }

    /**
     * Return the name of the next tile group container.
     *
     * @return string
     */
    protected function getNewTileContainerName($tileGroupNumber = 0)
    {
        return 'TileGroup' . (string) $tileGroupNumber;
    }

    /**
     * Get the full path of the file the tile will be saved as.
     *
     * @return string
     */
    protected function getFileReference($scaleNumber, $columnNumber, $rowNumber)
    {
        $tileFilename = $this->getTileFilename($scaleNumber, $columnNumber, $rowNumber);
        $tileContainerName = $this->getAssignedTileContainerName($tileFilename);
        return $this->_saveToLocation . DIRECTORY_SEPARATOR . $tileContainerName . DIRECTORY_SEPARATOR . $tileFilename;
    }

    /**
     * Return the name of the tile group for the indicated tile.
     *
     * @return string
     */
    protected function getAssignedTileContainerName($tileFilename)
    {
        if ($tileFilename) {
            // print "getAssignedTileContainerName tileFilename $tileFilename exists<br />" . PHP_EOL;
            // if (isset($this->_tileGroupMappings)) {
            //     print "getAssignedTileContainerName this->_tileGroupMappings defined<br />" . PHP_EOL;
            // }
            // if ($this->_tileGroupMappings) {
            //     print "getAssignedTileContainerName this->_tileGroupMappings is true" . PHP_EOL;
            // }
            if (isset($this->_tileGroupMappings) && $this->_tileGroupMappings) {
                if (isset($this->_tileGroupMappings[$tileFilename])) {
                    $containerName = $this->_tileGroupMappings[$tileFilename];
                    if ($containerName) {
                        // print "getAssignedTileContainerName returning containerName " . $containerName ."<br />" . PHP_EOL;
                        return $containerName;
                    }
                }
            }
        }
        $containerName = $this->getNewTileContainerName();
        if ($this->_debug) {
            print "getAssignedTileContainerName returning getNewTileContainerName " . $containerName . "<br />" . PHP_EOL;
        }

        return $containerName;
    }

    /**
     * Save xml metadata about the tiles.
     *
     * @return boolean
     */
    protected function saveXMLOutput()
    {
        $xmlFile = fopen($this->_saveToLocation . DIRECTORY_SEPARATOR . 'ImageProperties.xml', 'w');
        if ($xmlFile === false) {
            return false;
        }
        fwrite($xmlFile, $this->getXMLOutput());
        $result = fclose($xmlFile);
        if ($this->updatePerms) {
            @chmod($this->_saveToLocation . DIRECTORY_SEPARATOR . 'ImageProperties.xml', $this->fileMode);
            @chgrp($this->_saveToLocation . DIRECTORY_SEPARATOR . 'ImageProperties.xml', $this->fileGroup);
        }
        return $result;
    }

    /**
     * Create xml metadata about the tiles
     *
     * @return string
     */
    protected function getXMLOutput()
    {
        $xmlOutput = sprintf('<IMAGE_PROPERTIES WIDTH="%s" HEIGHT="%s" NUMTILES="%s" NUMIMAGES="1" TILESIZE="%s" VERSION="1.8" />',
            $this->_originalWidth, $this->_originalHeight, $this->_numberOfTiles, $this->tileSize) . PHP_EOL;
        return $xmlOutput;
    }

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirpath
     * @return boolean
     */
    protected function rmDir($dirPath)
    {
        $files = array_diff(scandir($dirPath), array('.', '..'));
        foreach ($files as $file) {
            $path = $dirPath . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            }
            else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }

    /**
     * Load the image data.
     *
     * @return ressource identifier of the image.
     */
    protected function openImage()
    {
        if ($this->_debug) {
            print "openImage $this->_imageFilename<br />" . PHP_EOL;
        }
        return $this->getImageFromFile($this->_imageFilename);
    }

    /**
     * Helper to get an image of different type (jpg, png or gif) from file.
     *
     * @return ressource identifier of the image.
     */
    protected function getImageFromFile($filename)
    {
        switch (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            case 'png':
                return imagecreatefrompng($filename);
            case 'gif':
                return imagecreatefromgif($filename);
            case 'jpg':
            case 'jpe':
            case 'jpeg':
            default:
                return imagecreatefromjpeg($filename);
        }
    }

    /**
     * Crop an image to a size.
     *
     * @return ressource identifier of the image.
     */
    protected function imageCrop($image, $left, $upper, $right, $lower)
    {
        $w = abs($right - $left);
        $h = abs($lower - $upper);
        $crop = imagecreatetruecolor($w, $h);
        imagecopy($crop, $image, 0, 0, $left, $upper, $w, $h);
        return $crop;
    }

    /**
     * Save the cropped region.
     */
    protected function saveTile($image, $scaleNumber, $column, $row)
    {
        $tile_file = $this->getFileReference($scaleNumber, $column, $row);
        touch($tile_file);
        if ($this->updatePerms) {
            @chmod($tile_file, $this->fileMode);
            @chgrp($tile_file, $this->fileGroup);
        }
        imagejpeg($image, $tile_file, $this->tileQuality);
        if ($this->_debug) {
            print "Saving to tile_file $tile_file<br />" . PHP_EOL;
        }
    }
}
