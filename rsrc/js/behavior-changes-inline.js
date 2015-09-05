/**
 * @provides javelin-behavior-changes-inline
 * @requires javelin-behavior
 *           javelin-dom
 *           javelin-json
 *           javelin-request
 */

JX.behavior('changes-inline', function(config) {
  var container = JX.$(config.div_id);
  
  var uri = '/changes/inline/?' +
    'revision_id=' + config.revision_id +
    '&diff_id=' + config.diff_id;

  var request = new JX.Request(
    uri,
    function(r) {
      JX.DOM.setContent(container, JX.$H(r));
    }
  );

  request.listen('error', function(error) {
    JX.DOM.setContent(
      container, 
      "There was an internal error fetching this data (" +
      error.code + ": " + error.info +
      ")");
  });

  // power the expand on click herald link
  JX.Stratcom.listen(
    // for mobile support, can copy what behavior-toggle-class does
    ['click'],
    'changes-herald-link',
    function(e) {
      e.kill();

      var t = e.getNodeData('changes-herald-link');
      JX.DOM.hide(JX.$(t.myID));
      JX.DOM.show(JX.$(t.heraldID));
    }
  );

  request.send();
});
