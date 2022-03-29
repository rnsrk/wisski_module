Drupal.behaviors.wisski_pathbuilder_solr = {
  attach: function (context, settings) {

    // Attach a click listener to the clear button.
    var clearBtn = document.getElementById('edit-with-solr');
    clearBtn.addEventListener('click', function() {

      var els = document.getElementsByClassName("wki-pb-solr");
      
      Array.prototype.forEach.call(els, function(el) {
        if(el.style.display === "none") {
          el.style.display = "block";
        } else {
          el.style.display = "none";
        }
         
      });

    }, false);

  }
};