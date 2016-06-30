<?php

/*
 * Copyright 2015  Daniel Berthereau
 * Copyright 2016  BibLibre
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace UniversalViewer\View\Helper;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Module\Manager as ModuleManager;
use Omeka\File\Manager as FileManager;
use Zend\View\Helper\AbstractHelper;
use \Exception;

class IiifManifest extends AbstractHelper
{
    public function __invoke(AbstractResourceEntityRepresentation $resource, $asJson = true)
    {
        $resourceName = $resource->resourceName();
        if ($resourceName == 'items') {
            $result = $this->_buildManifestItem($resource);
        }
        elseif ($resourceName == 'item_sets') {
            return $this->view->iiifItemSet($resource, $asJson);
        }
        else {
            return null;
        }

        if ($asJson) {
            return version_compare(phpversion(), '5.4.0', '<')
                ? json_encode($result)
                : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        // Return as array
        return $result;
    }

    /**
     * Get the IIIF manifest for the specified item.
     *
     * @todo Replace all data by standard classes.
     * @todo Replace web root by routes, even if main ones are only urn.
     *
     * @param $resource Item
     * @return Object|null. The object corresponding to the manifest.
     */
    protected function _buildManifestItem(ItemRepresentation $item)
    {
        // Prepare all values needed for manifest.
        $url = $this->view->url('universalviewer_presentation_manifest', array(
            'recordtype' => 'items',
            'id' => $item->id(),
        ));

        // The base url for some other ids.
        $this->_baseUrl = dirname($url);

        $metadata = [];
        foreach ($item->values() as $name => $term) {
            $value = reset($term['values']);
            $metadata[] = (object) [
                'label' => $value->property()->localName(),
                'value' => (string) $value,
            ];
        }

        $title = $item->displayTitle();

        $description = $item->value('dcterms:citation', array('type' => 'literal'));

        // Thumbnail of the whole work.
        // TODO Use index of the true representative file.
        $serviceLocator = $this->view->getHelperPluginManager()->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $response = $api->search('media', array('has_thumbnails' => 1, 'limit' => 1));
        $medias = $response->getContent();
        $thumbnail = $this->_iiifThumbnail($medias[0]);

        $settings = $serviceLocator->get('Omeka\Settings');
        $licence = $settings->get('universalviewer_licence');
        $attribution = $settings->get('universalviewer_attribution');

        // TODO To parameter or to extract from metadata.
        $service = '';
        /*
        $service = (object) array(
            '@context' =>'http://example.org/ns/jsonld/context.json',
            '@id' => 'http://example.org/service/example',
            'profile' => 'http://example.org/docs/example-service.html',
        );
        */

        // TODO To parameter or to extract from metadata.
        $seeAlso = '';
        /*
        $seeAlso = (object) array(
            '@id' => 'http://www.example.org/library/catalog/book1.marc',
            'format' =>'application/marc',
        );
        */

        $within = '';
        $withins = array();
        foreach ($item->itemSets() as $itemSet) {
            $withins[] = $this->view->url('universalviewer_presentation_manifest', array(
                'recordtype' => 'item_sets',
                'id' => $itemSet->id(),
            ));
        }
        if (!empty($withins)) {
            if (count($withins) == 1) {
                $within = $withins[0];
            } else {
                $within = $withins;
            }
        }

        $canvases = array();

        // Get all images and non-images.
        $medias = $item->media();
        $images = array();
        $nonImages = array();
        foreach ($medias as $media) {
            // Images files.
            // Internal: has_derivative is not only for images.
            if (strpos($media->mediaType(), 'image/') === 0) {
                $images[] = $media;
            }
            // Non-images files.
            else {
                  $nonImages[] = $media;
            }
        }
        unset ($medias);
        $totalImages = count($images);

        // Process images.
        $imageNumber = 0;
        foreach ($images as $media) {
            $canvas = $this->_iiifCanvasImage($media, ++$imageNumber);

            // TODO Add other content.
            /*
            $otherContent = array();
            $otherContent = (object) $otherContent;

            $canvas->otherContent = $otherContent;
            */

            $canvases[] = $canvas;
        }

        // Process non images.
        $rendering = array();
        $mediaSequences = array();
        $mediaSequencesElements = array();

        $translate = $this->getView()->plugin('translate');

        // TODO Manage the case where there is a video, a pdf etc, and the image
        // is only a quick view. So a main file should be set, that is not the
        // representative file.

        // When there are images, other files are added to download section.
        if ($totalImages > 0) {
            foreach ($nonImages as $media) {
                if ($media->mediaType() == 'application/pdf') {
                    $render = array();
                    $render['@id'] = $media->originalUrl();
                    $render['format'] = $media->mediaType();
                    $render['label'] = $translate('Download as PDF');
                    $render = (object) $render;
                    $rendering[] = $render;
                }
                // TODO Add alto files and search.
                // TODO Add other content.
            }
        }

        // Else, check if non-images are managed (special content, as pdf).
        else {
            foreach ($nonImages as $media) {
                switch ($media->mediaType()) {
                    case 'application/pdf':
                        $mediaSequenceElement = array();
                        $mediaSequenceElement['@id'] = $media->originalUrl();
                        $mediaSequenceElement['@type'] = 'foaf:Document';
                        $mediaSequenceElement['format'] = $media->mediaType();
                        // TODO If no file metadata, then item ones.
                        // TODO Currently, the main title and metadata are used,
                        // because in Omeka, a pdf is normally the only one
                        // file.
                        $mediaSequenceElement['label'] = $title;
                        $mediaSequenceElement['metadata'] = $metadata;
                        if ($media->hasThumbnails()) {
                            $thumbnailUrl = $media->thumbnailUrl('square');
                            if ($thumbnailUrl) {
                                $mediaSequenceElement['thumbnail'] = $thumbnailUrl;
                            }
                        }
                        $mediaSequencesService = array();
                        $mseUrl = $this->view->url('universalviewer_media', array(
                            'id' => $media->id(),
                        ));
                        $mediaSequencesService['@id'] = $mseUrl;
                        // See MediaController::contextAction()
                        $mediaSequencesService['profile'] = 'http://wellcomelibrary.org/ld/ixif/0/alpha.json';
                        $mediaSequencesService = (object) $mediaSequencesService;
                        $mediaSequenceElement['service'] = $mediaSequencesService;
                        $mediaSequenceElement = (object) $mediaSequenceElement;
                        $mediaSequencesElements[] = $mediaSequenceElement;
                        // TODO Add the file for download (no rendering)? The
                        // file is already available for download in the pdf viewer.
                        break;

                    case strpos($media->mediaType(), 'audio/') === 0:
                    // case 'audio/ogg':
                    // case 'audio/mp3':
                        $mediaSequenceElement = array();
                        $mediaSequenceElement['@id'] = $media->originalUrl() . '/element/e0';
                        $mediaSequenceElement['@type'] = 'dctypes:Sound';
                        // The format is not be set here (see rendering).
                        // $mediaSequenceElement['format'] = $file->mime_type;
                        // TODO If no file metadata, then item ones.
                        // TODO Currently, the main title and metadata are used,
                        // because in Omeka, such a file is normally the only
                        // one file.
                        $mediaSequenceElement['label'] = $title;
                        $mediaSequenceElement['metadata'] = $metadata;
                        if ($media->hasThumbnails()) {
                            $mseThumbnail = $media->thumbnailUrl('square');
                            if ($mseThumbnail) {
                                $mediaSequenceElement['thumbnail'] = $mseThumbnail;
                            }
                        }
                        // A place holder is recommended for media.
                        if (empty($mediaSequenceElement['thumbnail'])) {
                            // $placeholder = 'images/placeholder-audio.jpg';
                            // $mediaSequenceElement['thumbnail'] = src($placeholder);
                            $mediaSequenceElement['thumbnail'] = '';
                        }

                        // Specific to media files.
                        $mseRenderings = array();
                        // Only one rendering currently: the file itself, but it
                        // may be converted to multiple format: high and low
                        // resolution, webm...
                        $mseRendering = array();
                        $mseRendering['@id'] = $media->thumbnailUrl('square');
                        $mseRendering['format'] = $media->mediaType();
                        $mseRendering = (object) $mseRendering;
                        $mseRenderings[] = $mseRendering;
                        $mediaSequenceElement['rendering'] = $mseRenderings;

                        $mediaSequencesService = array();
                        $mseUrl = $this->view->url('universalviewer_media', array(
                            'id' => $media->id(),
                        ));
                        $mediaSequencesService['@id'] = $mseUrl;
                        // See MediaController::contextAction()
                        $mediaSequencesService['profile'] = 'http://wellcomelibrary.org/ld/ixif/0/alpha.json';
                        $mediaSequencesService = (object) $mediaSequencesService;
                        $mediaSequenceElement['service'] = $mediaSequencesService;
                        $mediaSequenceElement = (object) $mediaSequenceElement;
                        $mediaSequencesElements[] = $mediaSequenceElement;
                        // Rendering files are automatically added for download.
                        break;

                    // TODO Check/support the media type "application//octet-stream".
                    // case 'application//octet-stream':
                    case strpos($media->mediaType(), 'video/') === 0:
                    // case 'video/webm':
                        $mediaSequenceElement = array();
                        $mediaSequenceElement['@id'] = $media->originalUrl() . '/element/e0';
                        $mediaSequenceElement['@type'] = 'dctypes:MovingImage';
                        // The format is not be set here (see rendering).
                        // $mediaSequenceElement['format'] = $file->mime_type;
                        // TODO If no file metadata, then item ones.
                        // TODO Currently, the main title and metadata are used,
                        // because in Omeka, such a file is normally the only
                        // one file.
                        $mediaSequenceElement['label'] = $title;
                        $mediaSequenceElement['metadata'] = $metadata;
                        if ($media->hasThumbnails()) {
                            $mseThumbnail = $file->thumbnailUrl('square');
                            if ($mseThumbnail) {
                                $mediaSequenceElement['thumbnail'] = $mseThumbnail;
                            }
                        }
                        // A place holder is recommended for medias.
                        if (empty($mediaSequenceElement['thumbnail'])) {
                            // $placeholder = 'images/placeholder-video.jpg';
                            // $mediaSequenceElement['thumbnail'] = src($placeholder);
                            $mediaSequenceElement['thumbnail'] = '';
                        }

                        // Specific to media files.
                        $mseRenderings = array();
                        // Only one rendering currently: the file itself, but it
                        // may be converted to multiple format: high and low
                        // resolution, webm...
                        $mseRendering = array();
                        $mseRendering['@id'] = $media->originalUrl();
                        $mseRendering['format'] = $media->mediaType();
                        $mseRendering = (object) $mseRendering;
                        $mseRenderings[] = $mseRendering;
                        $mediaSequenceElement['rendering'] = $mseRenderings;

                        $mediaSequencesService = array();
                        $mseUrl = $this->view->url('universalviewer_media', array(
                            'id' => $media->id(),
                        ));
                        $mediaSequencesService['@id'] = $mseUrl;
                        // See MediaController::contextAction()
                        $mediaSequencesService['profile'] = 'http://wellcomelibrary.org/ld/ixif/0/alpha.json';
                        $mediaSequencesService = (object) $mediaSequencesService;
                        $mediaSequenceElement['service'] = $mediaSequencesService;
                        // TODO Get the true video width and height, even if it
                        // is automatically managed.
                        $mediaSequenceElement['width'] = 0;
                        $mediaSequenceElement['height'] = 0;
                        $mediaSequenceElement = (object) $mediaSequenceElement;
                        $mediaSequencesElements[] = $mediaSequenceElement;
                        // Rendering files are automatically added for download.
                        break;

                    default:
                        // TODO Add other content.
                }

                // TODO Add other files as resources of the current element.
            }
        }

        $sequences = array();

        // When there are images.
        if ($totalImages) {
            $sequence = array();
            $sequence['@id'] = $this->_baseUrl . '/sequence/normal';
            $sequence['@type'] = 'sc:Sequence';
            $sequence['label'] = 'Current Page Order';
            $sequence['viewingDirection'] = 'left-to-right';
            $sequence['viewingHint'] = $totalImages > 1 ? 'paged' : 'non-paged';
            if ($rendering) {
                $sequence['rendering'] = $rendering;
            }
            $sequence['canvases'] = $canvases;
            $sequence = (object) $sequence;

            $sequences[] = $sequence;
        }

        // Sequences when there is no image (special content).
        elseif ($mediaSequencesElements) {
            $mediaSequence = array();
            $mediaSequence['@id'] = $this->_baseUrl . '/sequence/s0';
            $mediaSequence['@type'] = 'ixif:MediaSequence';
            $mediaSequence['label'] = 'XSequence 0';
            $mediaSequence['elements'] = $mediaSequencesElements;
            $mediaSequence = (object) $mediaSequence;
            $mediaSequences[] = $mediaSequence;

            // Add a sequence in case of the media cannot be read.
            $sequence = $this->_iiifSequenceUnsupported($rendering);
            $sequences[] = $sequence;
        }

        // No supported content.
        else {
            // Set a default render if needed.
            /*
            if (empty($rendering)) {
                $placeholder = 'images/placeholder-unsupported.jpg';
                $render = array();
                $render['@id'] = src($placeholder);
                $render['format'] = 'image/jpeg';
                $render['label'] = __('Unsupported content.');
                $render = (object) $render;
                $rendering[] = $render;
            }
            */

            $sequence = $this->_iiifSequenceUnsupported($rendering);
            $sequences[] = $sequence;
        }

        // Prepare manifest.
        $manifest = array();
        $manifest['@context'] = $totalImages > 0
            ? 'http://iiif.io/api/presentation/2/context.json'
            : array(
                'http://iiif.io/api/presentation/2/context.json',
                // See MediaController::contextAction()
                'http://wellcomelibrary.org/ld/ixif/0/context.json',
                // WEB_ROOT . '/ld/ixif/0/context.json',
            );
        $manifest['@id'] = $url;
        $manifest['@type'] = 'sc:Manifest';
        $manifest['label'] = $title;
        if ($description) {
            $manifest['description'] = $description;
        }
        if ($thumbnail) {
            $manifest['thumbnail'] = $thumbnail;
        }
        if ($licence) {
            $manifest['license'] = $licence;
        }
        if ($attribution) {
            $manifest['attribution'] = $attribution;
        }
        if ($service) {
            $manifest['service'] = $service;
        }
        if ($seeAlso) {
            $manifest['seeAlso'] = $seeAlso;
        }
        if ($within) {
            $manifest['within'] = $within;
        }
        if ($metadata) {
            $manifest['metadata'] = $metadata;
        }
        if ($mediaSequences) {
            $manifest['mediaSequences'] = $mediaSequences;
        }
        if ($sequences) {
            $manifest['sequences'] = $sequences;
        }
        $manifest = (object) $manifest;

        return $manifest;
    }

    /**
     * Create an IIIF thumbnail object from an Omeka file.
     *
     * @param Omeka\Api\Representation\MediaRepresentation $file
     * @return Standard object|null
     */
    protected function _iiifThumbnail(MediaRepresentation $media)
    {
        if (empty($media)) {
            return;
        }

        $imageSize = $this->_getImageSize($media, 'square');
        list($width, $height) = array_values($imageSize);
        if (empty($width) || empty($height)) {
            return;
        }

        $thumbnail = array();

        $imageUrl = $this->view->url('universalviewer_image_url', array(
            'id' => $media->id(),
            'region' => 'full',
            'size' => $width . ',' . $height,
            'rotation' => 0,
            'quality' => 'default',
            'format' => 'jpg',
        ));
        $thumbnail['@id'] = $imageUrl;

        $thumbnailService = array();
        $thumbnailService['@context'] = 'http://iiif.io/api/image/2/context.json';
        $thumbnailServiceUrl = $this->view->url('universalviewer_image', array(
            'id' => $media->id(),
        ));
        $thumbnailService['@id'] = $thumbnailServiceUrl;
        $thumbnailService['profile'] = 'http://iiif.io/api/image/2/level2.json';
        $thumbnailService = (object) $thumbnailService;

        $thumbnail['service'] = $thumbnailService;
        $thumbnail = (object) $thumbnail;

        return $thumbnail;
    }

    /**
     * Create an IIIF image object from an Omeka file.
     *
     * @param MediaRepresentation $file
     * @param integer $index Used to set the standard name of the image.
     * @param string $canvasUrl Used to set the value for "on".
     * @param integer $width If not set, will be calculated.
     * @param integer $height If not set, will be calculated.
     * @return Standard object|null
     */
    protected function _iiifImage(MediaRepresentation $media, $index, $canvasUrl, $width = null, $height = null)
    {
        if (empty($media)) {
            return;
        }

        if (empty($width) || empty($height)) {
            $sizeFile = $this->_getImageSize($media, 'original');
            list($width, $height) = array_values($sizeFile);
        }

        $image = array();
        $image['@id'] = $this->_baseUrl . '/annotation/p' . sprintf('%04d', $index) . '-image';
        $image['@type'] = 'oa:Annotation';
        $image['motivation'] = "sc:painting";

        // There is only one image (parallel is not managed currently).
        $imageResource = array();
        $serviceLocator = $this->view->getHelperPluginManager()->getServiceLocator();
        $moduleManager = $serviceLocator->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('OpenLayersZoom');
        if ($module && $module->getState() == ModuleManager::STATE_ACTIVE
            && $this->view->openLayersZoom()->isZoomed($media))
        {
            $sizeFile = $this->_getImageSize($media, 'fullsize');
            list($widthFullsize, $heightFullsize) = array_values($sizeFile);
            $imageUrl = $this->view->url('universalviewer_image_url', array(
                'id' => $media->id(),
                'region' => 'full',
                'size' => $width . ',' . $height,
                'rotation' => 0,
                'quality' => 'default',
                'format' => 'jpg',
            ));
            $imageResource['@id'] = $imageUrl;
            $imageResource['@type'] = 'dctypes:Image';
            $imageResource['format'] = $media->mediaType();
            $imageResource['width'] = $widthFullsize;
            $imageResource['height'] = $heightFullsize;

            $imageResourceService = array();
            $imageResourceService['@context'] = 'http://iiif.io/api/image/2/context.json';

            $imageUrl = $this->view->url('universalviewer_image', array(
                'id' => $media->id(),
            ));
            $imageResourceService['@id'] = $imageUrl;
            $imageResourceService['profile'] = 'http://iiif.io/api/image/2/level2.json';
            $imageResourceService['width'] = $width;
            $imageResourceService['height'] = $height;

            $tile = $this->_iiifTile($media);
            if ($tile) {
                $tiles = array();
                $tiles[] = $tile;
                $imageResourceService['tiles'] = $tiles;
            }
            $imageResourceService = (object) $imageResourceService;

            $imageResource['service'] = $imageResourceService;
            $imageResource = (object) $imageResource;
        }

        // Simple light image.
        else {
            $imageResource['@id'] = $media->originalUrl();
            $imageResource['@type'] = 'dctypes:Image';
            $imageResource['format'] = $media->mediaType();
            $imageResource['width'] = $width;
            $imageResource['height'] = $height;

            $imageResourceService = array();
            $imageResourceService['@context'] = 'http://iiif.io/api/image/2/context.json';

            $imageUrl = $this->view->url('universalviewer_image', array(
                'id' => $media->id(),
            ));
            $imageResourceService['@id'] = $imageUrl;
            $imageResourceService['profile'] = 'http://iiif.io/api/image/2/level2.json';
            $imageResourceService = (object) $imageResourceService;

            $imageResource['service'] = $imageResourceService;
            $imageResource = (object) $imageResource;
        }

        $image['resource'] = $imageResource;
        $image['on'] = $canvasUrl;
        $image = (object) $image;

        return $image;
    }

    /**
     * Create an IIIF canvas object for an image.
     *
     * @param MediaRepresentation $file
     * @param integer $index Used to set the standard name of the image.
     * @return Standard object|null
     */
    protected function _iiifCanvasImage(MediaRepresentation $media, $index)
    {
        $canvas = array();

        $titleFile = $media->value('dcterms:title', array('type' => 'literal'));
        $canvasUrl = $this->_baseUrl . '/canvas/p' . $index;

        $canvas['@id'] = $canvasUrl;
        $canvas['@type'] = 'sc:Canvas';
        $canvas['label'] = $titleFile ?: '[' . $index .']';

        // Thumbnail of the current file.
        $canvas['thumbnail'] = $this->_iiifThumbnail($media);

        // Size of canvas should be the double of small images (< 1200 px), but
        // only when more than image is used by a canvas.
        list($width, $height) = array_values($this->_getImageSize($media, 'original'));
        $canvas['width'] = $width;
        $canvas['height'] = $height;

        $image = $this->_iiifImage($media, $index, $canvasUrl, $width, $height);

        $images = array();
        $images[] = $image;
        $canvas['images'] = $images;

        $canvas = (object) $canvas;

        return $canvas;
    }

    /**
     * Create an IIIF canvas object for a place holder.
     *
     * @return Standard object
     */
    protected function _iiifCanvasPlaceholder()
    {
        $translate = $this->getView()->plugin('translate');

        $canvas = array();
        $canvas['@id'] = $this->view->basePath('/iiif/ixif-message/canvas/c1');
        $canvas['@type'] = 'sc:Canvas';
        $canvas['label'] = $translate('Placeholder image');

        $placeholder = 'images/placeholder.jpg';
        $canvas['thumbnail'] = $this->view->assetUrl($placeholder, 'UniversalViewer');

        $imageSize = $this->_getWidthAndHeight(OMEKA_PATH . '/modules/UniversalViewer/asset/' . $placeholder);
        $canvas['width'] = $imageSize['width'];
        $canvas['height'] = $imageSize['height'];

        $image = array();
        $image['@id'] = $this->view->basePath('/iiif/ixif-message/imageanno/placeholder');
        $image['@type'] = 'oa:Annotation';
        $image['motivation'] = "sc:painting";

        // There is only one image (parallel is not managed).
        $imageResource = array();
        $imageResource['@id'] = $this->view->basePath('/iiif/ixif-message-0/res/placeholder');
        $imageResource['@type'] = 'dctypes:Image';
        $imageResource['width'] = $imageSize['width'];
        $imageResource['height'] = $imageSize['height'];
        $imageResource = (object) $imageResource;

        $image['resource'] = $imageResource;
        $image['on'] = $this->view->basePath('/iiif/ixif-message/canvas/c1');
        $image = (object) $image;
        $images = array($image);

        $canvas['images'] = $images;

        $canvas = (object) $canvas;

        return $canvas;
    }

    /**
     * Create an IIIF sequence object for an unsupported format.
     *
     * @param array $rendering
     * @return Standard object
     */
    protected function _iiifSequenceUnsupported($rendering = array())
    {
        $sequence = array();
        $sequence['@id'] = $this->_baseUrl . '/sequence/normal';
        $sequence['@type'] = 'sc:Sequence';
        $sequence['label'] = $this->view->translate('Unsupported extension. This manifest is being used as a wrapper for non-IIIF content (e.g., audio, video) and is unfortunately incompatible with IIIF viewers.');
        $sequence['compatibilityHint'] = 'displayIfContentUnsupported';

        $canvas = $this->_iiifCanvasPlaceholder();

        $canvases = array();
        $canvases[] = $canvas;

        if ($rendering) {
            $sequence['rendering'] = $rendering;
        }
        $sequence['canvases'] = $canvases;
        $sequence = (object) $sequence;

        return $sequence;
    }

    /**
     * Create an IIIF tile object for a place holder.
     *
     * @internal The method uses the Zoomify format of OpenLayersZoom.
     *
     * @param MediaRepresentation $file
     * @return Standard object or null if no tile.
     * @see UniversalViewer_View_Helper_IiifInfo::_iiifTile()
     */
    protected function _iiifTile(MediaRepresentation $media)
    {
        $tile = array();

        $tileProperties = $this->_getTileProperties($media);
        if (empty($tileProperties)) {
            return;
        }

        $squaleFactors = array();
        $maxSize = max($tileProperties['source']['width'], $tileProperties['source']['height']);
        $tileSize = $tileProperties['size'];
        $total = (integer) ceil($maxSize / $tileSize);
        $factor = 1;
        while ($factor / 2 <= $total) {
            $squaleFactors[] = $factor;
            $factor = $factor * 2;
        }
        if (count($squaleFactors) <= 1) {
            return;
        }

        $tile['width'] = $tileSize;
        $tile['scaleFactors'] = $squaleFactors;
        $tile = (object) $tile;
        return $tile;
    }

    /**
     * Return the properties of a tiled file.
     *
     * @return array|null
     * @see UniversalViewer_ImageController::_getTileProperties()
     */
    protected function _getTileProperties(MediaRepresentation $media)
    {
        $olz = new OpenLayersZoom_Creator();
        $dirpath = $olz->useIIPImageServer()
            ? $olz->getZDataWeb($media)
            : $olz->getZDataDir($media);
        $properties = simplexml_load_file($dirpath . '/ImageProperties.xml');
        if ($properties === false) {
            return;
        }
        $properties = $properties->attributes();
        $properties = reset($properties);

        // Standardize the properties.
        $result = array();
        $result['size'] = (integer) $properties['TILESIZE'];
        $result['total'] = (integer) $properties['NUMTILES'];
        $result['source']['width'] = (integer) $properties['WIDTH'];
        $result['source']['height'] = (integer) $properties['HEIGHT'];
        return $result;
    }

    /**
     * Get an array of the width and height of the image file.
     *
     * @internal The process uses the saved constraints. It they are changed but
     * the derivative haven't been rebuilt, the return will be wrong (but
     * generally without consequences for BookReader).
     *
     * @param MediaRepresentation $file
     * @param string $imageType
     * @return array Associative array of width and height of the image file.
     * If the file is not an image, the width and the height will be null.
     * @see UniversalViewer_View_Helper_IiifInfo::_getImageSize()
     */
    protected function _getImageSize(MediaRepresentation $media, $imageType = 'original')
    {
        $serviceLocator = $this->view->getHelperPluginManager()->getServiceLocator();

        // Check if this is an image.
        if (empty($media) || strpos($media->mediaType(), 'image/') !== 0) {
            return array('width' => null, 'height' => null);
        }

        // This is an image.
        // Get the resolution directly.
        // The storage adapter should be checked for external storage.
        $fileManager = $serviceLocator->get('Omeka\File\Manager');
        if ($imageType == 'original') {
            $storagePath = $fileManager->getStoragePath($imageType, $media->filename());
        } else {
            $basename = $fileManager->getBasename($media->filename());
            $storagePath = $fileManager->getStoragePath($imageType, $basename, FileManager::THUMBNAIL_EXTENSION);
        }
        $filepath = OMEKA_PATH . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $storagePath;
        $result = $this->_getWidthAndHeight($filepath);

        if (empty($result['width']) || empty($result['height'])) {
            throw new Exception("Failed to get image resolution: $filepath");
        }

        return $result;
    }

    /**
     * Helper to get width and height of an image.
     *
     * @param string $filepath This should be an image (no check here).
     * @return array Associative array of width and height of the image file.
     * If the file is not an image, the width and the height will be null.
     * @see UniversalViewer_ImageController::_getWidthAndHeight()
     */
    protected function _getWidthAndHeight($filepath)
    {
        if (file_exists($filepath)) {
            list($width, $height, $type, $attr) = getimagesize($filepath);
            return array(
                'width' => $width,
                'height' => $height,
            );
        }

        return array(
            'width' => null,
            'height' => null,
        );
    }
}
