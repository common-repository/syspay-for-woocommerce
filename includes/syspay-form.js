
// Syspay token submission callback.
var callback = function (response) {

    var jQuerytokenInput = jQuery('form.woocommerce-checkout input[name="syspay-token"]');


    if (null === response.error) {
        // The request was successfully processed.  Add the returned token to the form before submitting it for real.
        
        // Emptying form.
        jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv').val('');

        if(jQuerytokenInput.size() > 0){
            jQuerytokenInput.remove();
        }
        
        jQuery('<input type="hidden" name="syspay-token" />')
            .val(response.token)
            .prependTo('form.woocommerce-checkout');

        jQuery('#syspaySubmitBtn').click();
    } else {
        alert('An error occured: ' + response.message + '(Code: ' + response.error + ')');

        // User input unlocked.
        jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv, #place_order')
        .prop("disabled", false);

        return false;
    }

    // User input unlocked.
    jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv, #place_order')
        .prop("disabled", false);
};


function registerSyspayCheckoutButton(){

    // Date dynamic slash.
    jQuery('#syspay_expdate').bind('keyup','keydown', function(event) {
        var inputLength = event.target.value.length;
        if(event.key == 'Enter'){
            return false;
        }
        if (event.keyCode != 8){
            if(inputLength === 2){
                var thisVal = event.target.value;
                thisVal += '/';
                jQuery(event.target).val(thisVal);
            }
        }
      });

    jQuery('#place_order').click(function (e) {
        

        // Is Syspay the current selected payment method ?
        if( ! jQuery('#payment_method_syspay').is(':checked'))
            return true;
        
        // Inputs invalidity prevents submission.
        if( ! document.forms['checkout'].reportValidity())
            return false;

        e.preventDefault();
        e.stopPropagation();

        // Pre-submit validation additional to the html input validation (length, pattern).
        var dateExplode = jQuery("#syspay_expdate").val().split("/");
        if(
            dateExplode.length != 2 
            || dateExplode[0].length != 2 || dateExplode[1].length != 4
            || jQuery('#syspay_ccNo').val().length == 0 
            || jQuery('#syspay_owner').val().length == 0
            || jQuery('#syspay_cvv').val().length == 0){
            return false;
        }

        // User input locked during submission.
        jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv, #place_order')
            .prop("disabled", true);

        // Submit the card data to Syspay.
        syspay.tokenizer.tokenizeCard({
            number: jQuery('#syspay_ccNo').val(),
            cardholder: jQuery('#syspay_owner').val(),
            exp_month: dateExplode[0],
            exp_year: dateExplode[1],
            cvc: jQuery('#syspay_cvv').val()
        }, callback);

        // Prevent form submission.
        return false;
    });
}

// Checkout interaction.
jQuery(function () {

    // Form validation.
    jQuery('form.woocommerce-checkout').validate();

    // Catch form submissions and send the card data to syspay.
    registerSyspayCheckoutButton();

});