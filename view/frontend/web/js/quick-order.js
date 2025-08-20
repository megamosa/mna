define([
    'jquery',
    'mage/url',
    'Magento_Customer/js/customer-data',
    'mage/translate'
], function ($, urlBuilder, customerData) {
    'use strict';

    function plugin(config, element) {
        var $container = $(element);
        var configData = config && config.config ? config.config : {};

        var $form = $container.find('#magoarab-easyorder-form');
        var $submitBtn = $container.find('#magoarab-easyorder-submit-btn');
        var $loadingOverlay = $container.find('#magoarab-loading-overlay');

        var isOrderProcessing = false;
        var calculationCache = {};
        var calculationTimeout = null;
        var isCalculating = false;
        var buttonDisabledTimeout;
        var lastUpdateTime = Date.now();

        init();

        function init() {
            initializeDefaults();
            bindEvents();
            checkFormValidity();
            preloadIfNeeded();
        }

        function initializeDefaults() {
            try {
                if (configData.customer && configData.customer.isLoggedIn) {
                    if (configData.customer.name) {
                        $('#magoarab-customer-name').val(configData.customer.name);
                    }
                    if (configData.customer.email) {
                        $('#magoarab-customer-email').val(configData.customer.email);
                        $('#magoarab-customer-email-hidden').val(configData.customer.email);
                    }
                    if (configData.customer.phone) {
                        $('#magoarab-customer-phone').val(configData.customer.phone);
                    }
                }
            } catch (e) {}

            if (configData.settings && configData.settings.autoGenerateEmail) {
                var updateEmailFromPhone = function () {
                    var phone = ($('#magoarab-customer-phone').val() || '').replace(/[^0-9]/g, '');
                    if (phone.length >= 6) {
                        var email = phone + '@' + (configData.settings.emailDomain || 'example.com');
                        $('#magoarab-customer-email-hidden').val(email);
                        $('#magoarab-customer-email').val(email);
                    }
                };
                $('#magoarab-customer-phone').on('keyup blur change', updateEmailFromPhone);
                updateEmailFromPhone();
            }

            // Initialize order summary with current unit price
            var $unit = $('#magoarab-unit-price');
            if ($unit.length) {
                var unitText = $unit.text();
                var unitNumeric = parseFloat($unit.attr('data-price')) || 0;
                $('#magoarab-unit-price-display').text(unitText);
                $('#magoarab-product-subtotal').text(unitText);
                $('#magoarab-order-total').text(unitText);
                $('#magoarab-qty-display').text('1');
                if (unitNumeric) {
                    $('#magoarab-order-total').attr('data-base', unitNumeric);
                }
            }
        }

        function bindEvents() {
            // Quantity buttons
            $container.on('click', '.qty-btn', function () {
                var action = $(this).data('action');
                var $qtyInput = $('#magoarab-easyorder-qty');
                var currentQty = parseInt($qtyInput.val(), 10) || 1;
                if (action === 'plus') {
                    $qtyInput.val(currentQty + 1);
                } else if (action === 'minus' && currentQty > 1) {
                    $qtyInput.val(currentQty - 1);
                }
                updateQuantityDisplay();
                optimizedUpdateCalculation();
            });

            // Quantity input
            $container.on('input change', '#magoarab-easyorder-qty', function () {
                var qty = parseInt($(this).val(), 10) || 1;
                if (qty < 1) {
                    $(this).val(1);
                }
                updateQuantityDisplay();
                clearTimeout(calculationTimeout);
                calculationTimeout = setTimeout(function () {
                    optimizedUpdateCalculation();
                }, 300);
            });

            // Country -> regions, postcode toggle, debounced shipping
            $container.on('change', '#magoarab-country-id', function () {
                var countryId = $(this).val();
                loadRegions(countryId);
                togglePostcodeField();
                if (countryId && $('#magoarab-city').val()) {
                    debouncedLoadShipping();
                }
            });

            $container.on('change', '#magoarab-region-id, #magoarab-region', function () {
                if ($('#magoarab-country-id').val() && $('#magoarab-city').val()) {
                    loadShippingMethods();
                }
            });

            $container.on('change', '#magoarab-city, #magoarab-street-1, #magoarab-street-2, #magoarab-postcode', function () {
                if ($('#magoarab-country-id').val() && $('#magoarab-city').val()) {
                    loadShippingMethods();
                }
            });

            $container.on('change', 'input[name="payment_method"]', function () {
                checkFormValidity();
            });

            $container.on('change', 'input[name="shipping_method"]', function () {
                var costAttr = $(this).attr('data-cost');
                var cost = parseFloat(costAttr) || 0;
                $('#magoarab-shipping-cost').text(formatPrice(cost)).attr('data-cost', cost);
                $('#magoarab-shipping-cost-row').show();
                optimizedUpdateCalculation();
                checkFormValidity();
            });

            // Required fields validation
            $form.find('input[required], select[required], textarea[required]').on('change blur', function () {
                checkFormValidity();
            });

            // Submit
            $form.on('submit', function (e) {
                e.preventDefault();
                submitOrder();
            });

            // Coupon and Note toggles
            $container.on('click', '#magoarab-coupon-toggle', function (e) {
                e.preventDefault();
                $('#magoarab-coupon-field').slideToggle(150);
            });
            $container.on('click', '#magoarab-note-toggle', function (e) {
                e.preventDefault();
                $('#magoarab-note-field').slideToggle(150);
            });

            // Live counter for customer note length
            $container.on('input', '#customer_note', function () {
                var max = configData.customerNoteMaxLength || 200;
                var val = $(this).val() || '';
                if (val.length > max) {
                    $(this).val(val.substring(0, max));
                }
                var remaining = max - ($(this).val() || '').length;
                var $helper = $(this).siblings('.note');
                if ($helper.length) {
                    $helper.text($.mage.__('Max %1 characters').replace('%1', max) + ' · ' + $.mage.__('Remaining: %1').replace('%1', remaining));
                }
            });

            // Apply/Remove coupon via calculation endpoint to reflect real Magento rules
            $container.on('click', '#apply-coupon-btn', function () {
                var code = $('#coupon-code').val();
                if (!code) { return; }
                $('#coupon-message').removeClass('error success').hide();
                optimizedUpdateCalculation();
                // Give brief feedback; detailed validity will reflect in discount row
                setTimeout(function(){
                    var hasDiscount = parseFloat($('#magoarab-discount-amount').attr('data-discount') || '0') > 0 || $('#magoarab-discount-row:visible').length > 0;
                    if (hasDiscount) {
                        $('#coupon-message').text($.mage.__('Coupon code applied successfully')).removeClass('error').addClass('success').show();
                        $('#remove-coupon-btn').show();
                    } else {
                        $('#coupon-message').text($.mage.__('Invalid coupon code or no discount applied')).removeClass('success').addClass('error').show();
                    }
                }, 500);
            });

            $container.on('click', '#remove-coupon-btn', function () {
                $('#coupon-message').removeClass('error success').hide();
                $('#coupon-code').val('');
                optimizedUpdateCalculation();
                setTimeout(function(){
                    $('#coupon-message').text($.mage.__('Coupon code removed successfully')).removeClass('error').addClass('success').show();
                    $('#remove-coupon-btn').hide();
                }, 400);
            });

            // Track updates to re-enable button if needed
            $form.on('change input', 'input[type="number"], select[name="shipping_method"], select[name="payment_method"]', function () {
                lastUpdateTime = Date.now();
                if ($submitBtn.hasClass('extended-disabled') && buttonDisabledTimeout) {
                    clearTimeout(buttonDisabledTimeout);
                    enableSubmitButtonAfterDelay();
                    showInfoMessage($.mage.__('Order button re-enabled after changes'));
                }
            });

            // Product options change
            $(document).on('change', '.product-option-select', function () {
                updateProductPrice();
                checkFormValidity();
            });
        }

        function preloadIfNeeded() {
            if (configData.settings && configData.settings.hideCountry) {
                var defaultCountry = configData.defaultCountry || $('#magoarab-country-id option[selected]').val();
                if (defaultCountry) {
                    $('#magoarab-country-id').val(defaultCountry).trigger('change');
                }
                $('#magoarab-country-field-wrapper').css({ visibility: 'hidden', height: 0, margin: 0, padding: 0, overflow: 'hidden' });
            }

            var initialCountry = $('#magoarab-country-id').val();
            if (initialCountry) {
                loadRegions(initialCountry);
                togglePostcodeField();
            }
        }

        // Regions
        function loadRegions(countryId) {
            var $regionSelect = $('#magoarab-region-id');
            var $regionInput = $('#magoarab-region');

            if (!countryId) {
                $regionSelect.hide();
                $regionInput.hide();
                return;
            }

            $regionSelect.html('<option value="">' + $.mage.__('Loading...') + '</option>').show();
            $regionInput.hide();

            $.get(configData.urls.regions, { country_id: countryId })
                .done(function (response) {
                    $regionSelect.empty().append('<option value="">' + $.mage.__('Select State/Province') + '</option>');
                    if (response.success && response.regions && response.regions.length > 0) {
                        $.each(response.regions, function (index, region) {
                            $regionSelect.append('<option value="' + region.value + '">' + region.label + '</option>');
                        });
                        $regionSelect.show();
                        $regionInput.hide();
                        if (configData.settings.requireRegion) {
                            $regionSelect.addClass('required-entry');
                            $regionInput.removeClass('required-entry');
                        }
                    } else {
                        $regionSelect.hide();
                        $regionInput.show();
                        if (configData.settings.requireRegion) {
                            $regionInput.addClass('required-entry');
                            $regionSelect.removeClass('required-entry');
                        }
                    }
                })
                .fail(function () {
                    $regionSelect.hide();
                    $regionInput.show();
                    if (configData.settings.requireRegion) {
                        $regionInput.addClass('required-entry');
                    }
                });
        }

        function togglePostcodeField() {
            var postcodeFieldType = (configData.settings && configData.settings.postcodeFieldType) || 'optional';
            var $postcodeField = $('#magoarab-postcode-field');
            var $postcodeInput = $('#magoarab-postcode');
            if (postcodeFieldType === 'hidden') {
                $postcodeField.hide();
                $postcodeInput.removeClass('required-entry');
            } else if (postcodeFieldType === 'required') {
                $postcodeField.show();
                $postcodeField.addClass('required');
                $postcodeInput.addClass('required-entry');
                $postcodeField.find('.label span').text($.mage.__('Postal Code') + ' *');
            } else {
                $postcodeField.show();
                $postcodeField.removeClass('required');
                $postcodeInput.removeClass('required-entry');
                $postcodeField.find('.label span').text($.mage.__('Postal Code') + ' (' + $.mage.__('Optional') + ')');
            }
        }

        // Shipping
        var shippingTimeout;
        function debouncedLoadShipping() {
            if (shippingTimeout) {
                clearTimeout(shippingTimeout);
            }
            showOrderSummarySpinner();
            shippingTimeout = setTimeout(function () {
                loadShippingMethods();
            }, 200);
        }

        function loadShippingMethods() {
            var postData = {
                product_id: configData.productId,
                country_id: $('#magoarab-country-id').val(),
                region_id: $('#magoarab-region-id').val(),
                region: $('#magoarab-region').val(),
                postcode: $('#magoarab-postcode').val(),
                city: $('#magoarab-city').val(),
                phone: $('#magoarab-customer-phone').val(),
                qty: parseInt($('#magoarab-easyorder-qty').val(), 10) || 1,
                form_key: $('input[name="form_key"]').val()
            };

            var finalRegion = postData.region || postData.region_id;
            if (!postData.country_id || !finalRegion) {
                $('#magoarab-shipping-methods-container').html(
                    '<div class="select-region-message">' +
                    '<p>' + $.mage.__('Please select your region to view available shipping methods') + '</p>' +
                    '</div>'
                );
                return;
            }

            $('#magoarab-shipping-section').show();
            $('#magoarab-shipping-methods-container').html(
                '<div class="shipping-loading">' +
                '  <div class="loading-spinner"></div>' +
                '  <p>' + $.mage.__('Loading shipping methods...') + '</p>' +
                '</div>'
            );

            $.post({ url: configData.urls.shipping, data: postData, timeout: 15000 })
                .done(function (response) {
                    hideOrderSummarySpinner();
                    handleShippingResponse(response);
                })
                .fail(function (xhr, status) {
                    hideOrderSummarySpinner();
                    var errorMsg = $.mage.__('Error loading shipping methods. Please try again.');
                    if (status === 'timeout') {
                        errorMsg = $.mage.__('Request timeout. Please check your connection and try again.');
                    }
                    $('#magoarab-shipping-methods-container').html(
                        '<div class="shipping-error">' +
                        '  <p>' + errorMsg + '</p>' +
                        '  <button type="button" class="retry-shipping-btn">' + $.mage.__('Try Again') + '</button>' +
                        '</div>'
                    );
                });
        }

        $container.on('click', '.retry-shipping-btn', function () {
            loadShippingMethods();
        });

        function handleShippingResponse(response) {
            if (response.success && response.shipping_methods && response.shipping_methods.length > 0) {
                var html = '<div class="shipping-methods-list">';
                response.shipping_methods.forEach(function (method, index) {
                    var isChecked = index === 0 ? 'checked' : '';
                    var priceFormatted = method.price_formatted || formatPrice(method.price);
                    html += '' +
                        '<label class="shipping-method-option">' +
                        '  <input type="radio" name="shipping_method" value="' + method.code + '" class="shipping-radio" data-cost="' + method.price + '" ' + isChecked + '>' +
                        '  <div class="method-info">' +
                        '    <span class="method-title">' + (method.carrier_title || method.title) + '</span>' +
                        '    <span class="method-description">' + method.title + '</span>' +
                        '  </div>' +
                        '  <span class="method-price">' + priceFormatted + '</span>' +
                        '</label>';
                });
                html += '</div>';
                $('#magoarab-shipping-methods-container').html(html);
                $('input[name="shipping_method"]:first').trigger('change');
            } else {
                var errorMsg = response.message || $.mage.__('No shipping methods available for this location');
                $('#magoarab-shipping-methods-container').html(
                    '<div class="no-shipping-methods">' +
                    '  <p>' + errorMsg + '</p>' +
                    '  <small>' + $.mage.__('Please verify your address details or contact our support team') + '</small>' +
                    '  <button type="button" class="retry-shipping-btn">' + $.mage.__('Retry') + '</button>' +
                    '</div>'
                );
            }
        }

        // Calculation
        function showOrderSummarySpinner() {
            if (!isCalculating) {
                isCalculating = true;
                $('#order-summary-spinner').show();
                $('#order-summary-content').addClass('updating');
            }
        }
        function hideOrderSummarySpinner() {
            isCalculating = false;
            $('#order-summary-spinner').hide();
            $('#order-summary-content').removeClass('updating');
        }

        function updateCalculation() { optimizedUpdateCalculation(); }

        function optimizedUpdateCalculation() {
            showOrderSummarySpinner();
            var shippingMethod = $('input[name="shipping_method"]:checked').val();
            if (!shippingMethod) { hideOrderSummarySpinner(); return; }
            var postData = {
                product_id: configData.productId,
                qty: parseInt($('#magoarab-easyorder-qty').val(), 10) || 1,
                shipping_method: shippingMethod,
                country_id: $('#magoarab-country-id').val(),
                region_id: $('#magoarab-region-id').val() || '',
                region: $('#magoarab-region').val() || '',
                city: $('#magoarab-city').val() || '',
                postcode: $('#magoarab-postcode').val() || '',
                form_key: $('input[name="form_key"]').val()
            };

            var superAttribute = {};
            $('.product-option-select').each(function () {
                if ($(this).val()) {
                    superAttribute[$(this).attr('name').replace('super_attribute[', '').replace(']', '')] = $(this).val();
                }
            });
            if (Object.keys(superAttribute).length > 0) {
                postData.super_attribute = superAttribute;
            }
            var couponCode = $('#coupon-code').val();
            if (couponCode) { postData.coupon_code = couponCode; }

            var cacheKey = JSON.stringify(postData);
            var shouldUseCache = !postData.super_attribute && !postData.coupon_code;
            if (shouldUseCache && calculationCache[cacheKey]) {
                updateOrderSummaryWithCalculation(calculationCache[cacheKey]);
                hideOrderSummarySpinner();
                return;
            }

            $.ajax({
                url: configData.urls.calculate,
                type: 'POST',
                data: postData,
                dataType: 'json',
                cache: false,
                timeout: 10000,
                success: function (response) {
                    hideOrderSummarySpinner();
                    if (response.success && response.calculation) {
                        if (shouldUseCache) {
                            calculationCache[cacheKey] = response.calculation;
                            if (Object.keys(calculationCache).length > 20) {
                                var firstKey = Object.keys(calculationCache)[0];
                                delete calculationCache[firstKey];
                            }
                        }
                        updateOrderSummaryWithCalculation(response.calculation);
                    }
                },
                error: function () { hideOrderSummarySpinner(); }
            });
        }

        function updateQuantityDisplay() {
            var qty = parseInt($('#magoarab-easyorder-qty').val(), 10) || 1;
            $('#magoarab-qty-display').text(qty);
            var unitPrice = parseFloat($('#magoarab-unit-price').attr('data-price')) || 0;
            if (unitPrice > 0) {
                var newSubtotal = unitPrice * qty;
                $('#magoarab-product-subtotal').text(formatPrice(newSubtotal));
                var currentShipping = parseFloat($('#magoarab-shipping-cost').attr('data-cost')) || 0;
                var currentDiscount = parseFloat($('#magoarab-discount-amount').attr('data-discount')) || 0;
                var estimatedTotal = newSubtotal + currentShipping - currentDiscount;
                $('#magoarab-order-total').text(formatPrice(estimatedTotal));
            }
        }

        function updateOrderSummaryWithCalculation(calculation) {
            if (!calculation || typeof calculation !== 'object') { return; }
            if (calculation.product_price !== undefined) {
                $('#magoarab-unit-price-display').text(formatPrice(calculation.product_price));
                $('#magoarab-unit-price').attr('data-price', calculation.product_price);
            }
            if (calculation.subtotal !== undefined) {
                $('#magoarab-product-subtotal').text(formatPrice(calculation.subtotal));
            }
            // Decide which shipping cost to display:
            // - If server returns 0 (free shipping by rules) => show 0
            // - Else prefer selected method's data-cost for display; fallback to server value if no data-cost
            var $sel = $('input[name="shipping_method"]:checked');
            var selectedCostVal = $sel.length ? parseFloat($sel.attr('data-cost')) : NaN;
            var serverShip = (calculation.shipping_cost !== undefined) ? (parseFloat(calculation.shipping_cost) || 0) : NaN;
            var displayShip;
            if (!isNaN(serverShip) && serverShip === 0) {
                displayShip = 0;
            } else if (!isNaN(selectedCostVal)) {
                displayShip = selectedCostVal;
            } else if (!isNaN(serverShip)) {
                displayShip = serverShip;
            } else {
                displayShip = 0;
            }
            $('#magoarab-shipping-cost').text(formatPrice(displayShip)).attr('data-cost', displayShip);
            $('#magoarab-shipping-cost-row').show();
            if (calculation.discount_amount !== undefined) {
                if (!$('#magoarab-discount-row').length) {
                    $('#magoarab-shipping-cost-row').after(
                        '<div class="summary-row" id="magoarab-discount-row">' +
                        '  <span class="label">' + $.mage.__('Discount:') + '</span>' +
                        '  <span class="value" id="magoarab-discount-amount">-</span>' +
                        '</div>'
                    );
                }
                var discountAmount = parseFloat(calculation.discount_amount) || 0;
                if (discountAmount > 0) {
                    $('#magoarab-discount-amount').text('-' + formatPrice(discountAmount)).attr('data-discount', discountAmount);
                    $('#magoarab-discount-row').show();
                } else {
                    $('#magoarab-discount-amount').text(formatPrice(0)).attr('data-discount', 0);
                    $('#magoarab-discount-row').show();
                }
            } else {
                $('#magoarab-discount-row').hide();
            }
            // Recompute total if our display shipping differs from server shipping
            if (calculation.total !== undefined) {
                var subtotalNum = parseFloat(String(calculation.subtotal)) || 0;
                var discountNum = parseFloat(String(calculation.discount_amount)) || 0;
                var recomputed = subtotalNum - (discountNum > 0 ? discountNum : 0) + displayShip;
                if (!isNaN(serverShip) && Math.abs(displayShip - serverShip) < 0.01) {
                    $('#magoarab-order-total').text(formatPrice(calculation.total));
                } else {
                    $('#magoarab-order-total').text(formatPrice(recomputed));
                }
            }
            var qty = parseInt($('#magoarab-easyorder-qty').val(), 10) || 1;
            $('#magoarab-qty-display').text(qty);
        }

        function checkFormValidity() {
            var isValid = true;
            $form.find('input[required], select[required], textarea[required]').each(function () {
                var val = $(this).val();
                if (!val || !String(val).trim()) { isValid = false; return false; }
            });
            if (!$('input[name="shipping_method"]:checked').length) { isValid = false; }
            if (!$('input[name="payment_method"]:checked').length) { isValid = false; }
            var productOptionsExist = $('.product-option-select').length > 0;
            if (productOptionsExist) {
                var allSelected = true;
                $('.product-option-select').each(function () { if (!$(this).val()) { allSelected = false; return false; } });
                if (!allSelected) { isValid = false; }
            }
            $submitBtn.prop('disabled', !isValid);
            return isValid;
        }

        function submitOrder() {
            if (!checkFormValidity()) {
                showErrorMessage($.mage.__('Please fill all required fields'));
                return;
            }
            if (isOrderProcessing) { return; }
            isOrderProcessing = true;

            var orderSummaryData = {
                frontend_subtotal: parseFloat($('#magoarab-product-subtotal').text().replace(/[^\d.-]/g, '') || 0),
                frontend_shipping: parseFloat($('#magoarab-shipping-cost').text().replace(/[^\d.-]/g, '') || 0),
                frontend_discount: parseFloat($('#magoarab-discount-amount').text().replace(/[^\d.-]/g, '') || 0),
                frontend_grand_total: parseFloat($('#magoarab-order-total').text().replace(/[^\d.-]/g, '') || 0),
                frontend_qty: parseInt($('#magoarab-qty-display').text(), 10) || 1
            };

            var formData = $form.serialize();
            var productOptionsExist = $('.product-option-select').length > 0;
            if (productOptionsExist) {
                var productOptions = {};
                $('.product-option-select').each(function () {
                    var attributeId = $(this).attr('name').match(/\[(\d+)\]/)[1];
                    var optionId = $(this).val();
                    if (optionId) { productOptions[attributeId] = optionId; }
                });
                if (Object.keys(productOptions).length > 0) {
                    formData += '&' + $.param({ super_attribute: productOptions });
                }
            }
            formData += '&' + $.param(orderSummaryData);

            // Capture formatted summary before placing order, for success screen
            var preSummary = {
                unitPrice: $('#magoarab-unit-price-display').text(),
                qty: $('#magoarab-qty-display').text(),
                subtotal: $('#magoarab-product-subtotal').text(),
                shipping: $('#magoarab-shipping-cost').length ? $('#magoarab-shipping-cost').text() : '',
                discount: $('#magoarab-discount-amount').length ? $('#magoarab-discount-amount').text() : '',
                total: $('#magoarab-order-total').text()
            };

            disableSubmitButton();
            hideMessages();

            $.ajax({
                url: configData.urls.submit,
                type: 'POST',
                data: formData,
                timeout: 30000
            }).done(function (response) {
                $loadingOverlay.hide();
                isOrderProcessing = false;
                if (response.success) {
                    showSuccessMessageWithConfetti(
                        response.message || $.mage.__('Order created successfully!'),
                        response.increment_id,
                        response.product_details,
                        preSummary
                    );
                    $submitBtn.removeClass('processing').addClass('completed').html('<span>✓ ' + $.mage.__('Order created successfully') + '</span>');
                    setExtendedButtonDisable();
                } else {
                    showErrorMessage(response.message || $.mage.__('Error creating order'));
                    resetSubmitButton();
                }
            }).fail(function () {
                $loadingOverlay.hide();
                isOrderProcessing = false;
                showErrorMessage($.mage.__('Error creating order'));
                resetSubmitButton();
            });
        }

        function disableSubmitButton() {
            $submitBtn.prop('disabled', true).addClass('processing').html('<span class="processing-text">' + $.mage.__('Creating order...') + '</span><div class="processing-spinner"></div>');
            $loadingOverlay.show();
        }

        function setExtendedButtonDisable() {
            lastUpdateTime = Date.now();
            var disableDuration = 65000;
            // Keep button minimal; show the disable notice only under success message
            $submitBtn.addClass('extended-disabled').html('<span class="disabled-text">✓</span>');
            var remainingTime = disableDuration / 1000;
            var countdownInterval = setInterval(function () {
                remainingTime--;
                $('#countdown-timer-success').text($.mage.__('Re-enabled in: %1s').replace('%1', remainingTime));
                // Optionally also reflect in button if desired: $('#countdown-timer')
                if (remainingTime <= 0) { clearInterval(countdownInterval); }
            }, 1000);

            buttonDisabledTimeout = setTimeout(function () {
                var timeSinceLastUpdate = Date.now() - lastUpdateTime;
                if (timeSinceLastUpdate >= disableDuration) {
                    enableSubmitButtonAfterDelay();
                    clearInterval(countdownInterval);
                } else {
                    setExtendedButtonDisable();
                }
            }, disableDuration);
        }

        function enableSubmitButtonAfterDelay() {
            $submitBtn.removeClass('processing completed extended-disabled');
            $submitBtn.prop('disabled', false);
            $submitBtn.html('<span class="enabled-text"><i class="fa fa-shopping-cart"></i> ' + $.mage.__('Click Here to Order') + '</span>');
            $('#magoarab-success-message').fadeOut();
            var shippingMethod = $('input[name="shipping_method"]:checked').val();
            if (shippingMethod) { updateCalculation(); }
            isOrderProcessing = false;
            lastUpdateTime = Date.now();
            showInfoMessage($.mage.__('You can place a new order now'), 3000);
        }

        function resetSubmitButton() {
            if (buttonDisabledTimeout) { clearTimeout(buttonDisabledTimeout); }
            $submitBtn.prop('disabled', false).removeClass('processing completed').html('<span>' + $.mage.__('Click Here to Order') + '</span>');
            $loadingOverlay.hide();
            isOrderProcessing = false;
        }

        function showSuccessMessageWithConfetti(message, orderNumber, productDetails, summary) {
            hideMessages();
            
            // Create confetti if enabled
            if (configData.enableConfetti) {
                createConfetti();
            }
            
            // Play success sound if enabled
            if (configData.enableSuccessSound) {
                playSuccessSound();
            }

            $('#magoarab-success-text').text(message);
            if (orderNumber) { $('#magoarab-order-number').text(orderNumber); }

            var $details = $('#magoarab-product-details');
            $details.empty();

            if (productDetails && productDetails.length > 0) {
                var productDetailsHtml = '<div class="ordered-products">';
                productDetails.forEach(function (product, index) {
                    productDetailsHtml += '<div class="product-item" data-index="' + index + '">' +
                        '  <div class="product-name"><strong>' + product.name + '</strong></div>' +
                        '  <div class="info-row">' +
                        '    <span class="qty">' + $.mage.__('Qty:') + ' <strong>' + product.qty + '</strong></span>' +
                        '    <span class="price">' + $.mage.__('Row total:') + ' <strong>' + product.row_total + '</strong></span>' +
                        '    <span class="unit-price">' + $.mage.__('Unit price:') + ' <strong>' + product.price + '</strong></span>' +
                        '  </div>' +
                        '</div>';
                });
                productDetailsHtml += '</div>';
                $details.append(productDetailsHtml);
            }

            // Append final order summary (formatted strings) if provided
            if (summary && (summary.unitPrice || summary.total)) {
                var summaryHtml = ''+
                  '<div class="order-summary success-summary">' +
                  '  <h4>' + $.mage.__('Order Summary') + '</h4>' +
                  '  <div class="summary-row"><span class="label">' + $.mage.__('Unit Price:') + '</span><span class="value">' + (summary.unitPrice || '') + '</span></div>' +
                  '  <div class="summary-row"><span class="label">' + $.mage.__('Quantity:') + '</span><span class="value">' + (summary.qty || '') + '</span></div>' +
                  '  <div class="summary-row"><span class="label">' + $.mage.__('Subtotal:') + '</span><span class="value">' + (summary.subtotal || '') + '</span></div>' +
                  '  <div class="summary-row"><span class="label">' + $.mage.__('Shipping Cost:') + '</span><span class="value">' + (summary.shipping || '') + '</span></div>' +
                  (summary.discount && summary.discount !== '-' ? ('  <div class="summary-row"><span class="label">' + $.mage.__('Discount:') + '</span><span class="value">' + summary.discount + '</span></div>') : '') +
                  '  <div class="summary-row total-row"><span class="label">' + $.mage.__('Final Total:') + '</span><span class="value">' + (summary.total || '') + '</span></div>' +
                  '</div>';
                $details.append(summaryHtml);
            }

            var $closeBtn = $('#manual-close-btn');
            if ($closeBtn.length) {
                $closeBtn.off('click').on('click', function () { $('#magoarab-success-message').fadeOut(300); });
                $closeBtn.show();
            }

            // Show success, then add disable note under it and scroll to it
            $('#magoarab-success-message').show();
            
            // Auto scroll to success message if enabled
            if (configData.autoScrollToSuccess) {
                try {
                    $('html, body').animate({ scrollTop: $('#magoarab-success-message').offset().top - 100 }, 500);
                } catch (e) {}
            }
            if (!$('#magoarab-success-disable-note').length) {
                var noteHtml = '' +
                  '<div id="magoarab-success-disable-note" class="success-disable-note" style="margin-top:12px; font-weight:600; display:none;">' +
                  '  <span class="msg"><i class="fa fa-clock-o"></i> ' + $.mage.__('Order created - button is disabled to prevent duplicates') + '</span> ' +
                  '  <span class="count" id="countdown-timer-success"></span>' +
                  '</div>';
                $('#magoarab-success-message .message-content').append(noteHtml);
            }
            $('#magoarab-success-disable-note').show();
        }

        function hideMessages() {
            $('#magoarab-success-message, #magoarab-error-message').hide();
        }
        function showErrorMessage(message) {
            $('#magoarab-error-text').text(message);
            $('#magoarab-error-message').show();
            setTimeout(function () { $('#magoarab-error-message').fadeOut(); }, 10000);
        }
        function showInfoMessage(message, duration) {
            duration = duration || 5000;
            var $info = $('<div class="info-message" style="position: fixed; top: 20px; right: 20px; background: #2196F3; color: #fff; padding: 12px 16px; border-radius: 4px; z-index: 9999; font-weight: 600;">' + message + '</div>');
            $('body').append($info);
            setTimeout(function () { $info.fadeOut(function () { $info.remove(); }); }, duration);
        }

        function updateProductPrice() {
            var selectedOptions = {};
            $('.product-option-select').each(function () {
                var match = $(this).attr('name').match(/\[(\d+)\]/);
                if (match) {
                    var attributeId = match[1];
                    var optionId = $(this).val();
                    if (optionId) { selectedOptions[attributeId] = optionId; }
                }
            });
            if (Object.keys(selectedOptions).length > 0) {
                $.ajax({
                    url: configData.urls.getPrice,
                    type: 'POST',
                    data: { product_id: configData.productId, super_attribute: selectedOptions, form_key: $('input[name="form_key"]').val() }
                }).done(function (response) {
                    if (response.success) {
                        $('#magoarab-unit-price').attr('data-price', response.price).text(response.formatted_price);
                        $('#magoarab-unit-price-display').text(response.formatted_price);
                    }
                });
            }
        }

        function createConfetti() {
            var colors = ['#FF6B35', '#F7931E', '#FFD23F', '#06FFA5', '#118AB2', '#073B4C', '#EF476F'];
            var shapes = ['circle', 'square', 'triangle'];
            var confettiCount = 80;
            for (var i = 0; i < confettiCount; i++) {
                var confetti = document.createElement('div');
                var shape = shapes[Math.floor(Math.random() * shapes.length)];
                confetti.className = 'confetti-piece confetti-' + shape;
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.top = '-10px';
                confetti.style.animationDelay = Math.random() * 2 + 's';
                confetti.style.animationDuration = (Math.random() * 3 + 2) + 's';
                confetti.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
                document.body.appendChild(confetti);
                (function(node){ setTimeout(function(){ if (node && node.parentNode) { node.parentNode.removeChild(node); } }, 6000); })(confetti);
            }
            // center burst
            var burstCount = 20;
            var centerX = window.innerWidth / 2;
            var centerY = window.innerHeight / 2;
            for (var j = 0; j < burstCount; j++) {
                var particle = document.createElement('div');
                particle.className = 'burst-particle';
                var angle = (j / burstCount) * 2 * Math.PI;
                var velocity = 100 + Math.random() * 100;
                particle.style.left = centerX + 'px';
                particle.style.top = centerY + 'px';
                particle.style.setProperty('--end-x', Math.cos(angle) * velocity + 'px');
                particle.style.setProperty('--end-y', Math.sin(angle) * velocity + 'px');
                document.body.appendChild(particle);
                (function(node){ setTimeout(function(){ if (node && node.parentNode) { node.parentNode.removeChild(node); } }, 2000); })(particle);
            }
        }

        function playSuccessSound() {
            try {
                var audioContext = new (window.AudioContext || window.webkitAudioContext)();
                var oscillator = audioContext.createOscillator();
                var gainNode = audioContext.createGain();
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                oscillator.frequency.setValueAtTime(523.25, audioContext.currentTime);
                oscillator.frequency.setValueAtTime(659.25, audioContext.currentTime + 0.1);
                oscillator.frequency.setValueAtTime(783.99, audioContext.currentTime + 0.2);
                gainNode.gain.setValueAtTime(0.2, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.5);
            } catch (e) {}
        }

        function formatPrice(price) {
            var currencySymbol = configData.currency && configData.currency.symbol ? configData.currency.symbol : '';
            var precision = (configData.currency && configData.currency.precision) || 2;
            var numericPrice = parseFloat(String(price).replace(/[^\d.-]/g, '')) || 0;
            return currencySymbol + numericPrice.toFixed(precision);
        }
    }

    return function (config, element) {
        plugin(config, element);
    };
});


