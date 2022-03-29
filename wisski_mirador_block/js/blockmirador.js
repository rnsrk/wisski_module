(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.wisski_mirador_block_Behavior = {
    attach: function (context, settings) {
      $('div#block-miradorblockviewer', context).once('wisski_mirador_block').each(function (element) {

    const iiif_field = drupalSettings.blockmirador.iiif_field;
console.log(iiif_field);
    const viewer_height = drupalSettings.blockmirador.viewer_height;
    
    const iiif_manifest = $('div.field--name-'+ iiif_field + ' div.field__item').text();
    
console.log(iiif_manifest);
    if (iiif_manifest.length > 0){
      if (iiif_manifest.includes('manifest') ){
        const mirador = Mirador.viewer({
          id: "mirador_block",
          allowFullscreen: true,
          windows: [
              {manifestId: iiif_manifest}
          ],
          catalog: [
            { manifestId: iiif_manifest },
          ]
          // All of the settings (with descriptions (ﾉ^∇^)ﾉﾟ) located here:
          // https://github.com/ProjectMirador/mirador/blob/master/src/config/settings.js
        });
      };
      $('#mirador_block').height(viewer_height);
    } else {
      $("#mirador_block").hide();
    };

    
 });
    }
}
})(jQuery, Drupal, drupalSettings, once);