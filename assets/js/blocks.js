(function () {
    const settings = window.wc.wcSettings.getSetting('getyourcash_data', {});
    const el = window.wp.element.createElement;
    const title = window.wp.htmlEntities.decodeEntities(settings.title || 'GetYourCash');

    const Label = function () {
        return el(
            'span',
            { style: { display: 'flex', alignItems: 'center', gap: '8px', width: '100%' } },
            title,
            settings.icon ? el('img', { src: settings.icon, alt: '', style: { maxHeight: '24px', marginLeft: 'auto' } }) : null
        );
    };

    const Content = function () {
        return el('div', null, window.wp.htmlEntities.decodeEntities(settings.description || ''));
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'getyourcash',
        label: el(Label, null),
        content: el(Content, null),
        edit: el(Content, null),
        canMakePayment: function () { return true; },
        ariaLabel: title,
        supports: { features: settings.supports || ['products'] }
    });
})();
