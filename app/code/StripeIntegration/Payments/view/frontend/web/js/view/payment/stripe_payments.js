define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'stripe_payments',
                component: 'StripeIntegration_Payments/js/view/payment/method-renderer/stripe_payments'
            },
            {
                type: 'stripe_payments_checkout',
                component: 'StripeIntegration_Payments/js/view/payment/method-renderer/checkout'
            }
        );
        // Add view logic here if needed
        return Component.extend({});
    }
);
