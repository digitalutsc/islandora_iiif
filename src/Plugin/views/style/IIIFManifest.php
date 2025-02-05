<?php

namespace Drupal\islandora_iiif\Plugin\views\style;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\views\ResultRow;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\termwithuri_condition\Utils;

/**
 * Provide serializer format for IIIF Manifest.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "iiif_manifest",
 *   title = @Translation("IIIF Manifest"),
 *   help = @Translation("Display images as an IIIF Manifest."),
 *   display_types = {"data"}
 * )
 */
class IIIFManifest extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  /**
   * The allowed formats for this serializer. Default to only JSON.
   *
   * @var array
   */
  protected $formats = ['json'];

  /**
   * The serializer which serializes the views result.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The request service.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * This module's config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $iiifConfig;

  /**
   * The Drupal Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Drupal Filesystem.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The Guzzle HTTP Client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Module Handler for running hooks.
   *
   * @var \Drupal\Core\Extention\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Memoized structured text term.
   *
   * @var \Drupal\taxonomy\TermInterface|null
   */
  protected ?TermInterface $structuredTextTerm;

  /**
   * Flag to track if we _have_ attempted a lookup, as the value is nullable.
   *
   * @var bool
   */
  protected bool $structuredTextTermMemoized = FALSE;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, SerializerInterface $serializer, Request $request, ImmutableConfig $iiif_config, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, Client $http_client, MessengerInterface $messenger, ModuleHandlerInterface $moduleHandler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->serializer = $serializer;
    $this->request = $request;
    $this->iiifConfig = $iiif_config;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->messenger = $messenger;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('serializer'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('config.factory')->get('islandora_iiif.settings'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('messenger'),
      $container->get('module_handler')
    );
  }

  /**
   * Return the request property.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The Symfony request object
   */
  public function getRequest() {
    return $this->request;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $json = [];
    $iiif_address = $this->iiifConfig->get('iiif_server');
    if (!is_null($iiif_address) && !empty($iiif_address)) {
      // Get the current URL being requested.
      $request_host = $this->request->getSchemeAndHttpHost();
      $request_url = $this->request->getRequestUri();
      // Strip off the last URI component to get the base ID of the URL.
      // @todo assumming the view is a path like /node/1/manifest.json
      $url_components = explode('/', trim($request_url, '/'));
      array_pop($url_components);
      $content_path = '/' . implode('/', $url_components);
      $iiif_base_id = "{$request_host}{$content_path}";
      $display = $this->iiifConfig->get('show_title');
      switch ($display) {
        case 'none':
          $label = '';
          break;

        case 'view':
          $label = $this->view->getTitle();
          break;

        case 'node':
          $label = $this->getEntityTitle($content_path);

          break;

        default:
          $label = $this->t("IIIF Manifest");
      }

      // @see https://iiif.io/api/presentation/2.1/#manifest
      $json += [
        '@type' => 'sc:Manifest',
        '@id' => $request_url,
        // If the View has a title, set the View title as the manifest label.
        'label' => $label,
        '@context' => 'http://iiif.io/api/presentation/2/context.json',
        // @see https://iiif.io/api/presentation/2.1/#sequence
        'sequences' => [
          [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            '@id' => $iiif_base_id . '/sequence/normal',
            '@type' => 'sc:Sequence',
          ],
        ],
      ];
      // For each row in the View result.
      foreach ($this->view->result as $row) {
        // Add the IIIF URL to the image to print out as JSON.
        $canvases = $this->getTileSourceFromRow($row, $iiif_address, $iiif_base_id);
        foreach ($canvases as $tile_source) {
          $json['sequences'][0]['canvases'][] = $tile_source;
        }
      }
    }
    unset($this->view->row_index);

    $content_type = 'json';

    // Add a search endpoint if one is defined.
    $this->addSearchEndpoint($json, $url_components);

    // Give other modules a chance to alter the manifest.
    $this->moduleHandler->alter('islandora_iiif_manifest', $json, $this);

    return $this->serializer->serialize($json, $content_type, ['views_style_plugin' => $this]);
  }

  /**
   * Render array from views result row.
   *
   * @param \Drupal\views\ResultRow $row
   *   Result row.
   * @param string $iiif_address
   *   The URL to the IIIF server endpoint.
   * @param string $iiif_base_id
   *   The URL for the request, minus the last part of the URL,
   *   which is likely "manifest".
   *
   * @return array
   *   List of IIIF URLs to display in the Openseadragon viewer.
   */
  protected function getTileSourceFromRow(ResultRow $row, $iiif_address, $iiif_base_id) {
    $canvases = [];
    foreach (array_filter(array_values($this->options['iiif_tile_field'])) as $iiif_tile_field) {
      $viewsField = $this->view->field[$iiif_tile_field];
      $entity = $viewsField->getEntity($row);

      if (isset($entity->{$viewsField->definition['field_name']})) {
        /** @var \Drupal\Core\Field\FieldItemListInterface $images */
        $images = $entity->{$viewsField->definition['field_name']};
        foreach ($images as $i => $image) {
          if (!$image->entity->access('view')) {
            // If the user does not have permission to view the file, skip it.
            continue;
          }

          // Create the IIIF URL for this file
          // Visiting $iiif_url will resolve to the info.json for the image.
          if ($this->iiifConfig->get('use_relative_paths')) {
            $file_url = ltrim($image->entity->createFileUrl(TRUE), '/');
          }
          else {
            $file_url = $image->entity->createFileUrl(FALSE);
          }

          $mime_type = $image->entity->getMimeType();
          $iiif_url = rtrim($iiif_address, '/') . '/' . urlencode($file_url);

          // Create the necessary ID's for the canvas and annotation.
          $canvas_id = $iiif_base_id . '/canvas/' . $entity->id();
          $annotation_id = $iiif_base_id . '/annotation/' . $entity->id();

          [$width, $height] = $this->getCanvasDimensions($iiif_url, $image, $mime_type);

          $tmp_canvas = [
            // @see https://iiif.io/api/presentation/2.1/#canvas
            '@id' => $canvas_id,
            '@type' => 'sc:Canvas',
            'label' => $image->entity->label(),
            'height' => $height,
            'width' => $width,
            // @see https://iiif.io/api/presentation/2.1/#image-resources
            'images' => [
              [
                '@id' => $annotation_id,
                "@type" => "oa:Annotation",
                'motivation' => 'sc:painting',
                'resource' => [
                  '@id' => $iiif_url . '/full/full/0/default.jpg',
                  "@type" => "dctypes:Image",
                  'format' => $mime_type,
                  'height' => $height,
                  'width' => $width,
                  'service' => [
                    '@id' => $iiif_url,
                    '@context' => 'http://iiif.io/api/image/2/context.json',
                    'profile' => 'http://iiif.io/api/image/2/profiles/level2.json',
                  ],
                ],
                'on' => $canvas_id,
              ],
            ],
          ];

          $node_id = null;
          if (preg_match('/\/node\/(\d+)/', $iiif_base_id, $matches)) {
            $node_id = $matches[1];
          }

          if ($ocr_url = $this->getOcrUrl($entity, $node_id)) {
            $tmp_canvas['seeAlso'] = [
              '@id' => $ocr_url,
              'format' => 'text/vnd.hocr+html',
              'profile' => 'http://kba.cloud/hocr-spec',
              'label' => 'hOCR embedded text',
            ];
          }

          // Give other modules a chance to alter the canvas.
          $alter_options = [
            'options' => $this->options,
            'views_plugin' => $this,
          ];
          $this->moduleHandler->alter('islandora_iiif_manifest_canvas', $tmp_canvas, $row, $alter_options);

          $canvases[] = $tmp_canvas;
        }
      }
    }

    return $canvases;
  }

  /**
   * Try to fetch the IIIF metadata for the image.
   *
   * @param string $iiif_url
   *   Base URL of the canvas.
   * @param \Drupal\Core\Field\FieldItemInterface $image
   *   The image field.
   * @param string $mime_type
   *   The mime type of the image.
   *
   * @return [string]
   *   The width and height of the image.
   */
  protected function getCanvasDimensions(string $iiif_url, FieldItemInterface $image, string $mime_type) {

    if (isset($image->width) && is_numeric($image->width)
    && isset($image->height) && is_numeric($image->height)) {
      return [intval($image->width), intval($image->height)];
    }

    try {
      // For dsu-utsc.
      if (!empty(\Drupal::hasService('jwt.authentication.jwt'))) {
        $jwtService = \Drupal::service('jwt.authentication.jwt');
        $token = $jwtService->generateToken();
        $info_json = $this->httpClient->get($iiif_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ])->getBody();
      }
      else {
        $info_json = $this->httpClient->get($iiif_url)->getBody();
      }
      $resource = json_decode($info_json, TRUE);
      $width = $resource['width'];
      $height = $resource['height'];
    }
    catch (ClientException | ServerException | ConnectException $e) {
      // If we couldn't get the info.json from IIIF
      // try seeing if we can get it from Drupal.
      if (empty($width) || empty($height)) {
        // Get the image properties so we know the image width/height.
        $properties = $image->getProperties();
        $width = isset($properties['width']) ? $properties['width'] : 0;
        $height = isset($properties['height']) ? $properties['height'] : 0;

        // If this is a TIFF AND we don't know the width/height
        // see if we can get the image size via PHP's core function.
        if ($mime_type === 'image/tiff' && (!$width || !$height)) {
          $uri = $image->entity->getFileUri();
          $path = $this->fileSystem->realpath($uri);
          $image_size = getimagesize($path);
          if ($image_size) {
            $width = $image_size[0];
            $height = $image_size[1];
          }
        }
      }
    }
    return [$width, $height];
  }

  /**
   * Retrieves a URL text with positional data such as hOCR.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity at the current row.
   * @param int $id
   *   The ID of the book node.
   *
   * @return string|false
   *   The absolute URL of the current row's structured text,
   *   or FALSE if none.
   */
  protected function getOcrUrl(EntityInterface $entity, int $id) {
    $ocr_url = FALSE;
    $iiif_ocr_file_field = !empty($this->options['iiif_ocr_file_field']) ? array_filter(array_values($this->options['iiif_ocr_file_field'])) : [];
    $ocrField = count($iiif_ocr_file_field) > 0 ? $this->view->field[$iiif_ocr_file_field[0]] : NULL;
    if ($ocrField) {
      $ocr_entity = $entity;
      $ocr_field_name = $ocrField->definition['field_name'];
      if (!is_null($ocr_field_name)) {
        $ocrs = $ocr_entity->{$ocr_field_name};
        $ocr = $ocrs[0] ?? FALSE;
        if ($ocr) {
          $ocr_url = $ocr->entity->createFileUrl(FALSE);
        }
      }
    }
    elseif ($structured_text_term = $this->getStructuredTextTerm()) {
      $parent_node = $this->getParentNode($entity, $id);
      $ocr_entity_array = Utils::getMediaReferencingNodeAndTerm($parent_node, $structured_text_term);
      $ocr_entity_id = is_array($ocr_entity_array) ? array_shift($ocr_entity_array) : NULL;
      $ocr_entity = $ocr_entity_id ? $this->entityTypeManager->getStorage('media')->load($ocr_entity_id) : NULL;
      if ($ocr_entity) {
        $ocr_file_source = $ocr_entity->getSource();
        $ocr_fid = $ocr_file_source->getSourceFieldValue($ocr_entity);
        $ocr_file = $this->entityTypeManager->getStorage('file')->load($ocr_fid);
        $ocr_url = $ocr_file->createFileUrl(FALSE);
      }
    }

    return $ocr_url;
  }

  /**
   * Gets nodes that a media belongs to.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The Media whose node you are searching for.
   * @param int $id
   *   The ID of the book node.
   *
   * @return \Drupal\node\NodeInterface
   *   Parent node.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Method $field->first() throws if data structure is unset and no item can
   *   be created.
   */
  protected function getParentNode(EntityInterface $entity, int $id) {
    if (!$id) {
      return NULL;
    }
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $nids = $query->accessCheck(TRUE)->condition('field_part_of', $id)->execute();
    if (!empty($nids)) {
      foreach ($nids as $nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        $media_ids = array_map(function ($item) {
          return $item['target_id'];
        }, $node->get('field_islandora_object_media')->getValue());
        if (in_array($entity->id(), $media_ids)) {
          return $node;
        }
      }
    }
    return NULL;
  }
  
  /**
   * Pull a title from the node or media passed to this view.
   *
   * @param string $content_path
   *   The path of the content being requested.
   *
   * @return string
   *   The entity's title.
   */
  public function getEntityTitle(string $content_path): string {
    $entity_title = $this->t('IIIF Manifest');
    try {
      $params = Url::fromUserInput($content_path)->getRouteParameters();
      if (isset($params['node'])) {
        $node = $this->entityTypeManager->getStorage('node')->load($params['node']);
        $entity_title = $node->getTitle();
      }
      elseif (isset($params['media'])) {
        $media = $this->entityTypeManager->getStorage('media')->load($params['media']);
        $entity_title = $media->getName();
      }
    }
    catch (\InvalidArgumentException $e) {

    }
    return $entity_title;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['iiif_tile_field'] = ['default' => ''];
    $options['iiif_ocr_file_field'] = ['default' => ''];

    return $options;
  }

  /**
   * Add the configured search endpoint to the manifest.
   *
   * @param array $json
   *   The IIIF manifest.
   * @param array $url_components
   *   The search endpoint URL as array.
   */
  protected function addSearchEndpoint(array &$json, array $url_components) {
    $url_base = $this->getRequest()->getSchemeAndHttpHost();
    $hocr_search_path = $this->options['search_endpoint'] ?? NULL;

    if ($hocr_search_path) {
      $hocr_search_url = $url_base . '/' . ltrim($hocr_search_path, '/');

      $hocr_search_url = str_replace('%node', $url_components[1], $hocr_search_url);

      $json['service'][] = [
        "@context" => "http://iiif.io/api/search/0/context.json",
        "@id" => $hocr_search_url,
        "profile" => "http://iiif.io/api/search/0/search",
        "label" => t("Search inside this work"),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $field_options = [];

    $fields = $this->displayHandler->getHandlers('field');
    $islandora_default_file_fields = [
      'field_media_file',
      'field_media_image',
    ];
    $file_views_field_formatters = [
      // Image formatters.
      'image', 'image_url',
      // File formatters.
      'file_default', 'file_url_plain',
    ];
    /** @var \Drupal\views\Plugin\views\field\FieldPluginBase[] $fields */
    foreach ($fields as $field_name => $field) {
      // If this is a known Islandora file/image field
      // OR it is another/custom field add it as an available option.
      // @todo find better way to identify file fields
      // Currently $field->options['type'] is storing the "formatter" of the
      // file/image so there are a lot of possibilities.
      // The default formatters are 'image' and 'file_default'
      // so this approach should catch most...
      if (in_array($field_name, $islandora_default_file_fields) ||
        (!empty($field->options['type']) && in_array($field->options['type'], $file_views_field_formatters))) {
        $field_options[$field_name] = $field->adminLabel();
      }
    }

    // If no fields to choose from, add an error message indicating such.
    if (count($field_options) == 0) {
      $this->messenger->addMessage($this->t('No image or file fields were found in the View.
        You will need to add a field to this View'), 'error');
    }

    $form['iiif_tile_field'] = [
      '#title' => $this->t('Tile source field(s)'),
      '#type' => 'checkboxes',
      '#default_value' => $this->options['iiif_tile_field'],
      '#description' => $this->t("The source of image for each entity."),
      '#options' => $field_options,
      // Only make the form element required if
      // we have more than one option to choose from
      // otherwise could lock up the form when setting up a View.
      '#required' => count($field_options) > 0,
    ];

    $form['iiif_ocr_file_field'] = [
      '#title' => $this->t('Structured OCR data file field'),
      '#type' => 'checkboxes',
      '#default_value' => $this->options['iiif_ocr_file_field'],
      '#description' => $this->t("If the hOCR is a field on the same entity as the image source  field above, select it here. If it's found in a related entity via the term below, leave this blank."),
      '#options' => $field_options,
      '#required' => FALSE,
    ];

    $form['structured_text_term'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#title' => $this->t('Structured OCR text term'),
      '#default_value' => $this->getStructuredTextTerm(),
      '#required' => FALSE,
      '#description' => $this->t('Term indicating the media that holds structured text, such as hOCR, for the given object. Use this if the text is on a separate media from the tile source.'),
    ];

    $form['search_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Search endpoint path."),
      '#description' => $this->t("If there is a search endpoint to search within the book that returns IIIF annotations, put it here. Use %node substitution where needed.<br>E.g., paged-content-search/%node"),
      '#default_value' => $this->options['search_endpoint'],
      '#required' => FALSE,
    ];
  }

  /**
   * Returns an array of format options.
   *
   * @return string[]
   *   An array of the allowed serializer formats. In this case just JSON.
   */
  public function getFormats() {
    return ['json' => 'json'];
  }

  /**
   * Submit handler for options form.
   *
   * Used to store the structured text media term by URL instead of Ttid.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   */
  // @codingStandardsIgnoreStart
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    // @codingStandardsIgnoreEnd
    $style_options = $form_state->getValue('style_options');
    $tid = $style_options['structured_text_term'];
    unset($style_options['structured_text_term']);
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    $style_options['structured_text_term_uri'] = Utils::getUriForTerm($term);
    $form_state->setValue('style_options', $style_options);
    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * Get the structured text term.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The term if it could be found; otherwise, NULL.
   */
  protected function getStructuredTextTerm() : ?TermInterface {
    if (!$this->structuredTextTermMemoized) {
      $this->structuredTextTermMemoized = TRUE;
      $this->structuredTextTerm = Utils::getTermForUri($this->options['structured_text_term_uri']);
    }

    return $this->structuredTextTerm;
  }

}
