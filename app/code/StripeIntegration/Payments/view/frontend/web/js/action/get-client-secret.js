define(
    [
        'Magento_Checkout/js/model/url-builder',
        'mage/storage'
    ],
    function (urlBuilder, storage) {
        'use strict';

        return function (callback)
        {
            var serviceUrl = urlBuilder.createUrl('/stripe/payments/get_client_secret', {});

            return storage.get(serviceUrl).always(callback);
        };
    }
);
