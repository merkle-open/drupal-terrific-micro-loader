<?php

namespace Drupal\terrific_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystem;
use Drupal\terrific_integration\AssetManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terrific integration.
 */
class TerrificIntegrationController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public function getAsset() {
    $frontendBase =  DRUPAL_ROOT . '/../../frontend/';

    if (!file_exists($frontendBase . 'config.json')) {
      return new Response('Config not found', 404);
    }

    $config = json_decode(file_get_contents($frontendBase . 'config.json'));
    $asset_name = isset($_GET['name']) ? $_GET['name'] : NULL;

    if (!$asset_name) {
      return new Response('Missing name parameter', 404);
    }
    if (!isset($config->assets->{$asset_name})) {
      return new Response('Invalid asset name', 404);
    }

    $data = $config->assets->{$asset_name};
    if (empty($data)) {
      return new Response('Empty dataset by given config', 404);
    }

    $assetManager = new AssetManager($frontendBase, $data, $asset_name);
    $assetManager->dump();

    return new Response($assetManager->getOutput(), 200, $assetManager->getHeaders());
  }

}
