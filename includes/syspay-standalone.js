/**
 * The following function will be called back once the card data will 
 * have been submitted to the SysPay API.
 */

window.usedSyspayToken = '';

// Syspay token submission callback.
var callback = function (response) {

    // console.info('Syspay callback')
    // console.log(response);
    var jQuerytokenInput = jQuery('#SyspayForm input[name="syspay-token"]');

    if (null === response.error) {
        // The request was successfully processed.  
        // Add the returned token to the form before submitting it for real.
        
        // Emptying form.
        jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv').val('');

        if(jQuerytokenInput.size() > 0){
            jQuerytokenInput.remove();
        }
        
        jQuery('<input type="hidden" name="syspay-token" />')
            .val(response.token)
            .prependTo('#SyspayFormPost');

        // jQuery('#syspaySubmitBtn').click();
        let data = jQuery('#SyspayFormPost').serializeArray().reduce(function(obj, item) {
            obj[item.name] = item.value;
            return obj;
        }, {});

        jQuery.ajax({
            method: "POST",
            // url: "some.php",
            data: data,
            dataType: 'json',
            success: response => {
                if(typeof(response.error) !== 'undefined'){
                    showError(response.error);
                }else if(typeof(response.success) !== 'undefined'){
                    showSuccess(response.success);
                    jQuery('#SyspayForm .form-row, #SyspayForm h5').hide();
                }else if(typeof(response.redirect) !== 'undefined'){
                    window.location = response.redirect;
                }else{
                    showError('An unknown error occured');
                }
            },
            error: (jqxhr, error, exc) => {
               
                showError('Website error', error);

                // User input unlocked.
                jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv, #place_order')
                .prop("disabled", false);
            },
            complete: () => {
                // User input unlocked.
                jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv, #place_order')
                .prop("disabled", false);
            },
        });

    } else {
        let message = response.error + ' ' + response.message;

        var detail = '';
        if(typeof(response.api_message) !== 'undefined' 
            && typeof(response.api_code) !== 'undefined')
        {
            detail = response.api_message + ' (Code: ' + response.api_code + ')';
        }
    
        showError(message, detail);

        // User input unlocked.
        jQuery('#syspay_ccNo, #syspay_owner, #syspay_expdate, #syspay_cvv, #place_order')
            .prop("disabled", false);

        return false;
    }
};

function registerSyspayCheckoutButton(){

    jQuery('#syspayFormSubmit').click(function (e) {
        
        // Inputs invalidity prevents submission.
        if( ! document.forms['SyspayFormPost'].reportValidity())
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
        
        hideMessages();
        
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

function syspayDefer(method) {
    if (window.syspay) {
        if(typeof(window.SyspTokBaseUriConf) === 'undefined'
            || typeof(window.SyspPubkConf) === 'undefined'){
            showError('Syspay JS missing dependency');
            console.error('Syspay missing dependency');
        }
        syspay.tokenizer.setBaseUrl(window.SyspTokBaseUriConf);
        syspay.tokenizer.setPublicKey(window.SyspPubkConf);
        // console.info('Syspay JS initialized');
    } else {
        setTimeout(() => syspayDefer(method), 50);
    }
}

// Error message handling.
function showError(val, detail=''){
    jQuery('#syspay-error-message .message.first').text(val)
    jQuery('#syspay-error-message .message.second').text(detail)
    jQuery('#syspay-error-message').fadeIn();
}

function hideError(){
    jQuery('#syspay-error-message').fadeOut(300, () => {
        jQuery('#syspay-error-message .message').text('')
    });
}

function showSuccess(val, detail=''){
    jQuery('#syspay-success-message .message.first').text(val)
    jQuery('#syspay-success-message .message.second').text(detail)
    jQuery('#syspay-success-message').fadeIn();
}

function hideSuccess(){
    jQuery('#syspay-success-message').fadeOut(300, () => {
        jQuery('#syspay-success-message .message').text('')
    });
}

function hideMessages(){
    hideError();
    hideSuccess();
}

// // Checkout interaction.
jQuery(function () {
    // console.info('jquery initialized');
    syspayDefer();

    // Form validation.
    jQuery('#SyspayFormPost').validate();

    // Catch form submissions and send the card data to syspay.
    registerSyspayCheckoutButton();

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
});