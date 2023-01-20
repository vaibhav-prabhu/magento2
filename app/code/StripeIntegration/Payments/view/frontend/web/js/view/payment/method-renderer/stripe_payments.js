define(
    [
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'StripeIntegration_Payments/js/action/get-client-secret',
        'StripeIntegration_Payments/js/action/post-confirm-payment',
        'StripeIntegration_Payments/js/action/post-update-cart',
        'StripeIntegration_Payments/js/action/post-restore-quote',
        'StripeIntegration_Payments/js/view/checkout/trialing_subscriptions',
        'stripe_payments_express',
        'mage/translate',
        'mage/url',
        'jquery',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/storage',
        'mage/url',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Customer/js/customer-data'
    ],
    function (
        ko,
        Component,
        globalMessageList,
        quote,
        customer,
        getClientSecretAction,
        confirmPaymentAction,
        updateCartAction,
        restoreQuoteAction,
        trialingSubscriptions,
        stripeExpress,
        $t,
        url,
        $,
        placeOrderAction,
        additionalValidators,
        redirectOnSuccessAction,
        storage,
        urlBuilder,
        agreementValidator,
        customerData
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/payment/element',
                stripePaymentsShowApplePaySection: false
            },
            redirectAfterPlaceOrder: false,
            elements: null,

            initObservable: function ()
            {
                this._super()
                    .observe([
                        'paymentElement',
                        'isPaymentFormComplete',
                        'isPaymentFormVisible',
                        'isLoading',
                        'stripePaymentsError',
                        'permanentError',
                        'isOrderPlaced',

                        // Saved payment methods dropdown
                        'dropdownOptions',
                        'selection',
                        'isDropdownOpen'
                    ]);

                var self = this;

                this.isPaymentFormVisible(false);
                this.isOrderPlaced(false);

                trialingSubscriptions().refresh(quote); // This should be initially retrieved via a UIConfig

                var currentTotals = quote.totals();

                quote.totals.subscribe(function (totals)
                {
                    if (JSON.stringify(totals.total_segments) == JSON.stringify(currentTotals.total_segments))
                        return;

                    currentTotals = totals;

                    trialingSubscriptions().refresh(quote);
                    self.onQuoteTotalsChanged.bind(self)();
                    self.isOrderPlaced(false);
                }
                , this);

                return this;
            },

            initSavedPaymentMethods: function()
            {
                var methods = this.getStripeParam("savedMethods");
                var options = [];

                for (const i in methods)
                    options.push(methods[i]);

                if (options.length > 0)
                {
                    this.isPaymentFormVisible(false);
                    this.selection(options[0]);
                }
                else
                {
                    this.isPaymentFormVisible(true);
                    this.selection(false);
                }

                this.dropdownOptions(options);
            },

            newPaymentMethod: function()
            {
                this.selection({
                    type: 'new',
                    value: 'new',
                    icon: false,
                    label: $t('New payment method')
                });
                this.isDropdownOpen(false);
                this.isPaymentFormVisible(true);
            },

            getPaymentMethodId: function()
            {
                var selection = this.selection();

                if (selection && typeof selection.value != "undefined" && selection.value != "new")
                    return selection.value;

                return null;
            },

            toggleDropdown: function()
            {
                this.isDropdownOpen(!this.isDropdownOpen());
            },

            getStripeParam: function(param)
            {
                if (typeof window.checkoutConfig.payment["stripe_payments"] == "undefined")
                    return null;

                if (typeof window.checkoutConfig.payment["stripe_payments"].initParams == "undefined")
                    return null;

                if (typeof window.checkoutConfig.payment["stripe_payments"].initParams[param] == "undefined")
                    return null;

                return window.checkoutConfig.payment["stripe_payments"].initParams[param];
            },

            onQuoteTotalsChanged: function()
            {
                var self = this;
                var clientSecret = this.getStripeParam("clientSecret");
                if (!clientSecret)
                    return;

                getClientSecretAction(function(result, outcome, response)
                {
                    try
                    {
                        var params = JSON.parse(result);

                        for (const prop in params)
                        {
                            if (params.hasOwnProperty(prop))
                                window.checkoutConfig.payment["stripe_payments"].initParams[prop] = params[prop];
                        }

                        var clientSecret2 = self.getStripeParam("clientSecret");

                        if (clientSecret2 && clientSecret != clientSecret2)
                            self.initPaymentForm.bind(self)(params);
                    }
                    catch (e)
                    {
                        return self.crash(e.message);
                    }
                });
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                var params = window.checkoutConfig.payment["stripe_payments"].initParams;
                this.isLoading(true);
                initStripe(params, function(err)
                {
                    if (err)
                        return self.crash(err);

                    getClientSecretAction(function(result, outcome, response)
                    {
                        try
                        {
                            var params = JSON.parse(result);

                            for (const prop in params)
                            {
                                if (params.hasOwnProperty(prop))
                                    window.checkoutConfig.payment["stripe_payments"].initParams[prop] = params[prop];
                            }

                            self.initPaymentForm.bind(self)(params);
                        }
                        catch (e)
                        {
                            return self.crash(e.message);
                        }
                    });
                });
            },

            crash: function(message)
            {
                this.isLoading(false);
                this.permanentError($t("Sorry, this payment method is not available. Please contact us for assistance."));
                console.error("Error: " + message);
            },

            softCrash: function(message)
            {
                this.showError($t("Sorry, this payment method is not available. Please contact us for assistance."));
                console.error("Error: " + message);
            },

            restoreQuoteAndSoftCrash(message)
            {
                this.restoreQuote({ crash: message });
                this.softCrash(message);
            },

            initPaymentForm: function(params)
            {
                if (document.getElementById('stripe-payment-element') === null)
                    return this.crash("Cannot initialize Payment Element on a DOM that does not contain a div.stripe-payment-element.");;

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                if (!params.clientSecret)
                    return this.crash("The PaymentElement could not be initialized because no client_secret was provided in the initialization params.");

                if (this.getStripeParam("isOrderPlaced"))
                    this.isOrderPlaced(true);

                this.initSavedPaymentMethods();

                var elements = this.elements = stripe.stripeJs.elements({
                    locale: params.locale,
                    clientSecret: params.clientSecret,
                    appearance: this.getStripePaymentElementOptions()
                });

                var paymentElement = elements.create('payment');
                paymentElement.mount('#stripe-payment-element');
                paymentElement.on('change', this.onChange.bind(this));
            },

            onChange: function(event)
            {
                this.isLoading(false);
                this.isPaymentFormComplete(event.complete);
            },

            getStripePaymentElementOptions()
            {
                return {
                  theme: 'stripe',
                  variables: {
                    colorText: '#32325d',
                    fontFamily: '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif',
                  },
                };
            },

            isBillingAddressSet: function()
            {
                return quote.billingAddress() && quote.billingAddress().canUseForBilling();
            },

            isPlaceOrderEnabled: function()
            {
                if (this.stripePaymentsError())
                    return false;

                return this.isBillingAddressSet();
            },

            getAddressField: function(field)
            {
                if (!quote.billingAddress())
                    return null;

                var address = quote.billingAddress();

                if (typeof address[field] == "undefined")
                    return null;

                if (typeof address[field] !== "string" && typeof address[field] !== "array")
                    return null;

                if (address[field].length == 0)
                    return null;

                return address[field];
            },

            getBillingDetails: function()
            {
                var details = {};
                var address = {};

                if (this.getAddressField('city'))
                    address.city = this.getAddressField('city');

                if (this.getAddressField('countryId'))
                    address.country = this.getAddressField('countryId');

                if (this.getAddressField('postcode'))
                    address.postal_code = this.getAddressField('postcode');

                if (this.getAddressField('region'))
                    address.state = this.getAddressField('region');

                if (this.getAddressField('street'))
                {
                    var street = this.getAddressField('street');
                    address.line1 = street[0];

                    if (street.length > 1)
                        address.line2 = street[1];
                }

                if (Object.keys(address).length > 0)
                    details.address = address;

                if (this.getAddressField('telephone'))
                    details.phone = this.getAddressField('telephone');

                if (this.getAddressField('firstname'))
                    details.name = this.getAddressField('firstname') + ' ' + this.getAddressField('lastname');

                if (quote.guestEmail)
                    details.email = quote.guestEmail;
                else if (customerData.email)
                    details.email = customerData.email;

                if (Object.keys(details).length > 0)
                    return details;

                return null;
            },

            config: function()
            {
                return window.checkoutConfig.payment[this.getCode()];
            },

            isActive: function(parents)
            {
                return true;
            },

            placeOrder: function()
            {
                if (!this.isPaymentFormComplete() && !this.getPaymentMethodId())
                    return this.showError($t('Please complete your payment details.'));

                if (!additionalValidators.validate())
                    return;

                this.clearErrors();
                this.isPlaceOrderActionAllowed(false);
                this.isLoading(true);
                var placeNewOrder = this.placeNewOrder.bind(this);
                var reConfirmPayment = this.onOrderPlaced.bind(this);
                var self = this;

                if (this.isOrderPlaced()) // The order was already placed once but the payment failed
                {
                    updateCartAction(this.getPaymentMethodId(), function(result, outcome, response)
                    {
                        self.isLoading(false);
                        try
                        {
                            var data = JSON.parse(result);
                            if (typeof data != "undefined" && typeof data.error != "undefined")
                                self.showError(data.error);
                            else if (typeof data != "undefined" && typeof data.redirect != "undefined")
                                $.mage.redirect(data.redirect);
                            else if (typeof data != "undefined" && typeof data.placeNewOrder != "undefined" && data.placeNewOrder)
                                placeNewOrder();
                            else
                                reConfirmPayment();
                        }
                        catch (e)
                        {
                            self.showError($t("The order could not be placed. Please contact us for assistance."));
                            console.error(e.message);
                        }
                    });
                }
                else
                {
                    // We call updateCartAction because in the case that the cart changed, updateCartAction will cancel the old order
                    // before placing the new one.
                    updateCartAction(this.getPaymentMethodId(), function(result, outcome, response)
                    {
                        placeNewOrder();
                    });
                }

                return false;
            },

            placeNewOrder: function()
            {
                this.getPlaceOrderDeferredObject()
                    .fail(this.handlePlaceOrderErrors.bind(this))
                    .done(this.onOrderPlaced.bind(this));
            },

            getSelectedMethod: function(param)
            {
                var selection = this.selection();
                if (!selection)
                    return null;

                if (typeof selection[param] == "undefined")
                    return null;

                return selection[param];
            },

            onOrderPlaced: function(result, outcome, response)
            {
                if (!this.isOrderPlaced() && isNaN(result))
                    return this.softCrash("The order was placed but the response from the server did not include a numeric order ID. The response was ");
                else
                    this.isOrderPlaced(true);

                this.isLoading(true);
                var onConfirm = this.onConfirm.bind(this);
                var onFail = this.onFail.bind(this);

                // Non-card based confirms may redirect the customer externally. We restore the quote just before it in case the
                // customer clicks the back button on the browser before authenticating the payment.
                restoreQuoteAction();

                // If we are confirming the payment with a saved method, we need a client secret and a payment method ID
                var selectedMethod = this.getSelectedMethod("type");

                var clientSecret = this.getStripeParam("clientSecret");
                if (!clientSecret)
                    return this.softCrash("To confirm the payment, a client secret is necessary, but we don't have one.");

                var isSetup = false;
                if (clientSecret.indexOf("seti_") === 0)
                    isSetup = true;

                var confirmParams = {
                    payment_method: this.getSelectedMethod("value"),
                    return_url: this.getStripeParam("successUrl")
                };

                this.confirm(selectedMethod, confirmParams, clientSecret, isSetup, onConfirm, onFail);
            },

            confirm: function(methodType, confirmParams, clientSecret, isSetup, onConfirm, onFail)
            {
                if (!clientSecret)
                    return this.softCrash("To confirm the payment, a client secret is necessary, but we don't have one.");

                if (methodType && methodType != 'new')
                {
                    if (!confirmParams.payment_method)
                        return this.softCrash("To confirm the payment, a saved payment method must be selected, but we don't have one.");

                    if (isSetup)
                    {
                        if (methodType == "card")
                            stripe.stripeJs.confirmCardSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "sepa_debit")
                            stripe.stripeJs.confirmSepaDebitSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "boleto")
                            stripe.stripeJs.confirmBoletoSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "acss_debit")
                            stripe.stripeJs.confirmAcssDebitSetup(clientSecret, confirmParams).then(onConfirm, onFail);
                        else
                            self.showError($t("This payment method is not supported."));
                    }
                    else
                    {
                        if (methodType == "card")
                            stripe.stripeJs.confirmCardPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "sepa_debit")
                            stripe.stripeJs.confirmSepaDebitPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "boleto")
                            stripe.stripeJs.confirmBoletoPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else if (methodType == "acss_debit")
                            stripe.stripeJs.confirmAcssDebitPayment(clientSecret, confirmParams).then(onConfirm, onFail);
                        else
                            self.showError($t("This payment method is not supported."));
                    }
                }
                else
                {
                    // Confirm the payment using element
                    if (isSetup)
                    {
                        stripe.stripeJs.confirmSetup({
                            elements: this.elements,
                            confirmParams: {
                                return_url: this.getStripeParam("successUrl")
                            }
                        })
                        .then(onConfirm, onFail);
                    }
                    else
                    {
                        stripe.stripeJs.confirmPayment({
                            elements: this.elements,
                            confirmParams: {
                                return_url: this.getStripeParam("successUrl")
                            }
                        })
                        .then(onConfirm, onFail);
                    }
                }
            },

            onConfirm: function(result)
            {
                this.isLoading(false);
                if (result.error)
                {
                    this.showError(result.error.message);
                    this.restoreQuote(result);
                }
                else
                {
                    var successUrl = this.getStripeParam("successUrl");
                    $.mage.redirect(successUrl);
                }
            },

            onFail: function(result)
            {
                this.isLoading(false);
                this.showError("Could not confirm the payment. Please try again.");
                this.restoreQuote(result);
                console.error(result);
            },

            restoreQuote: function(result)
            {
                var self = this;

                // Logs the error on the order and re-activates the cart
                confirmPaymentAction(result, function(result, outcome, response)
                {
                    var data = JSON.parse(result);
                    if (typeof data.redirect != "undefined")
                    {
                        $.mage.redirect(data.redirect);
                        return;
                    }
                });
            },

            restoreQuoteBeforeConfirm: function()
            {
                confirmPaymentAction({ restore_quote: true }, function(result, outcome, response)
                {
                    customerData.invalidate(['cart']);
                });
            },

            resetQuote: function()
            {
                // Resets the quote
                confirmPaymentAction({ success: true }, function(result, outcome, response)
                {
                    customerData.invalidate(['cart']);
                });
            },

            /**
             * @return {*}
             */
            getPlaceOrderDeferredObject: function () {
                return $.when(
                    placeOrderAction(this.getData(), this.messageContainer)
                );
            },

            handlePlaceOrderErrors: function (result)
            {
                this.showError(result.responseJSON.message);
            },

            showGlobalError: function(message)
            {
                this.isLoading(false);
                document.getElementById('checkout').scrollIntoView({ behavior: "smooth", block: "nearest", inline: "nearest" });
                globalMessageList.addErrorMessage({ "message": message });
            },

            showError: function(message)
            {
                this.isLoading(false);
                document.getElementById('stripe-payments-card-errors').scrollIntoView({ behavior: "smooth", block: "nearest", inline: "nearest" });
                this.messageContainer.addErrorMessage({ "message": message });
            },

            validate: function(elm)
            {
                return additionalValidators.validate();
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

            getData: function()
            {
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'client_side_confirmation': true,
                        'payment_method': this.getPaymentMethodId()
                    }
                };

                return data;
            },

            clearErrors: function()
            {
                this.stripePaymentsError(null);
            }

        });
    }
);
