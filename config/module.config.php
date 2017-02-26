<?php
return [
    'block_layouts' => [
        'invokables' => [
            'universalViewer' => 'UniversalViewer\Site\BlockLayout\UniversalViewer',
        ],
    ],
    'controllers' => [
        'invokables' => [
            'UniversalViewer\Controller\Player' => 'UniversalViewer\Controller\PlayerController',
            'UniversalViewer\Controller\Presentation' => 'UniversalViewer\Controller\PresentationController',
        ],
        'factories' => [
            'UniversalViewer\Controller\Image' => 'UniversalViewer\Service\Controller\ImageControllerFactory',
            'UniversalViewer\Controller\Media' => 'UniversalViewer\Service\Controller\MediaControllerFactory',
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'jsonLd' => 'UniversalViewer\Mvc\Controller\Plugin\JsonLd',
        ],
    ],
    'router' => [
        'routes' => [
            'universalviewer_player' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/:resourcename/:id/play',
                    'constraints' => [
                        'resourcename' => 'item|item\-set',
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Player',
                        'action' => 'play',
                    ],
                ],
            ],

            // @todo It is recommended to use a true identifier (ark, urn...], not an internal id.

            // @link http://iiif.io/api/presentation/2.0
            // Collection     {scheme}://{host}/{prefix}/collection/{name}
            // Manifest       {scheme}://{host}/{prefix}/{identifier}/manifest
            // Sequence       {scheme}://{host}/{prefix}/{identifier}/sequence/{name}
            // Canvas         {scheme}://{host}/{prefix}/{identifier}/canvas/{name}
            // Annotation     {scheme}://{host}/{prefix}/{identifier}/annotation/{name}
            // AnnotationList {scheme}://{host}/{prefix}/{identifier}/list/{name}
            // Range          {scheme}://{host}/{prefix}/{identifier}/range/{name}
            // Layer          {scheme}://{host}/{prefix}/{identifier}/layer/{name}
            // Content        {scheme}://{host}/{prefix}/{identifier}/res/{name}.{format}

            // @link http://iiif.io/api/image/2.0
            // Image          {scheme}://{server}{/prefix}/{identifier}

            // For collections, the spec doesn't specify a name for the manifest itself.
            // Libraries use an empty name or "manifests", "manifest.json", "manifest",
            // "{id}.json", etc. Here, an empty name is used, and a second route is added.
            // Invert the names of the route to use the generic name for the manifest itself.
            'universalviewer_presentation_collection' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif/collection/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Presentation',
                        'action' => 'collection',
                    ],
                ],
            ],
            'universalviewer_presentation_collection_redirect' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif/collection/:id/manifest',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Presentation',
                        'action' => 'collection',
                    ],
                ],
            ],
            'universalviewer_presentation_item' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif/:id/manifest',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Presentation',
                        'action' => 'item',
                    ],
                ],
            ],
            // The redirection is not required for presentation, but a forward is possible.
            'universalviewer_presentation_item_redirect' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Presentation',
                        'action' => 'item',
                    ],
                ],
            ],
            // A redirect to the info.json is required by the specification.
            'universalviewer_image' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif-img/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Image',
                        'action' => 'index',
                    ],
                ],
            ],
            'universalviewer_image_info' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif-img/:id/info.json',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Image',
                        'action' => 'info',
                    ],
                ],
            ],
            // This route is a garbage collector that allows to return an error 400 or 501 to
            // invalid or not implemented requests, as required by specification.
            // This route should be set before the universalviewer_image in order to be
            // processed after it.
            // TODO Simplify to any number of sub elements.
            'universalviewer_image_bad' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif-img/:id/:region/:size/:rotation/:quality:.:format',
                    'constraints' => [
                        'id' => '\d+',
                        'region' => '.+',
                        'size' => '.+',
                        'rotation' => '.+',
                        'quality' => '.+',
                        'format' => '.+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Image',
                        'action' => 'bad',
                    ],
                ],
            ],
            // Warning: the format is separated with a ".", not a "/".
            'universalviewer_image_url' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/iiif-img/:id/:region/:size/:rotation/:quality:.:format',
                    'constraints' => [
                        'id' => '\d+',
                        'region' => 'full|\d+,\d+,\d+,\d+|pct:\d+\.?\d*,\d+\.?\d*,\d+\.?\d*,\d+\.?\d*',
                        'size' => 'full|\d+,\d*|\d*,\d+|pct:\d+\.?\d*|!\d+,\d+',
                        // TODO Max length of floating number is 10. No arbitrary rotation currently.
                        'rotation' => '0|90|180|270',
                        'quality' => 'default|color|gray|bitonal',
                        'format' => 'jpg|png|gif',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Image',
                        'action' => 'fetch',
                    ],
                ],
            ],
            // A redirect to the info.json is required by the specification.
            'universalviewer_media' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/ixif-media/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Media',
                        'action' => 'index',
                    ],
                ],
            ],
            'universalviewer_media_info' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/ixif-media/:id/info.json',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Media',
                        'action' => 'info',
                    ],
                ],
            ],
            // This route is a garbage collector that allows to return an error 400 or 501 to
            // invalid or not implemented requests, as required by specification.
            // This route should be set before the universalviewer_media in order to be
            // processed after it.
            'universalviewer_media_bad' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/ixif-media/:id:.:format',
                    'constraints' => [
                        'id' => '\d+',
                        'format' => '.+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Media',
                        'action' => 'bad',
                    ],
                ],
            ],
            // Warning: the format is separated with a ".", not a "/".
            'universalviewer_media_url' => [
                'type' => 'segment',
                'options' => [
                    'route' => '/ixif-media/:id:.:format',
                    'constraints' => [
                        'id' => '\d+',
                        'format' => 'pdf|mp3|ogg|mp4|webm|ogv',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'UniversalViewer\Controller',
                        'controller' => 'Media',
                        'action' => 'fetch',
                    ],
                ],
            ],

            // For IxIF, some json files should be available to describe media for context.
            // This is not used currently: the Wellcome uris are kept because they are set
            // for main purposes in UniversalViewer.
            // @link https://gist.github.com/tomcrane/7f86ac08d3b009c8af7c

            // If really needed, the three next routes may be uncommented to
            // keep compatibility with the old schemes used by the plugin for
            // Omeka 2 before the version 2.4.2.
            // 'universalviewer_player_classic' => [
            //     'type' => 'segment',
            //     'options' => [
            //         'route' => '/:resourcename/play/:id',
            //         'constraints' => [
            //             'resourcename' => 'item|items|item\-set|item_set|collection|item\-sets|item_sets|collections',
            //             'id' => '\d+',
            //         ],
            //         'defaults' => [
            //             '__NAMESPACE__' => 'UniversalViewer\Controller',
            //             'controller' => 'Player',
            //             'action' => 'play',
            //         ],
            //     ],
            // ],
            // 'universalviewer_presentation_classic' => [
            //     'type' => 'segment',
            //     'options' => [
            //         'route' => '/:resourcename/presentation/:id',
            //         'constraints' => [
            //             'resourcename' => 'item|items|item\-set|item_set|collection|item\-sets|item_sets|collections',
            //             'id' => '\d+',
            //         ],
            //         'defaults' => [
            //             '__NAMESPACE__' => 'UniversalViewer\Controller',
            //             'controller' => 'Presentation',
            //             'action' => 'manifest',
            //         ],
            //     ],
            // ],
            // 'universalviewer_presentation_manifest_classic' => [
            //     'type' => 'segment',
            //     'options' => [
            //         'route' => '/:resourcename/presentation/:id/manifest',
            //         'constraints' => [
            //             'resourcename' => 'item|items|item\-set|item_set|collection|item\-sets|item_sets|collections',
            //             'id' => '\d+',
            //         ],
            //         'defaults' => [
            //             '__NAMESPACE__' => 'UniversalViewer\Controller',
            //             'controller' => 'Presentation',
            //             'action' => 'manifest',
            //         ],
            //     ],
            // ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            OMEKA_PATH . '/modules/UniversalViewer/view',
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'universalViewer' => 'UniversalViewer\View\Helper\UniversalViewer',
            'iiifCollection' => 'UniversalViewer\View\Helper\IiifCollection',
            'uvForceHttpsIfRequired' => 'UniversalViewer\View\Helper\UvForceHttpsIfRequired',
        ],
        'factories' => [
            'iiifInfo' => 'UniversalViewer\Service\ViewHelper\IiifInfoFactory',
            'iiifManifest' => 'UniversalViewer\Service\ViewHelper\IiifManifestFactory',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
