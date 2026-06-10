(function (blocks, components, element, i18n, serverSideRender) {
  var el = element.createElement;
  var ServerSideRender = serverSideRender;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var PanelBody = components.PanelBody;
  var RangeControl = components.RangeControl;
  var TextControl = components.TextControl;
  var ToggleControl = components.ToggleControl;
  var __ = i18n.__;

  blocks.registerBlockType('content-studio/latest-posts', {
    title: __('Content Studio Latest Posts', 'content-studio'),
    description: __('Display the latest synced Content Studio posts.', 'content-studio'),
    category: 'widgets',
    icon: 'admin-post',
    attributes: {
      postsToShow: { type: 'number', default: 3 },
      showTitle: { type: 'boolean', default: true },
      title: { type: 'string', default: 'Latest articles' },
      showExcerpt: { type: 'boolean', default: true },
      showMeta: { type: 'boolean', default: true },
      showImage: { type: 'boolean', default: true },
    },
    edit: function (props) {
      var attributes = props.attributes;
      var setAttributes = props.setAttributes;

      return el(
        element.Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Settings', 'content-studio') },
            el(RangeControl, {
              label: __('Posts to show', 'content-studio'),
              min: 1,
              max: 12,
              value: attributes.postsToShow,
              onChange: function (value) {
                setAttributes({ postsToShow: value });
              },
            }),
            el(ToggleControl, {
              label: __('Show title', 'content-studio'),
              checked: attributes.showTitle,
              onChange: function (value) {
                setAttributes({ showTitle: value });
              },
            }),
            attributes.showTitle &&
              el(TextControl, {
                label: __('Title', 'content-studio'),
                value: attributes.title,
                onChange: function (value) {
                  setAttributes({ title: value });
                },
              }),
            el(ToggleControl, {
              label: __('Show images', 'content-studio'),
              checked: attributes.showImage,
              onChange: function (value) {
                setAttributes({ showImage: value });
              },
            }),
            el(ToggleControl, {
              label: __('Show date and read time', 'content-studio'),
              checked: attributes.showMeta,
              onChange: function (value) {
                setAttributes({ showMeta: value });
              },
            }),
            el(ToggleControl, {
              label: __('Show excerpt', 'content-studio'),
              checked: attributes.showExcerpt,
              onChange: function (value) {
                setAttributes({ showExcerpt: value });
              },
            })
          )
        ),
        el(ServerSideRender, {
          block: 'content-studio/latest-posts',
          attributes: attributes,
        })
      );
    },
    save: function () {
      return null;
    },
  });
})(
  window.wp.blocks,
  window.wp.components,
  window.wp.element,
  window.wp.i18n,
  window.wp.serverSideRender
);
