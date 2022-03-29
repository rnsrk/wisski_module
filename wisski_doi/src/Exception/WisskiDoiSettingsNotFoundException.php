<?php

namespace Drupal\wisski_doi\Exception;

/**
 * Exception class for checking DOI settings.
 *
 * Load the settings from the DOI configuration page and
 * checks if values are missing.
 */
class WisskiDoiSettingsNotFoundException extends \Exception {

  /**
   * Check if settings are set.
   *
   * @throws \Drupal\wisski_doi\Exception\WisskiDoiSettingsNotFoundException
   */
  public function checkDoiSetting($doiSettings) {
    foreach ($doiSettings as $setting => $value) {
      if (empty($value)) {
        throw new WisskiDoiSettingsNotFoundException("'$setting' not set, please go to Configure->[WISSKI]->WissKI DOI settings and do so.");
      }
    }
  }

}
