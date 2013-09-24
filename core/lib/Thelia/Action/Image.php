<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace Thelia\Action;

use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Core\Event\ImageCreateOrUpdateEvent;
use Thelia\Core\Event\ImagesCreateOrUpdateEvent;
use Thelia\Core\Event\ImageDeleteEvent;
use Thelia\Core\Event\ImageEvent;
use Thelia\Model\CategoryImage;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ContentImage;
use Thelia\Model\FolderImage;
use Thelia\Model\ProductImage;
use Thelia\Tools\FileManager;
use Thelia\Tools\URL;

use Imagine\Image\ImagineInterface;
use Imagine\Image\ImageInterface;
use Imagine\Image\Box;
use Imagine\Image\Color;
use Imagine\Image\Point;
use Thelia\Exception\ImageException;
use Thelia\Core\Event\TheliaEvents;

/**
 *
 * Image management actions. This class handles image processing and caching.
 *
 * Basically, images are stored outside of the web space (by default in local/media/images),
 * and cached inside the web space (by default in web/local/images).
 *
 * In the images caches directory, a subdirectory for images categories (eg. product, category, folder, etc.) is
 * automatically created, and the cached image is created here. Plugin may use their own subdirectory as required.
 *
 * The cached image name contains a hash of the processing options, and the original (normalized) name of the image.
 *
 * A copy (or symbolic link, by default) of the original image is always created in the cache, so that the full
 * resolution image is always available.
 *
 * Various image processing options are available :
 *
 * - resizing, with border, crop, or by keeping image aspect ratio
 * - rotation, in degrees, positive or negative
 * - background color, applyed to empty background when creating borders or rotating
 * - effects. The effects are applied in the specified order. The following effects are available:
 *    - gamma:value : change the image Gamma to the specified value. Example: gamma:0.7
 *    - grayscale or greyscale: switch image to grayscale
 *    - colorize:color : apply a color mask to the image. Exemple: colorize:#ff2244
 *    - negative : transform the image in its negative equivalent
 *    - vflip or vertical_flip : vertical flip
 *    - hflip or horizontal_flip : horizontal flip
 *
 * If a problem occurs, an ImageException may be thrown.
 *
 * @package Thelia\Action
 * @author Franck Allimant <franck@cqfdev.fr>
 *
 */
class Image extends BaseCachedFile implements EventSubscriberInterface
{
    // Resize mode constants
    const EXACT_RATIO_WITH_BORDERS = 1;
    const EXACT_RATIO_WITH_CROP = 2;
    const KEEP_IMAGE_RATIO = 3;



    /**
     * @return string root of the image cache directory in web space
     */
    protected function getCacheDirFromWebRoot() {
        return ConfigQuery::read('image_cache_dir_from_web_root', 'cache');
    }

    /**
     * Process image and write the result in the image cache.
     *
     * If the image already exists in cache, the cache file is immediately returned, without any processing
     * If the original (full resolution) image is required, create either a symbolic link with the
     * original image in the cache dir, or copy it in the cache dir.
     *
     * This method updates the cache_file_path and file_url attributes of the event
     *
     * @param  ImageEvent                 $event
     * @throws \InvalidArgumentException, ImageException
     */
    public function processImage(ImageEvent $event)
    {

        $subdir      = $event->getCacheSubdirectory();
        $source_file = $event->getSourceFilepath();

        if (null == $subdir || null == $source_file) {
            throw new \InvalidArgumentException("Cache sub-directory and source file path cannot be null");
        }

        // echo basename($source_file).": ";

        // Find cached file path
        $cacheFilePath = $this->getCacheFilePath($subdir, $source_file, $event->isOriginalImage(), $event->getOptionsHash());

        $originalImagePathInCache = $this->getCacheFilePath($subdir, $source_file, true);

        if (! file_exists($cacheFilePath)) {

            if (! file_exists($source_file)) {
                throw new ImageException(sprintf("Source image file %s does not exists.", $source_file));
            }

            // Create a chached version of the original image in the web space, if not exists

            if (! file_exists($originalImagePathInCache)) {

                $mode = ConfigQuery::read('original_image_delivery_mode', 'symlink');

                if ($mode == 'symlink') {
                    if (false == symlink($source_file, $originalImagePathInCache)) {
                         throw new ImageException(sprintf("Failed to create symbolic link for %s in %s image cache directory", basename($source_file), $subdir));
                    }
                } else {// mode = 'copy'
                    if (false == @copy($source_file, $originalImagePathInCache)) {
                        throw new ImageException(sprintf("Failed to copy %s in %s image cache directory", basename($source_file), $subdir));
                    }
                }
            }

            // Process image only if we have some transformations to do.
            if (! $event->isOriginalImage()) {

                // We have to process the image.
                $imagine = $this->createImagineInstance();

                $image = $imagine->open($source_file);

                if ($image) {

                    $background_color = $event->getBackgroundColor();

                    if ($background_color != null) {
                        $bg_color = new Color($background_color);
                    } else
                        $bg_color = null;

                    // Apply resize
                    $image = $this->applyResize($imagine, $image, $event->getWidth(), $event->getHeight(), $event->getResizeMode(), $bg_color);

                    // Rotate if required
                    $rotation = intval($event->getRotation());

                    if ($rotation != 0)
                        $image->rotate($rotation, $bg_color);

                    // Flip
                    // Process each effects
                    foreach ($event->getEffects() as $effect) {

                        $effect = trim(strtolower($effect));

                        $params = explode(':', $effect);

                        switch ($params[0]) {

                        case 'greyscale':
                        case 'grayscale':
                            $image->effects()->grayscale();
                            break;

                        case 'negative':
                            $image->effects()->negative();
                            break;

                        case 'horizontal_flip':
                        case 'hflip':
                            $image->flipHorizontally();
                            break;

                        case 'vertical_flip':
                        case 'vflip':
                            $image-> flipVertically();
                            break;

                        case 'gamma':
                            // Syntax: gamma:value. Exemple: gamma:0.7
                            if (isset($params[1])) {
                                $gamma = floatval($params[1]);

                                $image->effects()->gamma($gamma);
                            }
                            break;

                        case 'colorize':
                            // Syntax: colorize:couleur. Exemple: colorize:#ff00cc
                            if (isset($params[1])) {
                                $the_color = new Color($params[1]);

                                $image->effects()->colorize($the_color);
                            }
                            break;
                        }
                    }

                    $quality = $event->getQuality();

                    if (is_null($quality)) $quality = ConfigQuery::read('default_image_quality_percent', 75);

                    $image->save(
                            $cacheFilePath,
                            array('quality' => $quality)
                     );
                } else {
                    throw new ImageException(sprintf("Source file %s cannot be opened.", basename($source_file)));
                }
            }
        }

        // Compute the image URL
        $processed_image_url = $this->getCacheFileURL($subdir, basename($cacheFilePath));

        // compute the full resulution image path in cache
        $original_image_url = $this->getCacheFileURL($subdir, basename($originalImagePathInCache));

        // Update the event with file path and file URL
        $event->setCacheFilepath($cacheFilePath);
        $event->setCacheOriginalFilepath($originalImagePathInCache);

        $event->setFileUrl(URL::getInstance()->absoluteUrl($processed_image_url, null, URL::PATH_TO_FILE));
        $event->setOriginalFileUrl(URL::getInstance()->absoluteUrl($original_image_url, null, URL::PATH_TO_FILE));
    }

    /**
     * Take care of saving image in the database and file storage
     *
     * @param ImageCreateOrUpdateEvent $event Image event
     *
     * @throws \Thelia\Exception\ImageException
     * @todo refactor make all pictures using propel inheritance and factorise image behaviour into one single clean action
     */
    public function saveImage(ImageCreateOrUpdateEvent $event)
    {
        $this->adminLogAppend(
            $this->container->get('thelia.translator')->trans(
                'Saving images for %parentName% parent id %parentId% (%parentType%)',
                array(
                    '%parentName%' => $event->getParentName(),
                    '%parentId%' => $event->getParentId(),
                    '%parentType%' => $event->getImageType()
                ),
                'image'
            )
        );

        $fileManager = new FileManager($this->container);
        $model = $event->getModelImage();

        $nbModifiedLines = $model->save();
        $event->setModelImage($model);

        if (!$nbModifiedLines) {
            throw new ImageException(
                sprintf(
                    'Image "%s" with parent id %s (%s) failed to be saved',
                    $event->getParentName(),
                    $event->getParentId(),
                    $event->getImageType()
                )
            );
        }

        $newUploadedFile = $fileManager->copyUploadedFile($event->getParentId(), $event->getImageType(), $event->getModelImage(), $event->getUploadedFile(), FileManager::FILE_TYPE_IMAGES);
        $event->setUploadedFile($newUploadedFile);
    }

    /**
     * Take care of updating image in the database and file storage
     *
     * @param ImageCreateOrUpdateEvent $event Image event
     *
     * @throws \Thelia\Exception\ImageException
     * @todo refactor make all pictures using propel inheritance and factorise image behaviour into one single clean action
     */
    public function updateImage(ImageCreateOrUpdateEvent $event)
    {
        $this->adminLogAppend(
            $this->container->get('thelia.translator')->trans(
                'Updating images for %parentName% parent id %parentId% (%parentType%)',
                array(
                    '%parentName%' => $event->getParentName(),
                    '%parentId%' => $event->getParentId(),
                    '%parentType%' => $event->getImageType()
                ),
                'image'
            )
        );

        $fileManager = new FileManager($this->container);
        // Copy and save file
        if ($event->getUploadedFile()) {
            // Remove old picture file from file storage
            $url = $fileManager->getUploadDir($event->getImageType(), FileManager::FILE_TYPE_IMAGES) . '/' . $event->getOldModelImage()->getFile();
            unlink(str_replace('..', '', $url));

            $newUploadedFile = $fileManager->copyUploadedFile($event->getParentId(), $event->getImageType(), $event->getModelImage(), $event->getUploadedFile(), FileManager::FILE_TYPE_IMAGES);
            $event->setUploadedFile($newUploadedFile);
        }

        // Update image modifications
        $event->getModelImage()->save();
        $event->setModelImage($event->getModelImage());
    }

    /**
     * Take care of deleting image in the database and file storage
     *
     * @param ImageDeleteEvent $event Image event
     *
     * @throws \Exception
     * @todo refactor make all pictures using propel inheritance and factorise image behaviour into one single clean action
     */
    public function deleteImage(ImageDeleteEvent $event)
    {
        $fileManager = new FileManager($this->container);

        try {
            $fileManager->deleteFile($event->getImageToDelete(), $event->getImageType(), FileManager::FILE_TYPE_IMAGES);

            $this->adminLogAppend(
                $this->container->get('thelia.translator')->trans(
                    'Deleting image for %id% with parent id %parentId%',
                    array(
                        '%id%' => $event->getImageToDelete()->getId(),
                        '%parentId%' => $event->getImageToDelete()->getParentId(),
                    ),
                    'image'
                )
            );
        } catch(\Exception $e){
            $this->adminLogAppend(
                $this->container->get('thelia.translator')->trans(
                    'Fail to delete image for %id% with parent id %parentId% (Exception : %e%)',
                    array(
                        '%id%' => $event->getImageToDelete()->getId(),
                        '%parentId%' => $event->getImageToDelete()->getParentId(),
                        '%e%' => $e->getMessage()
                    ),
                    'image'
                )
            );
            throw $e;
        }
    }

    /**
     * Process image resizing, with borders or cropping. If $dest_width and $dest_height
     * are both null, no resize is performed.
     *
     * @param  ImagineInterface $imagine     the Imagine instance
     * @param  ImageInterface   $image       the image to process
     * @param  int              $dest_width  the required width
     * @param  int              $dest_height the required height
     * @param  int              $resize_mode the resize mode (crop / bands / keep image ratio)p
     * @param  string           $bg_color    the bg_color used for bands
     * @return ImageInterface   the resized image.
     */
    protected function applyResize(ImagineInterface $imagine, ImageInterface $image, $dest_width, $dest_height, $resize_mode, $bg_color)
    {
        if (! (is_null($dest_width) && is_null($dest_height))) {

            $width_orig = $image->getSize()->getWidth();
            $height_orig = $image->getSize()->getHeight();

            if (is_null($dest_width))
                $dest_width = $width_orig;

            if (is_null($dest_height))
                $dest_height = $height_orig;

            if (is_null($resize_mode))
                $resize_mode = self::KEEP_IMAGE_RATIO;

            $width_diff = $dest_width / $width_orig;
            $height_diff = $dest_height / $height_orig;

            $delta_x = $delta_y = $border_width = $border_height = 0;

            if ($width_diff > 1 AND $height_diff > 1) {

                $next_width = $width_orig;
                $next_height = $height_orig;

                $dest_width = ($resize_mode == self::EXACT_RATIO_WITH_BORDERS ? $dest_width : $next_width);
                $dest_height = ($resize_mode == self::EXACT_RATIO_WITH_BORDERS ? $dest_height : $next_height);
            } elseif ($width_diff > $height_diff) {
                // Image height > image width

                $next_height = $dest_height;
                $next_width = intval(($width_orig * $next_height) / $height_orig);

                if ($resize_mode == self::EXACT_RATIO_WITH_CROP) {
                    $next_width = $dest_width;
                    $next_height = intval($height_orig * $dest_width / $width_orig);
                    $delta_y = ($next_height - $dest_height) / 2;
                } elseif ($resize_mode != self::EXACT_RATIO_WITH_BORDERS) {
                    $dest_width = $next_width;
                }
            } else {
                // Image width > image height
                $next_width = $dest_width;
                $next_height = intval($height_orig * $dest_width / $width_orig);

                if ($resize_mode == self::EXACT_RATIO_WITH_CROP) {
                    $next_height = $dest_height;
                    $next_width  = intval(($width_orig * $next_height) / $height_orig);
                    $delta_x = ($next_width - $dest_width) / 2;
                } elseif ($resize_mode != self::EXACT_RATIO_WITH_BORDERS) {
                    $dest_height = $next_height;
                }
            }

            $image->resize(new Box($next_width, $next_height));

            // echo "w=$dest_width, h=$dest_height, nw=$next_width, nh=$next_height, dx=$delta_x, dy=$delta_y, bw=$border_width, bh=$border_height\n";

            if ($resize_mode == self::EXACT_RATIO_WITH_BORDERS) {

                $border_width = intval(($dest_width - $next_width) / 2);
                $border_height = intval(($dest_height - $next_height) / 2);

                $canvas = new Box($dest_width, $dest_height);

                return $imagine->create($canvas, $bg_color)
                    ->paste($image, new Point($border_width, $border_height));
            } elseif ($resize_mode == self::EXACT_RATIO_WITH_CROP) {
                $image->crop(
                    new Point($delta_x, $delta_y),
                    new Box($dest_width, $dest_height)
                );
            }
        }

        return $image;
    }

    /**
     * Create a new Imagine object using current driver configuration
     *
     * @return \Imagine\ImagineInterface
     */
    protected function createImagineInstance()
    {
        $driver = ConfigQuery::read("imagine_graphic_driver", "gd");

        switch ($driver) {
        case 'imagik':
            $image = new \Imagine\Imagick\Imagine();
            break;

        case 'gmagick':
            $image = new \Imagine\Gmagick\Imagine();
            break;

        case 'gd':
        default:
            $image = new \Imagine\Gd\Imagine();
        }

        return $image;
    }

    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::IMAGE_PROCESS => array("processImage", 128),
            TheliaEvents::IMAGE_CLEAR_CACHE => array("clearCache", 128),
            TheliaEvents::IMAGE_DELETE => array("deleteImage", 128),
            TheliaEvents::IMAGE_SAVE => array("saveImage", 128),
            TheliaEvents::IMAGE_UPDATE => array("updateImage", 128),
        );
    }
}
