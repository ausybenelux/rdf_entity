<?php

namespace Drupal\rdf_file\Controller;

use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects to the file location.
 *
 * This makes sure that when the SPARQL endpoint is used externally;
 * that the files can be dereferenced.
 */
class RdfFileRedirect {

  /**
   * Redirect to the actual file.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file object.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect object.
   */
  function redirect(File $file) {
    $url = $file->url();
    return new RedirectResponse($url);
  }

}