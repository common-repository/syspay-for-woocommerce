<?php
// Available variables :

// $syspay_public_key
// $baseurl
// global $syspayGlobalReturnStatus;
// global $syspayGlobalReturnError;
?>
<style>
    /* form.woocommerce-checkout label.error {
        color: red;
    } */
    #SyspayForm .show,
    .syspay-error-message.show,
    .syspay-success-message.show
    {display:block;}

    #SyspayForm{background-color: white;padding: 10px;}
        #SyspayForm h5 {
            color: #797979;
            padding: 7px 0 20px 0;
            text-transform: uppercase;
            font-size: 0.7em;
        }
        #SyspayForm span.required{color:red;}
        #SyspayForm .form-row {overflow: auto;}
            #SyspayForm .form-row > input {float: right;}
    
    #syspayFormSubmit{
        margin: 28px 0 29px 0;
        width: 100%;
    }

    #syspay-error-message, 
    .syspay-error-message{
        background-color: #ffd4db;
        padding: 10px;
        margin-top: 20px;
        display: none;
    }
        #syspay-error-message > h5,
        .syspay-error-message > h5
        {color: #ff4646;}

    #syspay-success-message, .syspay-success-message{
        background-color: #cdffcd;
        padding: 10px;
        margin-top: 20px;
        display: none;
    }
        #syspay-success-message > h5,
        , .syspay-success-message > h5
        {color: #2b6013;}

    #SyspayForm label.error{color:#ff4646; display: block; }
</style>

<script>
    window.SyspTokBaseUriConf = "<?php echo esc_attr($baseurl) ?>";
    window.SyspPubkConf = "<?php echo esc_attr($syspay_public_key) ?>";
</script>

<?php if(isset($syspayGlobalReturnStatus) && $syspayGlobalReturnStatus === true): ?>

    <!-- Payment has succeeded -->
    <div class="syspay-success-message show">
        <h5><?php echo __('Success', 'syspay') ?></h5>
        <div class="message first">
            <?php echo esc_attr(__('Your payment has been successfuly processed', 'syspay')) ?>
        </div>
    </div>
<?php elseif(isset($syspayGlobalReturnError) && is_string($syspayGlobalReturnError)): ?>

    <!-- Payment failed message -->
    <div class="syspay-error-message show">
        <h5><?php echo __('Error', 'syspay') ?></h5>
        <div class="message first">
            <?php echo esc_attr(__('Your payment has failed', 'syspay')) ?>
        </div>
    </div>
<?php else: ?>

<!-- Payment form widget -->

<div id="SyspayForm">

  <form id="SyspayFormPost" method="POST">
    <input type="hidden" name="syspay-single-form-page" value="single-page-form-mode"/>

    <h5><?php echo __('Payment details', 'syspay') ?></h5>

    <div class="form-row form-row-wide" style="margin-bottom: 15px;">
        <label for="syspay_name"><?php echo __('Name', 'syspay') ?> <span class="required">*</span></label>
        <input title="<?php echo esc_attr(__('Please enter your name', 'syspay')) ?>" 
            id="syspay_name" 
            type="text"
            name="syspay_name"
            onkeydown="return event.key != 'Enter';" 
            autocomplete="off" 
            minlength="2"
            required="required"
            >
    </div>
            <!-- value="Tesnam" -->

    <div class="form-row form-row-wide" style="margin-bottom: 15px;">
        <label for="syspay_surname"><?php echo __('Surname', 'syspay') ?> <span class="required">*</span></label>
        <input title="<?php echo esc_attr(__('Please enter your surname', 'syspay')) ?>" 
            id="syspay_surname" 
            type="text"
            name="syspay_surname"
            onkeydown="return event.key != 'Enter';" 
            autocomplete="off" 
            minlength="2"
            required="required"
            >
    </div>
            <!-- value="Tesurnam" -->

    <div class="form-row form-row-wide" style="margin-bottom: 15px;">
        <label for="syspay_email"><?php echo __('Email Address', 'syspay') ?> <span class="required">*</span></label>
        <input title="<?php echo esc_attr(__('Please enter your e-mail address', 'syspay')) ?>" 
            id="syspay_email" 
            name="syspay_email" 
            type="email"
            onkeydown="return event.key != 'Enter';" 
            autocomplete="off" 
            minlength="5"
            required="required"
            >
    </div>

    <div class="form-row form-row-wide" style="margin-bottom: 15px;">
        <label for="syspay_phone"><?php echo __('Phone (optional)', 'syspay') ?> </label>
        <input title="<?php echo esc_attr(__('Please enter your phone', 'syspay')) ?>" 
            id="syspay_phone" 
            name="syspay_phone" 
            type="text" 
            onkeydown="return event.key != 'Enter';" 
            autocomplete="off"
            >
    </div>

    <div class="form-row form-row-wide" style="margin-bottom: 15px;">
        <label for="syspay_description"><?php echo __('Description', 'syspay') ?> <span class="required">*</span></label>
        <input
            title="<?php echo esc_attr(__('Kindly insert reference to invoice, bill, quotation or RFP.', 'syspay')) ?>" 
            id="syspay_description"
            name="syspay_description"
            placeholder="<?php echo esc_attr(__('Kindly insert reference to invoice, bill, quotation or RFP.', 'syspay')) ?>" 
            type="text"
            onkeydown="return event.key != 'Enter';"
            autocomplete="off"
            minlength="2"
            required="required"
            >
    </div>
            <!-- value="Reference bill TEST"> -->

    <div class="form-row form-row-wide" style="margin-bottom: 15px;">
        <label for="syspay_name"><?php echo __('Amount to pay in Euros', 'syspay') ?> <span class="required">*</span></label>
        <input title="<?php echo esc_attr(__('Please enter the amount to pay', 'syspay')) ?>" 
            id="syspay_amount"
            name="syspay_amount"
            onkeydown="return event.key != 'Enter';" 
            autocomplete="off" 
            minlength="2"
            required="required"
            placeholder="€ 0.01"
            type="number"
            step="0.01"
            >
    </div>
            <!-- value="34" -->

    <h5><?php echo __('Credit card', 'syspay') ?></h5>

    <div class="form-row form-row-wide" style="margin-bottom: 15px;">
        <label for="syspay_ccNo"><?php echo __('Card Number', 'syspay') ?> <span class="required">*</span></label>
        <input title="<?php echo esc_attr(__('Please enter a valid credit card number', 'syspay')) ?>" 
            id="syspay_ccNo" 
            type="number"
            onkeydown="return event.key != 'Enter';" 
            autocomplete="off" 
            minlength="16" 
            size="19" 
            required="required"
        >
    </div>
    <div class="form-row form-row-first" style="margin-bottom: 15px;">
        <label for="syspay_expdate"><?php echo __('Expiry Date', 'syspay') ?> <span class="required">*</span></label>
        <input title="<?php echo esc_attr(__('Please enter the expiration date in format MM/YYYY', 'syspay')) ?>" 
            id="syspay_expdate" pattern="[0-9]+[0-9]+/[0-9][0-9][0-9][0-9]" 
            maxlength="7" 
            type="text" 
            autocomplete="off" required="required" minlength="7" size="7" 
            placeholder="MM / YYYY" 
            oninvalid="setCustomValidity('Date format: MM/YYYY.')" 
            oninput="setCustomValidity('')"
            >
            <!-- value="01/2022" -->
    </div>

    <div class="form-row form-row-last" style="margin-bottom: 15px;">
        <label for="syspay_cvv"><?php echo __('Card Code (CVC)', 'syspay') ?> <span class="required">*</span></label>
        <input title="<?php echo esc_attr(__('Please enter the security code', 'syspay')) ?>" 
            id="syspay_cvv" pattern="[0-9]+" type="password" 
            onkeydown="return event.key != 'Enter';" autocomplete="off" 
            minlength="3" size="3" required="required" placeholder="CVC" 
            maxlength="4" 
            oninvalid="setCustomValidity('3 digits security code required')" 
            oninput="setCustomValidity('')"
            >
    </div>
            <!-- value="399" -->
    <div class="form-row form-row-wide">
        <label for="syspay_owner"><?php echo __('Cardholder name', 'syspay') ?> <span class="required">*</span></label>
        <input id="syspay_owner" 
            title="<?php echo esc_attr(__('Please enter the name of the card owner', 'syspay')) ?>" 
            type="text" 
            onkeydown="return event.key != 'Enter';" 
            autocomplete="off" minlength="4" required="required"
            >
    </div>
            <!-- value="Tesnam Tesurnam"> -->
    <div class="clear" style="padding: 5px;"></div>

    <div id="syspay-error-message">
        <h5><?php echo __('Error', 'syspay') ?></h5>
        <div class="message first"></div>
        <div class="message second"></div>
    </div>

    <div id="syspay-success-message">
        <h5><?php echo __('Success', 'syspay') ?></h5>
        <div class="message first"></div>
        <div class="message second"></div>
    </div>

    <div class="form-row form-row-last">
        <input id="syspayFormSubmit" 
            type="submit" 
            value="<?php echo __('Pay', 'syspay') ?>" />
    </div>

    <div style="text-align: right; font-size: 0.7em;">Powered by Syspay Ltd</div>
  </form>
</div>


<?php endif ?>
