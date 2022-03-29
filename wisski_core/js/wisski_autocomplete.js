/**
 * @file
 * Expands the behaviour of the default autocompletion.
 */

(function ($, Drupal) {

  // Override the "select" option of the jQueryUI autocomplete
  // to make sure we do not use quotes for inputs with comma.
  Drupal.autocomplete.options.select = function (event, ui) {

    //var terms = Drupal.autocomplete.splitValues(event.target.value);
    // start empty, otherwise cases with a , do not get created correctly...
    var terms = [];
    
    // Remove the current input.
    //terms.pop();
    // Add the selected item.
    terms.push(ui.item.value);
    event.target.value = terms.join(', ');
    // Return false to tell jQuery UI that we've filled in the value already.
    return false;
  };

 //Overrides the default extractLastTerm found in core/misc/autocomplete.js
  Drupal.autocomplete.extractLastTerm = function extractLastTerm(terms) {
    return terms;
    //alert(terms);
    //alert("yay?");
        //Implement your override behavior here
  };


})(jQuery, Drupal);