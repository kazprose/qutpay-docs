(function () {
  var registry = window.wc && window.wc.wcBlocksRegistry;
  var settingsApi = window.wc && window.wc.wcSettings;
  if (!registry || !settingsApi || !window.wp || !window.wp.element) return;
  var s = settingsApi.getSetting('qutpay_data', {});
  var el = window.wp.element.createElement;
  var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (x) { return x; };
  var label = decode(s.title || 'Kaspi арқылы төлеу / Оплата через Kaspi');
  registry.registerPaymentMethod({
    name: 'qutpay',
    label: el('span', null, label),
    content: el('div', null, decode(s.description || '')),
    edit: el('div', null, decode(s.description || '')),
    canMakePayment: function () { return true; },
    ariaLabel: label,
    supports: { features: s.supports || ['products'] },
  });
})();
